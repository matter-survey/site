<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\DatabaseService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Data access for devices, versions, endpoints, stats, and faceted search.
 *
 * Queries are built with the DBAL {@see QueryBuilder} (`$this->db->createQueryBuilder()`)
 * rather than string concatenation. The repository targets database views
 * (`device_summary`, `cluster_stats`) and raw tables, so the DBAL builder is used
 * instead of ORM/DQL — DQL cannot express these views or the SQLite JSON functions
 * (`json_each`, `json_extract`) and `INTERSECT` the filters rely on. Fixed upsert
 * statements (`INSERT ... ON CONFLICT ... RETURNING`) stay as raw `executeStatement`
 * calls because the builder cannot represent them.
 *
 * SQLite-specific filter SQL lives in named private `*Fragment()` helpers. Each helper
 * takes the shared QueryBuilder, binds its own parameters, and returns the SQL fragment
 * string for `->andWhere(...)`.
 *
 * Parameter-naming convention (prevents collisions on the shared builder):
 *   - Each fragment helper / filter loop owns a short namespace prefix
 *     (`conn`, `dt`, `cap{i}_cl{j}`, `compat`, `cluster`) and names parameters
 *     `{$ns}_{$i}`. Distinct prefixes guarantee no two filters overwrite each other.
 *   - The emitted SQL of the two highest-risk fragments (capability `INTERSECT`,
 *     connectivity JSON-array `LIKE`) is pinned by golden-SQL tests, so those keep
 *     explicit, stable parameter names.
 */
class DeviceRepository
{
    private const int BINDING_CLUSTER_ID = 30; // 0x001E

    private const int GROUPS_CLUSTER_ID = 4; // 0x0004

    /**
     * Scenes is detected via either the current Scenes Management cluster (0x0062,
     * Matter 1.4+) or the deprecated Scenes cluster (0x0005) it replaced.
     *
     * @var list<int>
     */
    private const array SCENES_CLUSTER_IDS = [98, 5]; // 0x0062, 0x0005

    /**
     * Capability filter definitions mapping user-friendly keys to cluster requirements.
     * Each capability defines:
     * - label: Human-readable name for the UI
     * - clusters: Array of cluster IDs (any match = capability present for cluster-only checks)
     * - features: Array of feature codes that indicate the capability (optional).
     *
     * @var array<string, array{label: string, clusters: array<int>, features?: array<string>}>
     */
    public const CAPABILITY_FILTERS = [
        'dimming' => [
            'label' => 'Brightness dimming',
            'clusters' => [8], // Level Control
            'features' => [],
        ],
        'full_color' => [
            'label' => 'Full color (RGB)',
            'clusters' => [768], // Color Control
            'features' => ['HS', 'EHUE', 'XY'], // Any of these = full color
        ],
        'color_temperature' => [
            'label' => 'Color temperature',
            'clusters' => [768], // Color Control
            'features' => ['CT'],
        ],
        'motion_detection' => [
            'label' => 'Motion/Occupancy sensor',
            'clusters' => [1030], // Occupancy Sensing
            'features' => [],
        ],
        'temperature_sensing' => [
            'label' => 'Temperature sensor',
            'clusters' => [1026], // Temperature Measurement
            'features' => [],
        ],
        'energy_monitoring' => [
            'label' => 'Energy monitoring',
            'clusters' => [144, 145], // Electrical Power Measurement, Electrical Energy Measurement
            'features' => [],
        ],
        'battery_powered' => [
            'label' => 'Battery powered',
            'clusters' => [47], // Power Source
            'features' => ['BAT'],
        ],
        'window_covering' => [
            'label' => 'Blinds/Shades',
            'clusters' => [258], // Window Covering
            'features' => [],
        ],
    ];

    private readonly Connection $db;

    public function __construct(DatabaseService $databaseService)
    {
        $this->db = $databaseService->getConnection();
    }

    /**
     * @param bool $isNew Set to true if this was a new device (not an update)
     */
    public function upsertDevice(array $deviceData, bool &$isNew = false): int
    {
        // Check if device already exists
        $existing = $this->db->executeQuery(
            'SELECT id, connectivity_types FROM products WHERE vendor_id = :vendor_id AND product_id = :product_id',
            [
                'vendor_id' => $deviceData['vendor_id'],
                'product_id' => $deviceData['product_id'],
            ]
        )->fetchAssociative();

        $isNew = (false === $existing);

        // Merge connectivity types if we have new data
        $connectivityTypes = $deviceData['connectivity_types'] ?? [];
        if (!$isNew && !empty($connectivityTypes)) {
            $existingTypes = $existing['connectivity_types']
                ? json_decode((string) $existing['connectivity_types'], true) ?? []
                : [];
            $connectivityTypes = array_values(array_unique(array_merge($existingTypes, $connectivityTypes)));
            sort($connectivityTypes);
        }
        $connectivityTypesJson = empty($connectivityTypes) ? null : json_encode($connectivityTypes);

        // Generate slug for new products
        $slug = \App\Entity\Product::generateSlug(
            $deviceData['product_name'] ?? null,
            (int) $deviceData['vendor_id'],
            (int) $deviceData['product_id']
        );

        $result = $this->db->executeQuery('
            INSERT INTO products (vendor_id, vendor_name, vendor_fk, product_id, product_name, slug, connectivity_types, first_seen, last_seen, submission_count)
            VALUES (:vendor_id, :vendor_name, :vendor_fk, :product_id, :product_name, :slug, :connectivity_types, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(vendor_id, product_id) DO UPDATE SET
                -- DCL is normative: keep existing names, only use survey data as fallback
                vendor_name = COALESCE(products.vendor_name, excluded.vendor_name),
                vendor_fk = COALESCE(products.vendor_fk, excluded.vendor_fk),
                product_name = COALESCE(products.product_name, excluded.product_name),
                slug = COALESCE(products.slug, excluded.slug),
                connectivity_types = excluded.connectivity_types,
                -- Set first_seen only if not already set (for products imported from DCL fixtures)
                first_seen = COALESCE(products.first_seen, CURRENT_TIMESTAMP),
                last_seen = CURRENT_TIMESTAMP,
                submission_count = products.submission_count + 1
            RETURNING id
        ', [
            'vendor_id' => $deviceData['vendor_id'],
            'vendor_name' => $deviceData['vendor_name'],
            'vendor_fk' => $deviceData['vendor_fk'] ?? null,
            'product_id' => $deviceData['product_id'],
            'product_name' => $deviceData['product_name'],
            'slug' => $slug,
            'connectivity_types' => $connectivityTypesJson,
        ]);

        return (int) $result->fetchOne();
    }

