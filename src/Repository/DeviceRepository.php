<?php

declare(strict_types=1);

namespace App\Repository;

use App\Service\DatabaseService;
use Doctrine\DBAL\Connection;

class DeviceRepository
{
    private const BINDING_CLUSTER_ID = 30; // 0x001E

    private Connection $db;

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
                ? json_decode($existing['connectivity_types'], true) ?? []
                : [];
            $connectivityTypes = array_values(array_unique(array_merge($existingTypes, $connectivityTypes)));
            sort($connectivityTypes);
        }
        $connectivityTypesJson = !empty($connectivityTypes) ? json_encode($connectivityTypes) : null;

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
        $this->db->executeStatement('
            INSERT INTO product_endpoints (device_id, endpoint_id, hardware_version, software_version, device_types, server_clusters, client_clusters)
            VALUES (:device_id, :endpoint_id, :hardware_version, :software_version, :device_types, :server_clusters, :client_clusters)
            ON CONFLICT(device_id, endpoint_id, hardware_version, software_version) DO UPDATE SET
                device_types = excluded.device_types,
                server_clusters = excluded.server_clusters,
                client_clusters = excluded.client_clusters,
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
        ]);
    }

    public function getAllDevices(int $limit = 100, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            ORDER BY submission_count DESC, last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    public function getDeviceCount(): int
    {
        return (int) $this->db->executeQuery('SELECT COUNT(*) FROM products')->fetchOne();
    }

    /**
     * Get devices with optional filters.
     *
     * @param array{
     *     connectivity?: array<string>,
     *     binding?: bool|null,
     *     vendor?: int|null,
     *     search?: string|null
     * } $filters
     */
    public function getFilteredDevices(array $filters, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT * FROM device_summary WHERE 1=1';
        $params = [];
        $types = [];

        $sql = $this->applyFilters($sql, $filters, $params, $types);

        $sql .= ' ORDER BY submission_count DESC, last_seen DESC LIMIT :limit OFFSET :offset';
        $params['limit'] = $limit;
        $params['offset'] = $offset;
        $types['limit'] = \Doctrine\DBAL\ParameterType::INTEGER;
        $types['offset'] = \Doctrine\DBAL\ParameterType::INTEGER;

        return $this->db->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Get count of devices with optional filters.
     *
     * @param array{
     *     connectivity?: array<string>,
     *     binding?: bool|null,
     *     vendor?: int|null,
     *     search?: string|null
     * } $filters
     */
    public function getFilteredDeviceCount(array $filters): int
    {
        $sql = 'SELECT COUNT(*) FROM device_summary WHERE 1=1';
        $params = [];
        $types = [];

        $sql = $this->applyFilters($sql, $filters, $params, $types);

        return (int) $this->db->executeQuery($sql, $params, $types)->fetchOne();
    }

    /**
     * Apply filters to SQL query.
     *
     * @param array<string, mixed> $params
     * @param array<string, mixed> $types
     */
    private function applyFilters(string $sql, array $filters, array &$params, array &$types): string
    {
        // Connectivity filter
        if (!empty($filters['connectivity'])) {
            $connectivityConditions = [];
            foreach ($filters['connectivity'] as $i => $type) {
                $paramName = 'conn_'.$i;
                $connectivityConditions[] = "connectivity_types LIKE :{$paramName}";
                $params[$paramName] = '%"'.$type.'"%';
            }
            $sql .= ' AND ('.implode(' OR ', $connectivityConditions).')';
        }

        // Binding filter
        if (isset($filters['binding'])) {
            $sql .= ' AND supports_binding = :binding';
            $params['binding'] = $filters['binding'] ? 1 : 0;
            $types['binding'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        // Vendor filter
        if (!empty($filters['vendor'])) {
            $sql .= ' AND vendor_fk = :vendor';
            $params['vendor'] = $filters['vendor'];
            $types['vendor'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        // Device types filter (array of IDs)
        if (!empty($filters['device_types'])) {
            $deviceTypeConditions = [];
            foreach ($filters['device_types'] as $i => $typeId) {
                $paramName = 'device_type_'.$i;
                $deviceTypeConditions[] = "json_extract(value, \"$.id\") = :{$paramName}";
                $params[$paramName] = $typeId;
                $types[$paramName] = \Doctrine\DBAL\ParameterType::INTEGER;
            }
            $sql .= ' AND id IN (
                SELECT DISTINCT pe.device_id
                FROM product_endpoints pe
                WHERE EXISTS (
                    SELECT 1 FROM json_each(pe.device_types)
                    WHERE '.implode(' OR ', $deviceTypeConditions).'
                )
            )';
        }

        // Search filter
        if (!empty($filters['search'])) {
            $sql .= ' AND (vendor_name LIKE :search OR product_name LIKE :search
                      OR CAST(vendor_id AS TEXT) LIKE :search OR CAST(product_id AS TEXT) LIKE :search)';
            $params['search'] = '%'.$filters['search'].'%';
        }

        return $sql;
    }

    /**
     * Get connectivity type facets (counts per type).
     *
     * @return array<string, int>
     */
    public function getConnectivityFacets(): array
    {
        $result = $this->db->executeQuery("
            SELECT
                SUM(CASE WHEN connectivity_types LIKE '%\"thread\"%' THEN 1 ELSE 0 END) as thread,
                SUM(CASE WHEN connectivity_types LIKE '%\"wifi\"%' THEN 1 ELSE 0 END) as wifi,
                SUM(CASE WHEN connectivity_types LIKE '%\"ethernet\"%' THEN 1 ELSE 0 END) as ethernet
            FROM products
        ")->fetchAssociative();

        return [
            'thread' => (int) ($result['thread'] ?? 0),
            'wifi' => (int) ($result['wifi'] ?? 0),
            'ethernet' => (int) ($result['ethernet'] ?? 0),
        ];
    }

    /**
     * Get binding support facets.
     *
     * @return array{with_binding: int, without_binding: int}
     */
    public function getBindingFacets(): array
    {
        $result = $this->db->executeQuery('
            SELECT
                SUM(CASE WHEN supports_binding = 1 THEN 1 ELSE 0 END) as with_binding,
                SUM(CASE WHEN supports_binding = 0 THEN 1 ELSE 0 END) as without_binding
            FROM device_summary
        ')->fetchAssociative();

        return [
            'with_binding' => (int) ($result['with_binding'] ?? 0),
            'without_binding' => (int) ($result['without_binding'] ?? 0),
        ];
    }

    /**
     * Get top vendors with device counts for faceted search.
     *
     * @return array<array{id: int, name: string, slug: string, count: int}>
     */
    public function getVendorFacets(int $limit = 20): array
    {
        return $this->db->executeQuery('
            SELECT v.id, v.name, v.slug, COUNT(p.id) as count
            FROM vendors v
            JOIN products p ON p.vendor_fk = v.id
            GROUP BY v.id
            HAVING count > 0
            ORDER BY count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get device type facets with counts for faceted search.
     * Uses the device_types table for names and joins with product_endpoints.
     *
     * @return array<array{id: int, name: string, count: int}>
     */
    public function getDeviceTypeFacets(int $limit = 15): array
    {
        return $this->db->executeQuery('
            SELECT
                dt.id,
                dt.name,
                COUNT(DISTINCT pe.device_id) as count
            FROM device_types dt
            JOIN product_endpoints pe ON EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = dt.id
            )
            GROUP BY dt.id
            HAVING count > 0
            ORDER BY count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    public function getDevice(int $id): ?array
    {
        $result = $this->db->executeQuery(
            'SELECT * FROM device_summary WHERE id = :id',
            ['id' => $id]
        )->fetchAssociative();

        return $result ?: null;
    }

    public function getDeviceBySlug(string $slug): ?array
    {
        $result = $this->db->executeQuery(
            'SELECT * FROM device_summary WHERE slug = :slug',
            ['slug' => $slug]
        )->fetchAssociative();

        return $result ?: null;
    }

    /**
     * Get all endpoints for a device, grouped by version.
     * Returns endpoints with version info, ordered by version then endpoint_id.
     */
    public function getDeviceEndpoints(int $deviceId): array
    {
        $rows = $this->db->executeQuery('
            SELECT endpoint_id, hardware_version, software_version, device_types, server_clusters, client_clusters, first_seen, last_seen, submission_count
            FROM product_endpoints
            WHERE device_id = :device_id
            ORDER BY software_version DESC, hardware_version DESC, endpoint_id
        ', ['device_id' => $deviceId])->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode($row['device_types'], true) ?? [];
            $row['server_clusters'] = json_decode($row['server_clusters'], true) ?? [];
            $row['client_clusters'] = json_decode($row['client_clusters'], true) ?? [];
            // Derive binding support from either server or client clusters
            $row['has_binding_cluster'] = \in_array(self::BINDING_CLUSTER_ID, $row['server_clusters'], true)
                || \in_array(self::BINDING_CLUSTER_ID, $row['client_clusters'], true);
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    /**
     * Get endpoints for a specific version of a device.
     */
    public function getDeviceEndpointsByVersion(int $deviceId, ?string $hardwareVersion, ?string $softwareVersion): array
    {
        $rows = $this->db->executeQuery('
            SELECT endpoint_id, hardware_version, software_version, device_types, server_clusters, client_clusters, first_seen, last_seen, submission_count
            FROM product_endpoints
            WHERE device_id = :device_id
              AND (hardware_version = :hardware_version OR (hardware_version IS NULL AND :hardware_version IS NULL))
              AND (software_version = :software_version OR (software_version IS NULL AND :software_version IS NULL))
            ORDER BY endpoint_id
        ', [
            'device_id' => $deviceId,
            'hardware_version' => $hardwareVersion,
            'software_version' => $softwareVersion,
        ])->fetchAllAssociative();

        $endpoints = [];
        foreach ($rows as $row) {
            $row['device_types'] = json_decode($row['device_types'], true) ?? [];
            $row['server_clusters'] = json_decode($row['server_clusters'], true) ?? [];
            $row['client_clusters'] = json_decode($row['client_clusters'], true) ?? [];
            $row['has_binding_cluster'] = \in_array(self::BINDING_CLUSTER_ID, $row['server_clusters'], true)
                || \in_array(self::BINDING_CLUSTER_ID, $row['client_clusters'], true);
            $endpoints[] = $row;
        }

        return $endpoints;
    }

    /**
     * Get unique versions that have endpoint data for a device.
     */
    public function getDeviceEndpointVersions(int $deviceId): array
    {
        return $this->db->executeQuery('
            SELECT DISTINCT hardware_version, software_version,
                   MIN(first_seen) as first_seen, MAX(last_seen) as last_seen,
                   SUM(submission_count) as total_submissions
            FROM product_endpoints
            WHERE device_id = :device_id
            GROUP BY hardware_version, software_version
            ORDER BY software_version DESC, hardware_version DESC
        ', ['device_id' => $deviceId])->fetchAllAssociative();
    }

    public function getDeviceVersions(int $deviceId): array
    {
        return $this->db->executeQuery('
            SELECT hardware_version, software_version, count, first_seen, last_seen
            FROM product_versions
            WHERE device_id = :device_id
            ORDER BY count DESC
        ', ['device_id' => $deviceId])->fetchAllAssociative();
    }

    public function searchDevices(string $query, int $limit = 50): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE vendor_name LIKE :query
               OR product_name LIKE :query
               OR CAST(vendor_id AS TEXT) LIKE :query
               OR CAST(product_id AS TEXT) LIKE :query
            ORDER BY submission_count DESC
            LIMIT :limit
        ', [
            'query' => "%$query%",
            'limit' => $limit,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Get devices by vendor FK.
     */
    public function getDevicesByVendor(int $vendorFk, int $limit = 100, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE vendor_fk = :vendor_fk
            ORDER BY submission_count DESC, last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'vendor_fk' => $vendorFk,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Count devices by vendor FK.
     */
    public function getDeviceCountByVendor(int $vendorFk): int
    {
        return (int) $this->db->executeQuery(
            'SELECT COUNT(*) FROM products WHERE vendor_fk = :vendor_fk',
            ['vendor_fk' => $vendorFk]
        )->fetchOne();
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
     */
    public function getDevicesByDeviceType(int $deviceTypeId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT DISTINCT ds.*
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )
            ORDER BY ds.submission_count DESC, ds.last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'device_type_id' => $deviceTypeId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'device_type_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Count devices that implement a specific device type.
     */
    public function countDevicesByDeviceType(int $deviceTypeId): int
    {
        return (int) $this->db->executeQuery('
            SELECT COUNT(DISTINCT pe.device_id)
            FROM product_endpoints pe
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.device_types)
                WHERE json_extract(value, "$.id") = :device_type_id
            )
        ', [
            'device_type_id' => $deviceTypeId,
        ], [
            'device_type_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchOne();
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

        uksort($versionStats, 'version_compare');

        return $versionStats;
    }

    /**
     * Get top vendors by device count.
     */
    public function getTopVendors(int $limit = 10): array
    {
        return $this->db->executeQuery('
            SELECT v.id, v.name, v.slug, v.spec_id, v.device_count
            FROM vendors v
            WHERE v.device_count > 0
            ORDER BY v.device_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get most recently discovered devices.
     */
    public function getRecentDevices(int $limit = 5): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            ORDER BY first_seen DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
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
     * Get devices that support binding (have cluster 30).
     */
    public function getBindingCapableDevices(int $limit = 50): array
    {
        return $this->db->executeQuery('
            SELECT * FROM device_summary
            WHERE supports_binding = 1
            ORDER BY submission_count DESC
            LIMIT :limit
        ', ['limit' => $limit], ['limit' => \Doctrine\DBAL\ParameterType::INTEGER])->fetchAllAssociative();
    }

    /**
     * Get binding support breakdown by category.
     * Device types are stored as JSON arrays of objects with 'id' and 'revision' fields.
     */
    public function getBindingByCategory(\App\Service\MatterRegistry $registry): array
    {
        $rows = $this->db->executeQuery('
            SELECT
                pe.device_id,
                json_extract(json_each.value, "$.id") as device_type_id,
                MAX(CASE WHEN EXISTS (
                    SELECT 1 FROM json_each(pe.server_clusters) WHERE value = 30
                ) OR EXISTS (
                    SELECT 1 FROM json_each(pe.client_clusters) WHERE value = 30
                ) THEN 1 ELSE 0 END) as has_binding
            FROM product_endpoints pe, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY pe.device_id, json_extract(json_each.value, "$.id")
        ')->fetchAllAssociative();

        $categoryStats = [];
        foreach ($rows as $row) {
            $metadata = $registry->getDeviceTypeMetadata((int) $row['device_type_id']);
            $displayCategory = $metadata['displayCategory'] ?? 'Unknown';

            if (!isset($categoryStats[$displayCategory])) {
                $categoryStats[$displayCategory] = ['total' => 0, 'with_binding' => 0];
            }
            ++$categoryStats[$displayCategory]['total'];
            if ($row['has_binding']) {
                ++$categoryStats[$displayCategory]['with_binding'];
            }
        }

        // Calculate percentages
        foreach ($categoryStats as &$stat) {
            $stat['percentage'] = $stat['total'] > 0
                ? round(($stat['with_binding'] / $stat['total']) * 100, 1)
                : 0;
        }

        uasort($categoryStats, fn ($a, $b) => $b['percentage'] <=> $a['percentage']);

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
        $sql = '
            SELECT DISTINCT ds.*
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE pe.server_clusters IS NOT NULL
              AND pe.server_clusters != \'\'
              AND EXISTS (
                SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id
            )
        ';

        $params = ['cluster_id' => $clusterId, 'limit' => $limit];
        $types = ['cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER, 'limit' => \Doctrine\DBAL\ParameterType::INTEGER];

        if (null !== $excludeDeviceId) {
            $sql .= ' AND ds.id != :exclude_id';
            $params['exclude_id'] = $excludeDeviceId;
            $types['exclude_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY ds.submission_count DESC LIMIT :limit';

        return $this->db->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Get count of devices that have a specific cluster as a SERVER.
     */
    public function countDevicesWithServerCluster(int $clusterId, ?int $excludeDeviceId = null): int
    {
        $sql = '
            SELECT COUNT(DISTINCT ds.id)
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE pe.server_clusters IS NOT NULL
              AND pe.server_clusters != \'\'
              AND EXISTS (
                SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id
            )
        ';

        $params = ['cluster_id' => $clusterId];
        $types = ['cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER];

        if (null !== $excludeDeviceId) {
            $sql .= ' AND ds.id != :exclude_id';
            $params['exclude_id'] = $excludeDeviceId;
            $types['exclude_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        return (int) $this->db->executeQuery($sql, $params, $types)->fetchOne();
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
        $sql = '
            SELECT DISTINCT ds.*
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE pe.client_clusters IS NOT NULL
              AND pe.client_clusters != \'\'
              AND EXISTS (
                SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id
            )
        ';

        $params = ['cluster_id' => $clusterId, 'limit' => $limit];
        $types = ['cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER, 'limit' => \Doctrine\DBAL\ParameterType::INTEGER];

        if (null !== $excludeDeviceId) {
            $sql .= ' AND ds.id != :exclude_id';
            $params['exclude_id'] = $excludeDeviceId;
            $types['exclude_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        $sql .= ' ORDER BY ds.submission_count DESC LIMIT :limit';

        return $this->db->executeQuery($sql, $params, $types)->fetchAllAssociative();
    }

    /**
     * Get count of devices that have a specific cluster as a CLIENT.
     */
    public function countDevicesWithClientCluster(int $clusterId, ?int $excludeDeviceId = null): int
    {
        $sql = '
            SELECT COUNT(DISTINCT ds.id)
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE pe.client_clusters IS NOT NULL
              AND pe.client_clusters != \'\'
              AND EXISTS (
                SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id
            )
        ';

        $params = ['cluster_id' => $clusterId];
        $types = ['cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER];

        if (null !== $excludeDeviceId) {
            $sql .= ' AND ds.id != :exclude_id';
            $params['exclude_id'] = $excludeDeviceId;
            $types['exclude_id'] = \Doctrine\DBAL\ParameterType::INTEGER;
        }

        return (int) $this->db->executeQuery($sql, $params, $types)->fetchOne();
    }

    /**
     * Get devices that implement a specific cluster (as either server or client).
     */
    public function getDevicesByCluster(int $clusterId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->executeQuery('
            SELECT DISTINCT ds.*
            FROM device_summary ds
            JOIN product_endpoints pe ON ds.id = pe.device_id
            WHERE EXISTS (
                SELECT 1 FROM json_each(pe.server_clusters) WHERE value = :cluster_id
            ) OR EXISTS (
                SELECT 1 FROM json_each(pe.client_clusters) WHERE value = :cluster_id
            )
            ORDER BY ds.submission_count DESC, ds.last_seen DESC
            LIMIT :limit OFFSET :offset
        ', [
            'cluster_id' => $clusterId,
            'limit' => $limit,
            'offset' => $offset,
        ], [
            'cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER,
            'limit' => \Doctrine\DBAL\ParameterType::INTEGER,
            'offset' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
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
        return $this->db->executeQuery('
            SELECT dt.id, dt.hex_id, dt.name, dt.display_category, dt.icon,
                   CASE
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
                   END as requirement_type
            FROM device_types dt
            WHERE EXISTS (
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
            )
            ORDER BY dt.name
        ', [
            'cluster_id' => $clusterId,
        ], [
            'cluster_id' => \Doctrine\DBAL\ParameterType::INTEGER,
        ])->fetchAllAssociative();
    }

    /**
     * Get device type distribution for a specific vendor.
     * Returns device type IDs with product counts for analytics display.
     */
    public function getDeviceTypeDistributionByVendor(int $vendorFk): array
    {
        return $this->db->executeQuery('
            SELECT
                json_extract(json_each.value, "$.id") as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.device_types)
            WHERE p.vendor_fk = :vendor_fk
              AND json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY device_type_id
            ORDER BY product_count DESC
        ', ['vendor_fk' => $vendorFk])->fetchAllAssociative();
    }

    /**
     * Get cluster capabilities for a specific vendor.
     * Returns top clusters (both server and client) with product counts.
     */
    public function getClusterCapabilitiesByVendor(int $vendorFk): array
    {
        return $this->db->executeQuery('
            SELECT json_each.value as cluster_id, "server" as type, COUNT(DISTINCT pe.device_id) as count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.server_clusters)
            WHERE p.vendor_fk = :vendor_fk
            GROUP BY cluster_id
            UNION ALL
            SELECT json_each.value as cluster_id, "client" as type, COUNT(DISTINCT pe.device_id) as count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.client_clusters)
            WHERE p.vendor_fk = :vendor_fk
            GROUP BY cluster_id
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
        ', ['vendor_fk' => $vendorFk])->fetchAssociative();

        return [
            'total' => (int) $result['total'],
            'withBinding' => (int) $result['with_binding'],
            'percentage' => $result['total'] > 0
                ? round(($result['with_binding'] / $result['total']) * 100, 1)
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
        foreach ($rows as &$row) {
            $row['pairing_strength'] = round(((int) $row['shared_installations'] / $sourceInstallations) * 100, 1);
        }

        return $rows;
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
     * @return array<array{product_a: int, product_b: int, shared_installations: int, product_a_name: string, product_b_name: string}>
     */
    public function getTopProductPairings(int $limit = 20): array
    {
        return $this->db->executeQuery('
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
     * @return array<array{id: int, product_name: string, vendor_name: string, installation_count: int, unique_pairings: int}>
     */
    public function getMostConnectedProducts(int $limit = 10): array
    {
        return $this->db->executeQuery('
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
    }

    /**
     * Get vendor pairings - which vendors' products are commonly used together.
     *
     * @return array<array{vendor_a: string, vendor_b: string, shared_installations: int}>
     */
    public function getVendorPairings(int $limit = 15): array
    {
        return $this->db->executeQuery('
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
        // Get all device types per vendor with counts
        $rows = $this->db->executeQuery('
            SELECT
                p.vendor_fk,
                CAST(json_extract(json_each.value, "$.id") AS INTEGER) as device_type_id,
                COUNT(DISTINCT pe.device_id) as product_count
            FROM product_endpoints pe
            JOIN products p ON pe.device_id = p.id, json_each(pe.device_types)
            WHERE json_extract(json_each.value, "$.id") IS NOT NULL
            GROUP BY p.vendor_fk, device_type_id
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

        if (empty($topCategories)) {
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

            if (empty($categoryDeviceTypeIds)) {
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
        ')->fetchAssociative();

        // Get top 10 vendors' product count
        $top10Products = $this->db->executeQuery('
            SELECT COALESCE(SUM(device_count), 0) as top10_products
            FROM (
                SELECT device_count FROM vendors
                ORDER BY device_count DESC
                LIMIT 10
            )
        ')->fetchOne();

        $totalProducts = (int) $stats['total_products'];
        $totalVendors = (int) $stats['total_vendors'];
        $vendorsWithDevices = (int) $stats['vendors_with_devices'];

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
}