    public function upsertVersion(int $deviceId, ?string $hardwareVersion, ?string $softwareVersion): void
    {
        $this->db->executeStatement('
            INSERT INTO product_versions (device_id, hardware_version, software_version, last_seen, count)
            VALUES (:device_id, :hardware_version, :software_version, CURRENT_TIMESTAMP, 1)
            ON CONFLICT(device_id, hardware_version, software_version) DO UPDATE SET
                last_seen = CURRENT_TIMESTAMP,
                count = product_versions.count + 1
        ', [
            'device_id' => $deviceId,
            'hardware_version' => $hardwareVersion,
            'software_version' => $softwareVersion,
        ]);
    }

    public function upsertEndpoint(int $deviceId, array $endpointData, ?string $hardwareVersion = null, ?string $softwareVersion = null): void
    {
        $serverClusterDetails = $endpointData['server_cluster_details'] ?? null;
        $clientClusterDetails = $endpointData['client_cluster_details'] ?? null;
        $schemaVersion = $endpointData['schema_version'] ?? 2;

        $this->db->executeStatement('
            INSERT INTO product_endpoints (
                device_id, endpoint_id, hardware_version, software_version,
                device_types, server_clusters, client_clusters,
                server_cluster_details, client_cluster_details, schema_version
            )
            VALUES (
                :device_id, :endpoint_id, :hardware_version, :software_version,
                :device_types, :server_clusters, :client_clusters,
                :server_cluster_details, :client_cluster_details, :schema_version
            )
            ON CONFLICT(device_id, endpoint_id, hardware_version, software_version) DO UPDATE SET
                device_types = excluded.device_types,
                server_clusters = excluded.server_clusters,
                client_clusters = excluded.client_clusters,
                server_cluster_details = COALESCE(excluded.server_cluster_details, product_endpoints.server_cluster_details),
                client_cluster_details = COALESCE(excluded.client_cluster_details, product_endpoints.client_cluster_details),
                schema_version = MAX(excluded.schema_version, COALESCE(product_endpoints.schema_version, 2)),
                last_seen = CURRENT_TIMESTAMP,
                submission_count = product_endpoints.submission_count + 1
        ', [
            'device_id' => $deviceId,
            'endpoint_id' => $endpointData['endpoint_id'],
            'hardware_version' => $hardwareVersion,
            'software_version' => $softwareVersion,
            'device_types' => json_encode($endpointData['device_types'] ?? []),
            'server_clusters' => json_encode($endpointData['server_clusters'] ?? []),
            'client_clusters' => json_encode($endpointData['client_clusters'] ?? []),
            'server_cluster_details' => null !== $serverClusterDetails ? json_encode($serverClusterDetails) : null,
            'client_cluster_details' => null !== $clientClusterDetails ? json_encode($clientClusterDetails) : null,
            'schema_version' => $schemaVersion,
        ]);
    }

    public function getAllDevices(int $limit = 100, int $offset = 0): array
    {
        return $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->orderBy('submission_count', 'DESC')
            ->addOrderBy('last_seen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function getDeviceCount(): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('products')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get devices with optional filters.
     *
     * @param array{
     *     connectivity?: array<string>,
     *     binding?: bool|string|null,
     *     groups?: bool|string|null,
     *     scenes?: bool|string|null,
     *     vendor?: int|null,
     *     search?: string|null,
     *     device_types?: array<int>,
     *     min_rating?: int,
     *     compatible_with?: array<int>,
     *     capabilities?: array<string>
     * } $filters
     */
    public function getFilteredDevices(array $filters, int $limit = 100, int $offset = 0): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->orderBy('submission_count', 'DESC')
            ->addOrderBy('last_seen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        $this->applyFilters($qb, $filters);

        return $this->attachNameAmbiguity(
            $qb->executeQuery()->fetchAllAssociative()
        );
    }

    /**
     * Get count of devices with optional filters.
     *
     * @param array{
     *     connectivity?: array<string>,
     *     binding?: bool|string|null,
     *     groups?: bool|string|null,
     *     scenes?: bool|string|null,
     *     vendor?: int|null,
     *     search?: string|null,
     *     device_types?: array<int>,
     *     min_rating?: int,
     *     compatible_with?: array<int>,
     *     capabilities?: array<string>
     * } $filters
     */
    public function getFilteredDeviceCount(array $filters): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('device_summary');

        $this->applyFilters($qb, $filters);

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Apply the optional device filters to a `device_summary` query builder.
     *
     * Scalar predicates are added inline via `andWhere()`; SQLite-specific subqueries
     * (JSON-array LIKE, json_each device-type matching, capability INTERSECT) are
     * delegated to named `*Fragment()` helpers that bind their own parameters.
     *
     * @param array<string, mixed> $filters
     */
    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        // Connectivity filter (JSON-array LIKE, OR over the requested types)
        if (!empty($filters['connectivity'])) {
            $qb->andWhere($this->connectivityLikeFragment($qb, $filters['connectivity']));
        }

        // Coordination filters (binding, groups, scenes)
        foreach (['binding', 'groups', 'scenes'] as $coordFeature) {
            if (isset($filters[$coordFeature])) {
                $qb->andWhere("supports_{$coordFeature} = :{$coordFeature}")
                    ->setParameter($coordFeature, $filters[$coordFeature] ? 1 : 0, ParameterType::INTEGER);
            }
        }

        // Vendor filter
        if (!empty($filters['vendor'])) {
            $qb->andWhere('vendor_fk = :vendor')
                ->setParameter('vendor', $filters['vendor'], ParameterType::INTEGER);
        }

        // Device types filter (array of IDs)
        if (!empty($filters['device_types'])) {
            $qb->andWhere($this->deviceTypeSubqueryFragment($qb, $filters['device_types'], 'id', 'device_type'));
        }

        // Search filter
        if (!empty($filters['search'])) {
            $qb->andWhere(
                '(vendor_name LIKE :search OR product_name LIKE :search'
                .' OR CAST(vendor_id AS TEXT) LIKE :search OR CAST(product_id AS TEXT) LIKE :search)'
            )->setParameter('search', '%'.$filters['search'].'%');
        }

        // Minimum star rating filter
        if (!empty($filters['min_rating'])) {
            $qb->andWhere('id IN (SELECT device_id FROM device_scores WHERE star_rating >= :min_rating)')
                ->setParameter('min_rating', $filters['min_rating'], ParameterType::INTEGER);
        }

        // Compatible with owned devices filter
        if (!empty($filters['compatible_with'])) {
            $qb->andWhere($this->compatibleWithFragment($qb, $filters['compatible_with']));
        }

        // Capability filters: device must have ALL selected capabilities (AND logic)
        if (!empty($filters['capabilities'])) {
            $fragment = $this->capabilityIntersectFragment($qb, $filters['capabilities']);
            if (null !== $fragment) {
                $qb->andWhere($fragment);
            }
        }
    }

    /**
     * Build the connectivity JSON-array `LIKE` predicate (OR over the requested types).
     *
     * A connectivity type is stored as a JSON-array element and matched via
     * `LIKE '%"type"%'`. The emitted SQL is pinned by a golden-SQL test — keep the
     * explicit `conn_{i}` parameter names.
     *
     * @param array<int, string> $connectivityTypes
     */
    private function connectivityLikeFragment(QueryBuilder $qb, array $connectivityTypes): string
    {
        $conditions = [];
        foreach (array_values($connectivityTypes) as $i => $type) {
            $name = 'conn_'.$i;
            $conditions[] = "connectivity_types LIKE :{$name}";
            $qb->setParameter($name, '%"'.$type.'"%');
        }

        return '('.implode(' OR ', $conditions).')';
    }

    /**
     * Build a `{$column} IN (...)` subquery matching devices whose endpoints declare one
     * of the given Matter device-type ids (via json_each / json_extract over device_types).
     *
     * @param array<int, int> $deviceTypeIds
     * @param string          $column        Outer column to constrain (e.g. `id`, `pe.device_id`)
     * @param string          $ns            Parameter-name namespace prefix
     */
    private function deviceTypeSubqueryFragment(QueryBuilder $qb, array $deviceTypeIds, string $column, string $ns): string
    {
        $conditions = [];
        foreach (array_values($deviceTypeIds) as $i => $typeId) {
            $name = $ns.'_'.$i;
            $conditions[] = 'json_extract(value, "$.id") = :'.$name;
            $qb->setParameter($name, $typeId, ParameterType::INTEGER);
        }

        return $column.' IN (
                SELECT DISTINCT dtpe.device_id
                FROM product_endpoints dtpe
                WHERE EXISTS (
                    SELECT 1 FROM json_each(dtpe.device_types)
                    WHERE '.implode(' OR ', $conditions).'
                )
            )';
    }

    /**
     * Build the `id IN (... INTERSECT ...)` fragment requiring a device to expose ALL
     * selected capabilities (one server-cluster presence subquery per capability,
     * combined with INTERSECT). Returns null when no selected key maps to a known
     * capability, so the caller can skip the predicate entirely.
     *
     * The emitted SQL is pinned by a golden-SQL test — keep the explicit
     * `cap{i}_cl{j}` parameter names.
     *
     * @param array<int, string> $capabilityKeys
     */
    private function capabilityIntersectFragment(QueryBuilder $qb, array $capabilityKeys): ?string
    {
        $subqueries = [];
        foreach (array_values($capabilityKeys) as $i => $capKey) {
            if (!isset(self::CAPABILITY_FILTERS[$capKey])) {
                continue;
            }

            $clusterPlaceholders = [];
            foreach (self::CAPABILITY_FILTERS[$capKey]['clusters'] as $ci => $clusterId) {
                $name = "cap{$i}_cl{$ci}";
                $clusterPlaceholders[] = ':'.$name;
                $qb->setParameter($name, $clusterId, ParameterType::INTEGER);
            }

            $subqueries[] = '
                    SELECT DISTINCT pe.device_id
                    FROM product_endpoints pe
                    WHERE EXISTS (
                        SELECT 1 FROM json_each(pe.server_clusters)
                        WHERE value IN ('.implode(', ', $clusterPlaceholders).')
                    )
                ';
        }

        if ([] === $subqueries) {
            return null;
        }

        return 'id IN ('.implode(' INTERSECT ', $subqueries).')';
    }

    /**
     * Build the `id IN (...)` fragment restricting results to products compatible with the
     * given owned device ids (co-occurrence in installations). Returns `1 = 0` when no
     * compatible devices exist, so the query yields no rows.
     *
     * @param array<int, int> $ownedDeviceIds
     */
    private function compatibleWithFragment(QueryBuilder $qb, array $ownedDeviceIds): string
    {
        $compatibleIds = $this->findCompatibleDevices($ownedDeviceIds);
        if ([] === $compatibleIds) {
            return '1 = 0';
        }

        $placeholders = [];
        foreach (array_values($compatibleIds) as $i => $id) {
            $name = 'compat_'.$i;
            $placeholders[] = ':'.$name;
            $qb->setParameter($name, $id, ParameterType::INTEGER);
        }

        return 'id IN ('.implode(', ', $placeholders).')';
    }

    /**
     * Find products that are compatible with the given owned device IDs.
     * Compatibility is determined by co-occurrence in installations.
     *
     * @param array<int> $ownedDeviceIds Device IDs the user owns
     * @param int        $limit          Maximum number of compatible devices to return
     *
     * @return array<int> Product IDs that are compatible
     */
    private function findCompatibleDevices(array $ownedDeviceIds, int $limit = 200): array
    {
        if ([] === $ownedDeviceIds) {
            return [];
        }

        // Build placeholders for the owned-device IN clause (used twice, with/without NOT)
        $placeholders = [];
        $qb = $this->db->createQueryBuilder();
        foreach (array_values($ownedDeviceIds) as $i => $id) {
            $name = 'owned_'.$i;
            $placeholders[] = ':'.$name;
            $qb->setParameter($name, $id, ParameterType::INTEGER);
        }
        $inClause = implode(', ', $placeholders);

        // Find products that frequently appear in the same installations as owned products
        $results = $qb
            ->select('ip2.product_id', 'COUNT(DISTINCT ip1.installation_id) as shared_count')
            ->from('installation_products', 'ip1')
            ->join('ip1', 'installation_products', 'ip2', 'ip1.installation_id = ip2.installation_id')
            ->where('ip1.product_id IN ('.$inClause.')')
            ->andWhere('ip2.product_id NOT IN ('.$inClause.')')
            ->groupBy('ip2.product_id')
            ->having('shared_count >= 1')
            ->orderBy('shared_count', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): int => (int) $row['product_id'], $results);
    }

    /**
     * Get connectivity type facets (counts per type).
     *
     * @return array<string, int>
     */
    public function getConnectivityFacets(): array
    {
        $result = $this->db->createQueryBuilder()
            ->select(
                "SUM(CASE WHEN connectivity_types LIKE '%\"thread\"%' THEN 1 ELSE 0 END) as thread",
                "SUM(CASE WHEN connectivity_types LIKE '%\"wifi\"%' THEN 1 ELSE 0 END) as wifi",
                "SUM(CASE WHEN connectivity_types LIKE '%\"ethernet\"%' THEN 1 ELSE 0 END) as ethernet",
            )
            ->from('products')
            ->executeQuery()
            ->fetchAssociative();

        return [
            'thread' => (int) ($result['thread'] ?? 0),
            'wifi' => (int) ($result['wifi'] ?? 0),
            'ethernet' => (int) ($result['ethernet'] ?? 0),
        ];
    }

    /**
     * Get coordination-feature support facets (binding, groups, scenes).
     *
     * @return array{
     *     binding: array{with: int, without: int},
     *     groups: array{with: int, without: int},
     *     scenes: array{with: int, without: int}
     * }
     */
    public function getCoordinationFacets(): array
    {
        $result = $this->db->createQueryBuilder()
            ->select(
                'SUM(CASE WHEN supports_binding = 1 THEN 1 ELSE 0 END) as with_binding',
                'SUM(CASE WHEN supports_binding = 0 THEN 1 ELSE 0 END) as without_binding',
                'SUM(CASE WHEN supports_groups = 1 THEN 1 ELSE 0 END) as with_groups',
                'SUM(CASE WHEN supports_groups = 0 THEN 1 ELSE 0 END) as without_groups',
                'SUM(CASE WHEN supports_scenes = 1 THEN 1 ELSE 0 END) as with_scenes',
                'SUM(CASE WHEN supports_scenes = 0 THEN 1 ELSE 0 END) as without_scenes',
            )
            ->from('device_summary')
            ->executeQuery()
            ->fetchAssociative();

        return [
            'binding' => [
                'with' => (int) ($result['with_binding'] ?? 0),
                'without' => (int) ($result['without_binding'] ?? 0),
            ],
            'groups' => [
                'with' => (int) ($result['with_groups'] ?? 0),
                'without' => (int) ($result['without_groups'] ?? 0),
            ],
            'scenes' => [
                'with' => (int) ($result['with_scenes'] ?? 0),
                'without' => (int) ($result['without_scenes'] ?? 0),
            ],
        ];
    }

    /**
     * Get star rating facets (count of devices per star rating).
     *
     * @return array<int, int> Rating (1-5) => count
     */
    public function getStarRatingFacets(): array
    {
        $results = $this->db->createQueryBuilder()
            ->select('star_rating', 'COUNT(*) as count')
            ->from('device_scores')
            ->groupBy('star_rating')
            ->orderBy('star_rating', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $facets = [];
        foreach ($results as $row) {
            $facets[(int) $row['star_rating']] = (int) $row['count'];
        }

        // Ensure all ratings 1-5 are represented
        for ($i = 5; $i >= 1; --$i) {
            if (!isset($facets[$i])) {
                $facets[$i] = 0;
            }
        }

        krsort($facets);

        return $facets;
    }

    /**
     * Get capability facets with counts for faceted search.
     * Counts devices that have the clusters (and optionally features) for each capability.
     *
     * For capabilities without feature requirements, a simple cluster presence check is used.
     * For capabilities with features, we check V3 telemetry when available, with V2 fallback.
     *
     * When $deviceTypeIds is provided, counts are scoped to devices that expose one of
     * those device types — this lets the wizard show only the capabilities that are
     * actually relevant to a chosen category.
     *
     * @param array<int>|null $deviceTypeIds Optional device-type ids to scope counts to
     *
     * @return array<array{key: string, label: string, count: int}>
     */
    public function getCapabilityFacets(?array $deviceTypeIds = null): array
    {
        $facets = [];

        foreach (self::CAPABILITY_FILTERS as $key => $config) {
            $count = $this->countDevicesWithCapability($config['clusters'], $deviceTypeIds);
            $facets[] = [
                'key' => $key,
                'label' => $config['label'],
                'count' => $count,
            ];
        }

        // Sort by count descending
        usort($facets, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $facets;
    }

    /**
     * Count devices that have the specified clusters (and optionally features).
     *
     * @param array<int>      $clusters      Cluster IDs (any match counts)
     * @param array<int>|null $deviceTypeIds Optional device-type ids to scope the count to
     */
    private function countDevicesWithCapability(array $clusters, ?array $deviceTypeIds = null): int
    {
        if ([] === $clusters) {
            return 0;
        }

        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(DISTINCT pe.device_id)')
            ->from('product_endpoints', 'pe');

        // Cluster presence check (works for all data)
        $clusterPlaceholders = [];
        foreach (array_values($clusters) as $i => $clusterId) {
            $name = 'cluster_'.$i;
            $clusterPlaceholders[] = ':'.$name;
            $qb->setParameter($name, $clusterId, ParameterType::INTEGER);
        }
        $qb->andWhere('EXISTS (
                SELECT 1 FROM json_each(pe.server_clusters)
                WHERE value IN ('.implode(', ', $clusterPlaceholders).')
            )');

        // Optionally constrain to devices that expose one of the given device types.
        if (null !== $deviceTypeIds && [] !== $deviceTypeIds) {
            $qb->andWhere($this->deviceTypeSubqueryFragment($qb, $deviceTypeIds, 'pe.device_id', 'dt'));
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Get top vendors with device counts for faceted search.
     *
     * @return array<array{id: int, name: string, slug: string, count: int}>
     */
    public function getVendorFacets(int $limit = 20): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('v.id', 'v.name', 'v.slug', 'COUNT(p.id) as count')
            ->from('vendors', 'v')
            ->join('v', 'products', 'p', 'p.vendor_fk = v.id')
            ->groupBy('v.id')
            ->having('count > 0')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    /**
     * Get device type facets with counts for faceted search.
     * Uses the device_types table for names and joins with product_endpoints.
     *
     * @return array<array{id: int, name: string, count: int}>
     */
    public function getDeviceTypeFacets(int $limit = 15): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('dt.id', 'dt.name', 'COUNT(DISTINCT pe.device_id) as count')
            ->from('device_types', 'dt')
            ->join('dt', 'product_endpoints', 'pe', 'EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = dt.id
            )')
            ->groupBy('dt.id')
            ->having('count > 0')
            ->orderBy('count', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'count' => (int) $row['count'],
        ], $rows);
    }

    public function getDevice(int $id): ?array
    {
        $result = $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->where('id = :id')
            ->setParameter('id', $id, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->attachNameAmbiguity([$result])[0];
    }

    public function getDeviceBySlug(string $slug): ?array
    {
        $result = $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->where('slug = :slug')
            ->setParameter('slug', $slug)
            ->executeQuery()
            ->fetchAssociative();

        if (!$result) {
            return null;
        }

        return $this->attachNameAmbiguity([$result])[0];
    }

    /**
     * Attach an `is_name_ambiguous` flag to each row, true when another product
     * exists with the same vendor and product_name. Used by the UI to render a
     * disambiguator (PID) next to otherwise identical device names.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private function attachNameAmbiguity(array $rows): array
    {
        if ([] === $rows) {
            return $rows;
        }

        $pairs = [];
        foreach ($rows as $row) {
            $vendorFk = $row['vendor_fk'] ?? null;
            $name = $row['product_name'] ?? null;
            if (null === $vendorFk) {
                continue;
            }
            if (null === $name) {
                continue;
            }
            if ('' === $name) {
                continue;
            }
            $pairs[$vendorFk.':'.$name] = [(int) $vendorFk, (string) $name];
        }

        $duplicates = [];
        if ([] !== $pairs) {
            $qb = $this->db->createQueryBuilder();
            $conditions = [];
            foreach (array_values($pairs) as $i => [$fk, $name]) {
                $conditions[] = "(vendor_fk = :nfk_$i AND product_name = :nname_$i)";
                $qb->setParameter('nfk_'.$i, $fk, ParameterType::INTEGER);
                $qb->setParameter('nname_'.$i, $name);
            }
            $dupRows = $qb
                ->select('vendor_fk', 'product_name')
                ->from('products')
                ->where('('.implode(' OR ', $conditions).')')
                ->andWhere("product_name IS NOT NULL AND product_name != ''")
                ->groupBy('vendor_fk', 'product_name')
                ->having('COUNT(*) > 1')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($dupRows as $dup) {
                $duplicates[$dup['vendor_fk'].':'.$dup['product_name']] = true;
            }
        }

        foreach ($rows as &$row) {
            $key = ($row['vendor_fk'] ?? '').':'.($row['product_name'] ?? '');
            $row['is_name_ambiguous'] = isset($duplicates[$key]);
        }
        unset($row);

        return $rows;
    }

    /**
     * Get all endpoints for a device, grouped by version.
     * Returns endpoints with version info, ordered by version then endpoint_id.
     */
    public function getDeviceEndpoints(int $deviceId): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select(
                'endpoint_id', 'hardware_version', 'software_version', 'device_types', 'server_clusters', 'client_clusters',
                'server_cluster_details', 'client_cluster_details', 'schema_version',
                'first_seen', 'last_seen', 'submission_count',
            )
            ->from('product_endpoints')
            ->where('device_id = :device_id')
            ->setParameter('device_id', $deviceId, ParameterType::INTEGER)
            ->orderBy('software_version', 'DESC')
            ->addOrderBy('hardware_version', 'DESC')
            ->addOrderBy('endpoint_id')
            ->executeQuery()
            ->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode((string) $row['device_types'], true) ?? [];
            $row['server_clusters'] = json_decode((string) $row['server_clusters'], true) ?? [];
            $row['client_clusters'] = json_decode((string) $row['client_clusters'], true) ?? [];
            $row['server_cluster_details'] = $row['server_cluster_details'] ? json_decode((string) $row['server_cluster_details'], true) : null;
            $row['client_cluster_details'] = $row['client_cluster_details'] ? json_decode((string) $row['client_cluster_details'], true) : null;
            $row['schema_version'] = (int) ($row['schema_version'] ?? 2);
            // Derive coordination-feature support from the endpoint clusters
            $row['has_binding_cluster'] = \in_array(self::BINDING_CLUSTER_ID, $row['server_clusters'], true)
                || \in_array(self::BINDING_CLUSTER_ID, $row['client_clusters'], true);
            $row['has_groups_cluster'] = \in_array(self::GROUPS_CLUSTER_ID, $row['server_clusters'], true);
            $row['has_scenes_cluster'] = [] !== array_intersect(self::SCENES_CLUSTER_IDS, $row['server_clusters']);
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    /**
     * Get endpoints for a specific version of a device.
     */
    public function getDeviceEndpointsByVersion(int $deviceId, ?string $hardwareVersion, ?string $softwareVersion): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select(
                'endpoint_id', 'hardware_version', 'software_version', 'device_types', 'server_clusters', 'client_clusters',
                'server_cluster_details', 'client_cluster_details', 'first_seen', 'last_seen', 'submission_count',
            )
            ->from('product_endpoints')
            ->where('device_id = :device_id')
            ->andWhere('(hardware_version = :hardware_version OR (hardware_version IS NULL AND :hardware_version IS NULL))')
            ->andWhere('(software_version = :software_version OR (software_version IS NULL AND :software_version IS NULL))')
            ->orderBy('endpoint_id')
            ->setParameter('device_id', $deviceId, ParameterType::INTEGER)
            ->setParameter('hardware_version', $hardwareVersion)
            ->setParameter('software_version', $softwareVersion)
            ->executeQuery()
            ->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode((string) $row['device_types'], true) ?? [];
            $row['server_clusters'] = json_decode((string) $row['server_clusters'], true) ?? [];
            $row['client_clusters'] = json_decode((string) $row['client_clusters'], true) ?? [];
            $row['server_cluster_details'] = json_decode($row['server_cluster_details'] ?? 'null', true);
            $row['client_cluster_details'] = json_decode($row['client_cluster_details'] ?? 'null', true);
            $row['has_binding_cluster'] = \in_array(self::BINDING_CLUSTER_ID, $row['server_clusters'], true)
                || \in_array(self::BINDING_CLUSTER_ID, $row['client_clusters'], true);
            $row['has_groups_cluster'] = \in_array(self::GROUPS_CLUSTER_ID, $row['server_clusters'], true);
            $row['has_scenes_cluster'] = [] !== array_intersect(self::SCENES_CLUSTER_IDS, $row['server_clusters']);
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    /**
     * Get unique versions that have endpoint data for a device.
     */
    public function getDeviceEndpointVersions(int $deviceId): array
    {
        return $this->db->createQueryBuilder()
            ->select(
                'DISTINCT hardware_version', 'software_version',
                'MIN(first_seen) as first_seen', 'MAX(last_seen) as last_seen',
                'SUM(submission_count) as total_submissions',
            )
            ->from('product_endpoints')
            ->where('device_id = :device_id')
            ->setParameter('device_id', $deviceId, ParameterType::INTEGER)
            ->groupBy('hardware_version', 'software_version')
            ->orderBy('software_version', 'DESC')
            ->addOrderBy('hardware_version', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function getDeviceVersions(int $deviceId): array
    {
        return $this->db->createQueryBuilder()
            ->select('hardware_version', 'software_version', 'count', 'first_seen', 'last_seen')
            ->from('product_versions')
            ->where('device_id = :device_id')
            ->setParameter('device_id', $deviceId, ParameterType::INTEGER)
            ->orderBy('count', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function searchDevices(string $query, int $limit = 50): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->where('vendor_name LIKE :query')
            ->orWhere('product_name LIKE :query')
            ->orWhere('CAST(vendor_id AS TEXT) LIKE :query')
            ->orWhere('CAST(product_id AS TEXT) LIKE :query')
            ->orderBy('submission_count', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('query', "%$query%")
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->attachNameAmbiguity($rows);
    }

    /**
     * Get devices by vendor FK.
     */
    public function getDevicesByVendor(int $vendorFk, int $limit = 100, int $offset = 0): array
    {
        $rows = $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->where('vendor_fk = :vendor_fk')
            ->setParameter('vendor_fk', $vendorFk, ParameterType::INTEGER)
            ->orderBy('submission_count', 'DESC')
            ->addOrderBy('last_seen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();

        return $this->attachNameAmbiguity($rows);
    }

    /**
     * Count devices by vendor FK.
     */
    public function getDeviceCountByVendor(int $vendorFk): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('products')
            ->where('vendor_fk = :vendor_fk')
            ->setParameter('vendor_fk', $vendorFk, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get cluster statistics from the cluster_stats view.
     * Returns cluster_id, cluster_type (server/client), and product_count.
     */
    public function getClusterStats(): array
    {
        return $this->db->executeQuery('
            SELECT cluster_id, cluster_type, product_count
            FROM cluster_stats
            ORDER BY product_count DESC
        ')->fetchAllAssociative();
    }

    /**
     * Get device type distribution across all products.
     * Device types are stored as JSON arrays of objects with 'id' and 'revision' fields.
     */
    public function getDeviceTypeStats(): array
    {
        return $this->db->executeQuery('
            SELECT
                json_extract(json_each.value, "$.id") as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY json_extract(json_each.value, "$.id")
            ORDER BY product_count DESC
        ')->fetchAllAssociative();
    }

    /**
     * Get devices that implement a specific device type.
     *
     * @param string $sort Sort order: 'name' for alphabetical, 'recent' for most recent
     */
    public function getDevicesByDeviceType(int $deviceTypeId, int $limit = 50, int $offset = 0, string $sort = 'recent'): array
    {
        $orderBy = match ($sort) {
            'name' => 'ds.product_name ASC, ds.vendor_name ASC',
            default => 'ds.submission_count DESC, ds.last_seen DESC',
        };

        return $this->db->createQueryBuilder()
            ->select('DISTINCT ds.*')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )')
            ->orderBy($orderBy)
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->setParameter('device_type_id', $deviceTypeId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Count devices that implement a specific device type.
     */
    public function countDevicesByDeviceType(int $deviceTypeId): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(DISTINCT pe.device_id)')
            ->from('product_endpoints', 'pe')
            ->where('EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )')
            ->setParameter('device_type_id', $deviceTypeId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Get distribution by display category.
     */
    public function getCategoryDistribution(\App\Service\MatterRegistry $registry): array
    {
        $deviceTypeStats = $this->getDeviceTypeStats();
        $categoryStats = [];

        foreach ($deviceTypeStats as $dt) {
            $metadata = $registry->getDeviceTypeMetadata((int) $dt['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'Unknown';

            if (!isset($categoryStats[$displayCategory])) {
                $categoryStats[$displayCategory] = 0;
            }
            $categoryStats[$displayCategory] += (int) $dt['product_count'];
        }

        arsort($categoryStats);

        return $categoryStats;
    }

    /**
     * Get distribution by Matter spec version.
     */
    public function getSpecVersionDistribution(\App\Service\MatterRegistry $registry): array
    {
        $deviceTypeStats = $this->getDeviceTypeStats();
        $versionStats = [];

        foreach ($deviceTypeStats as $dt) {
            $metadata = $registry->getDeviceTypeMetadata((int) $dt['device_type_id']);
            $specVersion = $metadata['specVersion'] ?? 'Unknown';

            if (!isset($versionStats[$specVersion])) {
                $versionStats[$specVersion] = 0;
            }
            $versionStats[$specVersion] += (int) $dt['product_count'];
        }

        uksort($versionStats, static fn (string $a, string $b): int => version_compare($a, $b));

        return $versionStats;
    }

    /**
     * Get top vendors by device count.
     */
    public function getTopVendors(int $limit = 10): array
    {
        $qb = $this->db->createQueryBuilder();
        $placeholders = [];
        foreach (\App\Entity\Vendor::TEST_VENDOR_IDS as $i => $vendorId) {
            $name = 'test_vendor_'.$i;
            $placeholders[] = ':'.$name;
            $qb->setParameter($name, $vendorId, ParameterType::INTEGER);
        }

        return $qb
            ->select('v.id', 'v.name', 'v.slug', 'v.spec_id', 'v.device_count')
            ->from('vendors', 'v')
            ->where('v.device_count > 0')
            ->andWhere('(v.spec_id IS NULL OR v.spec_id NOT IN ('.implode(', ', $placeholders).'))')
            ->orderBy('v.device_count', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get most recently discovered devices.
     */
    public function getRecentDevices(int $limit = 5): array
    {
        return $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->orderBy('first_seen', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get cluster co-occurrence (which server clusters appear together).
     */
    public function getClusterCoOccurrence(int $limit = 15): array
    {
        return $this->db->executeQuery('
            SELECT
                c1.value as cluster_a,
                c2.value as cluster_b,
                COUNT(DISTINCT pe.device_id) as co_occurrence_count
            FROM product_endpoints pe,
                 json_each(pe.server_clusters) c1,
                 json_each(pe.server_clusters) c2
            WHERE c1.value < c2.value
            GROUP BY c1.value, c2.value
            ORDER BY co_occurrence_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get devices that support a given coordination feature.
     *
     * @param string $feature one of 'binding', 'groups', or 'scenes'
     *
     * @throws \InvalidArgumentException when $feature is not a known coordination feature
     */
    public function getCoordinationCapableDevices(string $feature, int $limit = 50): array
    {
        if (!\in_array($feature, ['binding', 'groups', 'scenes'], true)) {
            throw new \InvalidArgumentException("Unknown coordination feature: {$feature}");
        }

        return $this->db->createQueryBuilder()
            ->select('*')
            ->from('device_summary')
            ->where("supports_{$feature} = 1")
            ->orderBy('submission_count', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get coordination support breakdown by category for binding, groups, and scenes.
     * Device types are stored as JSON arrays of objects with 'id' and 'revision' fields.
     *
     * @return array<string, array{
     *     total: int,
     *     binding: int, groups: int, scenes: int,
     *     binding_pct: float, groups_pct: float, scenes_pct: float
     * }>
     */
    public function getCoordinationByCategory(\App\Service\MatterRegistry $registry): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                pe.device_id,
                json_extract(json_each.value, "$.id") as device_type_id,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 30
                ) OR EXISTS (
                    SELECT 1 FROM json_each(pe.client_clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as has_binding,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 4
                ) THEN 1 ELSE 0 END) as has_groups,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value IN (98, 5)
                ) THEN 1 ELSE 0 END) as has_scenes
            FROM product_endpoints pe, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY pe.device_id, json_extract(json_each.value, "$.id")
        ')->fetchAllAssociative();

        $categoryStats = [];
        foreach ($rows as $row) {
            $metadata = $registry->getDeviceTypeMetadata((int) $row['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'Unknown';

            if (!isset($categoryStats[$displayCategory])) {
                $categoryStats[$displayCategory] = ['total' => 0, 'binding' => 0, 'groups' => 0, 'scenes' => 0];
            }
            ++$categoryStats[$displayCategory]['total'];
            foreach (['binding', 'groups', 'scenes'] as $feature) {
                if ($row['has_'.$feature]) {
                    ++$categoryStats[$displayCategory][$feature];
                }
            }
        }

        // Calculate percentages (total is always >= 1 since we only create entries when there's a row)
        foreach ($categoryStats as &$stat) {
            foreach (['binding', 'groups', 'scenes'] as $feature) {
                $stat[$feature.'_pct'] = round(($stat[$feature] / $stat['total']) * 100, 1);
            }
        }
        unset($stat);

        // Sort by overall coordination prevalence (binding leads, consistent with prior behavior)
        uasort($categoryStats, fn ($a, $b): int => $b['binding_pct'] <=> $a['binding_pct']);

        return $categoryStats;
    }

    /**
     * Get products with multiple software/hardware versions (indicating OTA activity).
     */
    public function getProductsWithMultipleVersions(int $limit = 30): array
    {
        return $this->db->executeQuery('
            SELECT
                p.id,
                p.vendor_name,
                p.product_name,
                v.slug as vendor_slug,
                COUNT(DISTINCT pv.software_version) as software_version_count,
                COUNT(DISTINCT pv.hardware_version) as hardware_version_count,
                GROUP_CONCAT(DISTINCT pv.software_version) as software_versions
            FROM products p
            LEFT JOIN vendors v ON p.vendor_fk = v.id
            JOIN product_versions pv ON p.id = pv.device_id
            GROUP BY p.id
            HAVING software_version_count > 1 OR hardware_version_count > 1
            ORDER BY software_version_count DESC, hardware_version_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get overall version statistics.
     */
    public function getVersionStats(): array
    {
        $totalProducts = (int) $this->db->executeQuery('SELECT COUNT(*) FROM products')->fetchOne();
        $productsWithVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT device_id) FROM product_versions
        ')->fetchOne();
        $uniqueSoftwareVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT software_version) FROM product_versions WHERE software_version IS NOT NULL
        ')->fetchOne();
        $uniqueHardwareVersions = (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT hardware_version) FROM product_versions WHERE hardware_version IS NOT NULL
        ')->fetchOne();

        return [
            'total_products' => $totalProducts,
            'products_with_versions' => $productsWithVersions,
            'unique_software_versions' => $uniqueSoftwareVersions,
            'unique_hardware_versions' => $uniqueHardwareVersions,
        ];
    }

    /**
     * Get devices that have a specific cluster as a SERVER.
     * This finds devices that "provide" a capability (can be controlled/read from).
     *
     * @param int      $clusterId       The cluster ID to search for
     * @param int|null $excludeDeviceId Device ID to exclude from results (typically the current device)
     * @param int      $limit           Maximum number of results
     *
     * @return array List of devices with basic info
     */
    public function getDevicesWithServerCluster(int $clusterId, ?int $excludeDeviceId = null, int $limit = 10): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('DISTINCT ds.*')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('pe.server_clusters IS NOT NULL')
            ->andWhere("pe.server_clusters != ''")
            ->andWhere('EXISTS (SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id)')
            ->orderBy('ds.submission_count', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER);

        if (null !== $excludeDeviceId) {
            $qb->andWhere('ds.id != :exclude_id')
                ->setParameter('exclude_id', $excludeDeviceId, ParameterType::INTEGER);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Get count of devices that have a specific cluster as a SERVER.
     */
    public function countDevicesWithServerCluster(int $clusterId, ?int $excludeDeviceId = null): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(DISTINCT ds.id)')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('pe.server_clusters IS NOT NULL')
            ->andWhere("pe.server_clusters != ''")
            ->andWhere('EXISTS (SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id)')
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER);

        if (null !== $excludeDeviceId) {
            $qb->andWhere('ds.id != :exclude_id')
                ->setParameter('exclude_id', $excludeDeviceId, ParameterType::INTEGER);
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Get devices that have a specific cluster as a CLIENT.
     * This finds devices that "consume" a capability (can control/read from this device).
     *
     * @param int      $clusterId       The cluster ID to search for
     * @param int|null $excludeDeviceId Device ID to exclude from results (typically the current device)
     * @param int      $limit           Maximum number of results
     *
     * @return array List of devices with basic info
     */
    public function getDevicesWithClientCluster(int $clusterId, ?int $excludeDeviceId = null, int $limit = 10): array
    {
        $qb = $this->db->createQueryBuilder()
            ->select('DISTINCT ds.*')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('pe.client_clusters IS NOT NULL')
            ->andWhere("pe.client_clusters != ''")
            ->andWhere('EXISTS (SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id)')
            ->orderBy('ds.submission_count', 'DESC')
            ->setMaxResults($limit)
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER);

        if (null !== $excludeDeviceId) {
            $qb->andWhere('ds.id != :exclude_id')
                ->setParameter('exclude_id', $excludeDeviceId, ParameterType::INTEGER);
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * Get count of devices that have a specific cluster as a CLIENT.
     */
    public function countDevicesWithClientCluster(int $clusterId, ?int $excludeDeviceId = null): int
    {
        $qb = $this->db->createQueryBuilder()
            ->select('COUNT(DISTINCT ds.id)')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('pe.client_clusters IS NOT NULL')
            ->andWhere("pe.client_clusters != ''")
            ->andWhere('EXISTS (SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id)')
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER);

        if (null !== $excludeDeviceId) {
            $qb->andWhere('ds.id != :exclude_id')
                ->setParameter('exclude_id', $excludeDeviceId, ParameterType::INTEGER);
        }

        return (int) $qb->executeQuery()->fetchOne();
    }

    /**
     * Get devices that implement a specific cluster (as either server or client).
     */
    public function getDevicesByCluster(int $clusterId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->createQueryBuilder()
            ->select('DISTINCT ds.*')
            ->from('device_summary', 'ds')
            ->join('ds', 'product_endpoints', 'pe', 'ds.id = pe.device_id')
            ->where('EXISTS (SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id)'
                .' OR EXISTS (SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id)')
            ->orderBy('ds.submission_count', 'DESC')
            ->addOrderBy('ds.last_seen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Count devices that implement a specific cluster (as either server or client).
     */
    public function countDevicesByCluster(int $clusterId): int
    {
        return (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT pe.device_id)
            FROM product_endpoints pe
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id
            ) OR EXISTS (
                SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id
            )
        ', [
            'cluster_id' => $clusterId,
        ], [
            'cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchOne();
    }

    /**
     * Get device types that require a specific cluster (from the device_types table).
     */
    public function getDeviceTypesRequiringCluster(int $clusterId): array
    {
        return $this->db->createQueryBuilder()
            ->select(
                'dt.id', 'dt.hex_id', 'dt.name', 'dt.display_category', 'dt.icon',
                'CASE
                       WHEN EXISTS (
                           SELECT 1 FROM json_each(dt.mandatory_server_clusters)
                           WHERE json_extract(value, "$.id") = :cluster_id
                       ) THEN "mandatory_server"
                       WHEN EXISTS (
                           SELECT 1 FROM json_each(dt.optional_server_clusters)
                           WHERE json_extract(value, "$.id") = :cluster_id
                       ) THEN "optional_server"
                       WHEN EXISTS (
                           SELECT 1 FROM json_each(dt.mandatory_client_clusters)
                           WHERE json_extract(value, "$.id") = :cluster_id
                       ) THEN "mandatory_client"
                       WHEN EXISTS (
                           SELECT 1 FROM json_each(dt.optional_client_clusters)
                           WHERE json_extract(value, "$.id") = :cluster_id
                       ) THEN "optional_client"
                   END as requirement_type',
            )
            ->from('device_types', 'dt')
            ->where('EXISTS (
                SELECT 1 FROM json_each(dt.mandatory_server_clusters)
                WHERE json_extract(value, "$.id") = :cluster_id
            ) OR EXISTS (
                SELECT 1 FROM json_each(dt.optional_server_clusters)
                WHERE json_extract(value, "$.id") = :cluster_id
            ) OR EXISTS (
                SELECT 1 FROM json_each(dt.mandatory_client_clusters)
                WHERE json_extract(value, "$.id") = :cluster_id
            ) OR EXISTS (
                SELECT 1 FROM json_each(dt.optional_client_clusters)
                WHERE json_extract(value, "$.id") = :cluster_id
            )')
            ->orderBy('dt.name')
            ->setParameter('cluster_id', $clusterId, ParameterType::INTEGER)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Get device type distribution for a specific vendor.
     * Returns device type IDs with product counts for analytics display.
     *
     * System/utility types (id < 256, e.g. Root Node, OTA Requestor) are
     * excluded — every device has them, so they crowd out the actual mix.
     *
     * NOTE: GROUP BY uses the full json_extract expression rather than the
     * column alias. SQLite collapses to a single row per outer query group
     * when the alias is used with a json_each cross-join, which produced
     * per-endpoint rows with the same name repeated.
     */
    public function getDeviceTypeDistributionByVendor(int $vendorFk): array
    {
        return $this->db->executeQuery('
            SELECT
                CAST(json_extract(json_each.value, "$.id") AS INTEGER) as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.device_types)
            WHERE p.vendor_fk = :vendor_fk
              AND json_extract(json_each.value, "$.id") IS NOT NULL
              AND CAST(json_extract(json_each.value, "$.id") AS INTEGER) >= 256
            GROUP BY CAST(json_extract(json_each.value, "$.id") AS INTEGER)
            ORDER BY product_count DESC
        ', ['vendor_fk' => $vendorFk])->fetchAllAssociative();
    }

    /**
     * Get cluster capabilities for a specific vendor.
     * Returns top clusters (both server and client) with product counts.
     *
     * Utility clusters (Basic Information, Network Commissioning, Operational
     * Credentials, etc.) are excluded — they are mandatory on every Matter
     * device and reveal nothing about the vendor's product line. We want the
     * application clusters that differentiate the products.
     */
    public function getClusterCapabilitiesByVendor(int $vendorFk): array
    {
        return $this->db->executeQuery('
            SELECT cluster_id, type, count FROM (
                SELECT CAST(json_each.value AS INTEGER) as cluster_id, "server" as type, COUNT(DISTINCT pe.device_id) as count
                FROM product_endpoints pe
                JOIN products p ON pe.device_id = p.id, json_each(pe.server_clusters)
                LEFT JOIN clusters c ON c.id = CAST(json_each.value AS INTEGER)
                WHERE p.vendor_fk = :vendor_fk
                  AND (c.category IS NULL OR c.category != "utility")
                GROUP BY CAST(json_each.value AS INTEGER)
                UNION ALL
                SELECT CAST(json_each.value AS INTEGER) as cluster_id, "client" as type, COUNT(DISTINCT pe.device_id) as count
                FROM product_endpoints pe
                JOIN products p ON pe.device_id = p.id, json_each(pe.client_clusters)
                LEFT JOIN clusters c ON c.id = CAST(json_each.value AS INTEGER)
                WHERE p.vendor_fk = :vendor_fk
                  AND (c.category IS NULL OR c.category != "utility")
                GROUP BY CAST(json_each.value AS INTEGER)
            )
            ORDER BY count DESC
            LIMIT 20
        ', ['vendor_fk' => $vendorFk])->fetchAllAssociative();
    }

    /**
     * Get binding support statistics for a specific vendor.
     * Returns total products, products with binding support, and percentage.
     */
    public function getBindingSupportByVendor(int $vendorFk): array
    {
        $result = $this->db->executeQuery('
            SELECT
                COUNT(DISTINCT p.id) as total,
                COUNT(DISTINCT CASE WHEN ds.supports_binding = 1 THEN p.id END) as with_binding
            FROM products p
            LEFT JOIN device_summary ds ON p.id = ds.id
            WHERE p.vendor_fk = :vendor_fk
        ', ['vendor_fk' => $vendorFk])->fetchAssociative() ?: [];

        $total = (int) ($result['total'] ?? 0);
        $withBinding = (int) ($result['with_binding'] ?? 0);

        return [
            'total' => $total,
            'withBinding' => $withBinding,
            'percentage' => $total > 0
                ? round(($withBinding / $total) * 100, 1)
                : 0,
        ];
    }

    // ========================================
    // Device Pairing / Co-occurrence Methods
    // ========================================

    /**
     * Get products frequently paired with a given product.
     * Returns products that appear together in the same installation.
     *
     * @param int $productId              The product to find pairings for
     * @param int $minSharedInstallations Minimum number of shared installations (default 2)
     * @param int $limit                  Maximum results
     *
     * @return array<array{id: int, slug: string, vendor_name: string, product_name: string, shared_installations: int, pairing_strength: float}>
     */
    public function getFrequentlyPairedProducts(int $productId, int $minSharedInstallations = 2, int $limit = 10): array
    {
        // Get the total installations for the source product (for calculating pairing strength)
        $sourceInstallations = $this->getProductInstallationCount($productId);
        if (0 === $sourceInstallations) {
            return [];
        }

        $rows = $this->db->executeQuery('
            SELECT
                ds.id,
                ds.slug,
                ds.vendor_name,
                ds.product_name,
                ds.vendor_slug,
                COUNT(DISTINCT ip1.installation_id) as shared_installations
            FROM installation_products ip1
            JOIN installation_products ip2 ON ip1.installation_id = ip2.installation_id
            JOIN device_summary ds ON ip2.product_id = ds.id
            WHERE ip1.product_id = :product_id
              AND ip2.product_id != :product_id
            GROUP BY ip2.product_id
            HAVING shared_installations >= :min_shared
            ORDER BY shared_installations DESC
            LIMIT :limit
        ', [
            'product_id' => $productId,
            'min_shared' => $minSharedInstallations,
            'limit' => $limit,
        ], [
            'product_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'min_shared' => \Doctrine\DBAL\ParameterType::INTEGER,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();

        // Add pairing strength (percentage of source installations that include this product)
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'vendor_name' => (string) $row['vendor_name'],
            'product_name' => (string) $row['product_name'],
            'shared_installations' => (int) $row['shared_installations'],
            'pairing_strength' => round(((int) $row['shared_installations'] / $sourceInstallations) * 100, 1),
        ], $rows);
    }

    /**
     * Get the number of installations that include a specific product.
     */
    public function getProductInstallationCount(int $productId): int
    {
        return (int) $this->db->executeQuery(
            'SELECT COUNT(DISTINCT installation_id) FROM installation_products WHERE product_id = :product_id',
            ['product_id' => $productId],
            ['product_id' => \Doctrine\DBAL\ParameterType::INTEGER]
        )->fetchOne();
    }

    /**
     * Get top product pairings across all installations.
     * Uses the product_cooccurrence view for efficiency.
     *
     * @return array<array{product_a: int, product_b: int, shared_installations: int, product_a_name: string, product_a_vendor: string, product_a_slug: string, product_b_name: string, product_b_vendor: string, product_b_slug: string}>
     */
    public function getTopProductPairings(int $limit = 20): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                pc.product_a,
                pc.product_b,
                pc.shared_installations,
                pa.product_name as product_a_name,
                pa.vendor_name as product_a_vendor,
                pa.slug as product_a_slug,
                pb.product_name as product_b_name,
                pb.vendor_name as product_b_vendor,
                pb.slug as product_b_slug
            FROM product_cooccurrence pc
            JOIN device_summary pa ON pc.product_a = pa.id
            JOIN device_summary pb ON pc.product_b = pb.id
            ORDER BY pc.shared_installations DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'product_a' => (int) $row['product_a'],
            'product_b' => (int) $row['product_b'],
            'shared_installations' => (int) $row['shared_installations'],
            'product_a_name' => (string) $row['product_a_name'],
            'product_a_vendor' => (string) $row['product_a_vendor'],
            'product_a_slug' => (string) $row['product_a_slug'],
            'product_b_name' => (string) $row['product_b_name'],
            'product_b_vendor' => (string) $row['product_b_vendor'],
            'product_b_slug' => (string) $row['product_b_slug'],
        ], $rows);
    }

    /**
     * Get pairing statistics overview.
     *
     * @return array{total_installations: int, installations_with_multiple_products: int, total_pairings: int, avg_products_per_installation: float}
     */
    public function getPairingStats(): array
    {
        $result = $this->db->executeQuery('
            SELECT
                COUNT(DISTINCT installation_id) as total_installations,
                (SELECT COUNT(DISTINCT installation_id)
                 FROM installation_products
                 GROUP BY installation_id
                 HAVING COUNT(product_id) > 1) as multi_product_installations
            FROM installation_products
        ')->fetchAssociative();

        $avgProducts = $this->db->executeQuery('
            SELECT AVG(product_count) as avg_products
            FROM (
                SELECT installation_id, COUNT(product_id) as product_count
                FROM installation_products
                GROUP BY installation_id
            )
        ')->fetchOne();

        $totalPairings = $this->db->executeQuery('
            SELECT COUNT(*) FROM product_cooccurrence
        ')->fetchOne();

        return [
            'total_installations' => (int) ($result['total_installations'] ?? 0),
            'installations_with_multiple_products' => (int) ($result['multi_product_installations'] ?? 0),
            'total_pairings' => (int) ($totalPairings ?? 0),
            'avg_products_per_installation' => round((float) ($avgProducts ?? 0), 1),
        ];
    }

    /**
     * Get products that are commonly the "hub" of installations
     * (appear in the most multi-product installations).
     *
     * @return array<array{id: int, slug: string, product_name: string, vendor_name: string, installation_count: int, unique_pairings: int}>
     */
    public function getMostConnectedProducts(int $limit = 10): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                ds.id,
                ds.slug,
                ds.product_name,
                ds.vendor_name,
                ds.vendor_slug,
                COUNT(DISTINCT ip.installation_id) as installation_count,
                (SELECT COUNT(DISTINCT ip2.product_id)
                 FROM installation_products ip2
                 WHERE ip2.installation_id IN (
                     SELECT installation_id FROM installation_products WHERE product_id = ds.id
                 ) AND ip2.product_id != ds.id
                ) as unique_pairings
            FROM device_summary ds
            JOIN installation_products ip ON ds.id = ip.product_id
            WHERE EXISTS (
                SELECT 1 FROM installation_products ip3
                WHERE ip3.installation_id = ip.installation_id
                  AND ip3.product_id != ip.product_id
            )
            GROUP BY ds.id
            ORDER BY unique_pairings DESC, installation_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'slug' => (string) $row['slug'],
            'product_name' => (string) $row['product_name'],
            'vendor_name' => (string) $row['vendor_name'],
            'installation_count' => (int) $row['installation_count'],
            'unique_pairings' => (int) $row['unique_pairings'],
        ], $rows);
    }

    /**
     * Get vendor pairings - which vendors' products are commonly used together.
     *
     * @return array<array{vendor_a: string, vendor_a_slug: string, vendor_b: string, vendor_b_slug: string, shared_installations: int}>
     */
    public function getVendorPairings(int $limit = 15): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                va.name as vendor_a,
                va.slug as vendor_a_slug,
                vb.name as vendor_b,
                vb.slug as vendor_b_slug,
                COUNT(DISTINCT ip1.installation_id) as shared_installations
            FROM installation_products ip1
            JOIN installation_products ip2 ON ip1.installation_id = ip2.installation_id
            JOIN products pa ON ip1.product_id = pa.id
            JOIN products pb ON ip2.product_id = pb.id
            JOIN vendors va ON pa.vendor_fk = va.id
            JOIN vendors vb ON pb.vendor_fk = vb.id
            WHERE va.id < vb.id
            GROUP BY va.id, vb.id
            HAVING shared_installations >= 2
            ORDER BY shared_installations DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'vendor_a' => (string) $row['vendor_a'],
            'vendor_a_slug' => (string) $row['vendor_a_slug'],
            'vendor_b' => (string) $row['vendor_b'],
            'vendor_b_slug' => (string) $row['vendor_b_slug'],
            'shared_installations' => (int) $row['shared_installations'],
        ], $rows);
    }

    // ========================================
    // Vendor Index Page Methods
    // ========================================

    /**
     * Get top device types for each vendor (batch query for index page).
     * Returns a map of vendor_fk => array of top device type IDs.
     *
     * @return array<int, array<int>> Map of vendor_fk => [deviceTypeId, ...]
     */
    public function getTopDeviceTypesByVendor(int $maxPerVendor = 5): array
    {
        // Get all device types per vendor with counts.
        // Filter out system/utility types (id < 256, e.g. Root Node, Power Source,
        // OTA Requestor) — they're on every device and reveal nothing about what
        // the vendor actually makes.
        //
        // NOTE: GROUP BY uses the full CAST expression rather than the column
        // alias. SQLite collapses to a single row per vendor when the alias is
        // used with a json_each cross-join, so every vendor previously surfaced
        // only one device type — usually Root Node from endpoint 0.
        $rows = $this->db->executeQuery('
            SELECT
                p.vendor_fk,
                CAST(json_extract(json_each.value, "$.id") AS INTEGER) as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
              AND CAST(json_extract(json_each.value, "$.id") AS INTEGER) >= 256
            GROUP BY p.vendor_fk, CAST(json_extract(json_each.value, "$.id") AS INTEGER)
            ORDER BY p.vendor_fk, product_count DESC
        ')->fetchAllAssociative();

        // Group by vendor and take top N per vendor
        $result = [];
        $vendorCounts = [];

        foreach ($rows as $row) {
            $vendorFk = (int) $row['vendor_fk'];
            $deviceTypeId = (int) $row['device_type_id'];

            if (!isset($vendorCounts[$vendorFk])) {
                $vendorCounts[$vendorFk] = 0;
            }

            if ($vendorCounts[$vendorFk] < $maxPerVendor) {
                $result[$vendorFk][] = $deviceTypeId;
                ++$vendorCounts[$vendorFk];
            }
        }

        return $result;
    }

    /**
     * Get the most popular product for each of the top N categories (excluding System).
     * Used for dashboard highlights section.
     *
     * @param int $limit Number of categories to return
     *
     * @return array<array{category: string, product_id: int, product_name: string, vendor_name: string, slug: string, count: int}>
     */
    public function getTopProductsByCategory(\App\Service\MatterRegistry $registry, int $limit = 3): array
    {
        // Get category distribution (excluding System)
        $categoryDistribution = $this->getCategoryDistribution($registry);
        unset($categoryDistribution['System']);

        // Take top N categories
        $topCategories = array_slice(array_keys($categoryDistribution), 0, $limit);

        if ([] === $topCategories) {
            return [];
        }

        $highlights = [];

        foreach ($topCategories as $category) {
            // Get the device type IDs for this category
            $allDeviceTypes = $registry->getAllDeviceTypeMetadata();
            $categoryDeviceTypeIds = [];
            foreach ($allDeviceTypes as $id => $meta) {
                if (($meta['displayCategory'] ?? 'System') === $category) {
                    $categoryDeviceTypeIds[] = $id;
                }
            }

            if ([] === $categoryDeviceTypeIds) {
                continue;
            }

            // Find the most popular product with this device type
            $placeholders = implode(',', array_fill(0, \count($categoryDeviceTypeIds), '?'));
            $result = $this->db->executeQuery("
                SELECT
                    ds.id as product_id,
                    ds.product_name,
                    ds.vendor_name,
                    ds.slug,
                    ds.vendor_slug,
                    COUNT(DISTINCT pe.device_id) as count
                FROM device_summary ds
                JOIN product_endpoints pe ON ds.id = pe.device_id
                WHERE EXISTS (
                    SELECT 1 FROM json_each(pe.device_types)
                    WHERE json_extract(value, \"\$.id\") IN ({$placeholders})
                )
                GROUP BY ds.id
                ORDER BY count DESC, ds.submission_count DESC
                LIMIT 1
            ", $categoryDeviceTypeIds)->fetchAssociative();

            if ($result) {
                $highlights[] = [
                    'category' => $category,
                    'product_id' => (int) $result['product_id'],
                    'product_name' => $result['product_name'] ?? 'Unknown Product',
                    'vendor_name' => $result['vendor_name'] ?? 'Unknown Vendor',
                    'slug' => $result['slug'],
                    'vendor_slug' => $result['vendor_slug'],
                    'count' => (int) $result['count'],
                ];
            }
        }

        return $highlights;
    }

    /**
     * Get market insights for the vendor index page.
     *
     * @return array{totalVendors: int, totalProducts: int, vendorsWithDevices: int, top10ProductShare: float, avgProductsPerVendor: float}
     */
    public function getVendorMarketInsights(): array
    {
        // Get basic counts
        $stats = $this->db->executeQuery('
            SELECT
                (SELECT COUNT(*) FROM vendors) as total_vendors,
                (SELECT COUNT(*) FROM products) as total_products,
                (SELECT COUNT(*) FROM vendors WHERE device_count > 0) as vendors_with_devices
        ')->fetchAssociative() ?: [];

        // Get top 10 vendors' product count
        $top10Products = $this->db->executeQuery('
            SELECT COALESCE(SUM(device_count), 0) as top10_products
            FROM (
                SELECT device_count FROM vendors
                ORDER BY device_count DESC
                LIMIT 10
            )
        ')->fetchOne();

        $totalProducts = (int) ($stats['total_products'] ?? 0);
        $totalVendors = (int) ($stats['total_vendors'] ?? 0);
        $vendorsWithDevices = (int) ($stats['vendors_with_devices'] ?? 0);

        return [
            'totalVendors' => $totalVendors,
            'totalProducts' => $totalProducts,
            'vendorsWithDevices' => $vendorsWithDevices,
            'top10ProductShare' => $totalProducts > 0
                ? round(((int) $top10Products / $totalProducts) * 100, 1)
                : 0,
            'avgProductsPerVendor' => $vendorsWithDevices > 0
                ? round($totalProducts / $vendorsWithDevices, 1)
                : 0,
        ];
    }

    /**
     * Get comprehensive market analysis data.
     *
     * @return array<string, mixed>
     */
    public function getMarketAnalysis(\App\Service\MatterRegistry $registry): array
    {
        // Category distribution
        $categoryDistribution = $this->getCategoryDistribution($registry);

        // Spec version distribution
        $specVersions = $this->getSpecVersionDistribution($registry);

        // Connectivity type distribution
        $connectivity = $this->db->executeQuery("
            SELECT
                CASE
                    WHEN connectivity_types LIKE '%thread%' THEN 'Thread'
                    WHEN connectivity_types LIKE '%wifi%' THEN 'WiFi'
                    WHEN connectivity_types LIKE '%ethernet%' THEN 'Ethernet'
                    ELSE 'Unknown'
                END as conn_type,
                COUNT(*) as count
            FROM products
            WHERE connectivity_types IS NOT NULL
            GROUP BY conn_type
            ORDER BY count DESC
        ")->fetchAllAssociative();

        // Binding support stats
        $bindingStats = $this->db->executeQuery('
            SELECT
                SUM(CASE WHEN supports_binding = 1 THEN 1 ELSE 0 END) as with_binding,
                SUM(CASE WHEN supports_binding = 0 OR supports_binding IS NULL THEN 1 ELSE 0 END) as without_binding
            FROM device_summary
        ')->fetchAssociative();

        // Top vendors by market share
        $topVendors = $this->db->executeQuery('
            SELECT v.name, v.device_count,
                   ROUND(v.device_count * 100.0 / (SELECT SUM(device_count) FROM vendors WHERE device_count > 0), 1) as market_share
            FROM vendors v
            WHERE v.device_count > 0
            ORDER BY v.device_count DESC
            LIMIT 15
        ')->fetchAllAssociative();

        // Monthly certification growth (prefer certification_date, fall back to first_seen)
        $monthlyGrowth = $this->db->executeQuery("
            SELECT strftime('%Y-%m', COALESCE(certification_date, first_seen)) as month, COUNT(*) as new_products
            FROM products
            WHERE COALESCE(certification_date, first_seen) IS NOT NULL
            GROUP BY month
            ORDER BY month DESC
            LIMIT 24
        ")->fetchAllAssociative();

        // Count products with actual certification dates vs first_seen
        $certificationCounts = $this->db->executeQuery('
            SELECT
                SUM(CASE WHEN certification_date IS NOT NULL THEN 1 ELSE 0 END) as with_cert_date,
                SUM(CASE WHEN certification_date IS NULL AND first_seen IS NOT NULL THEN 1 ELSE 0 END) as with_first_seen_only
            FROM products
        ')->fetchAssociative();

        // Discovery capabilities distribution
        $discoveryStats = $this->db->executeQuery('
            SELECT
                SUM(CASE WHEN discovery_capabilities_bitmask & 1 THEN 1 ELSE 0 END) as softap,
                SUM(CASE WHEN discovery_capabilities_bitmask & 2 THEN 1 ELSE 0 END) as ble,
                SUM(CASE WHEN discovery_capabilities_bitmask & 4 THEN 1 ELSE 0 END) as on_network,
                COUNT(*) as total
            FROM products
            WHERE discovery_capabilities_bitmask IS NOT NULL
        ')->fetchAssociative();

        return [
            'categoryDistribution' => $categoryDistribution,
            'specVersions' => $specVersions,
            'connectivity' => $connectivity,
            'bindingStats' => $bindingStats,
            'topVendors' => $topVendors,
            'monthlyGrowth' => array_reverse($monthlyGrowth),
            'discoveryStats' => $discoveryStats,
            'certificationCounts' => $certificationCounts,
        ];
    }

    /**
     * Get version/firmware timeline data.
     *
     * @return array<string, mixed>
     */
    public function getVersionTimeline(): array
    {
        // Products with multiple versions (actively updated)
        $activelyUpdated = $this->db->executeQuery('
            SELECT
                ds.product_name,
                ds.vendor_name,
                ds.slug,
                COUNT(DISTINCT pv.software_version) as version_count,
                MIN(pv.first_seen) as first_version_date,
                MAX(pv.last_seen) as latest_version_date
            FROM device_summary ds
            JOIN product_versions pv ON ds.id = pv.device_id
            GROUP BY ds.id
            HAVING version_count > 1
            ORDER BY version_count DESC
            LIMIT 30
        ')->fetchAllAssociative();

        // Version distribution stats
        $versionStats = $this->db->executeQuery('
            SELECT
                version_count,
                COUNT(*) as product_count
            FROM (
                SELECT ds.id, COUNT(DISTINCT pv.software_version) as version_count
                FROM device_summary ds
                JOIN product_versions pv ON ds.id = pv.device_id
                GROUP BY ds.id
            )
            GROUP BY version_count
            ORDER BY version_count
        ')->fetchAllAssociative();

        // Recent version updates (last 30 days)
        $recentUpdates = $this->db->executeQuery("
            SELECT
                ds.product_name,
                ds.vendor_name,
                ds.slug,
                pv.software_version,
                pv.hardware_version,
                pv.first_seen,
                pv.count as submission_count
            FROM product_versions pv
            JOIN device_summary ds ON ds.id = pv.device_id
            WHERE pv.first_seen >= date('now', '-30 days')
            ORDER BY pv.first_seen DESC
            LIMIT 50
        ")->fetchAllAssociative();

        // Average versions per product by vendor
        $vendorUpdateFrequency = $this->db->executeQuery('
            SELECT
                v.name as vendor_name,
                v.slug as vendor_slug,
                ROUND(AVG(version_counts.version_count), 1) as avg_versions,
                COUNT(*) as product_count
            FROM vendors v
            JOIN (
                SELECT ds.vendor_fk, COUNT(DISTINCT pv.software_version) as version_count
                FROM device_summary ds
                JOIN product_versions pv ON ds.id = pv.device_id
                GROUP BY ds.id
            ) version_counts ON v.id = version_counts.vendor_fk
            GROUP BY v.id
            HAVING product_count >= 3
            ORDER BY avg_versions DESC
            LIMIT 20
        ')->fetchAllAssociative();

        return [
            'activelyUpdated' => $activelyUpdated,
            'versionStats' => $versionStats,
            'recentUpdates' => $recentUpdates,
            'vendorUpdateFrequency' => $vendorUpdateFrequency,
        ];
    }

    /**
     * Count total number of devices.
     */
    public function countDevices(): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('products')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Count total number of vendors.
     */
    public function countVendors(): int
    {
        return (int) $this->db->createQueryBuilder()
            ->select('COUNT(*)')
            ->from('vendors')
            ->executeQuery()
            ->fetchOne();
    }
}
