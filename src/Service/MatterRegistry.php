<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Observability\RegistryLookupTracing;
use App\Observability\Tracer;
use App\Repository\ClusterRepository;
use App\Repository\ClusterVersionRepository;
use App\Repository\DeviceTypeRepository;

/**
 * Registry for Matter cluster and device type name lookups.
 *
 * All data is loaded from the database which contains comprehensive information
 * extracted from the Matter 1.4 Device Library Specification via YAML fixtures.
 */
class MatterRegistry
{
    /**
     * Device type data loaded from database.
     *
     * @var array<int, array>|null
     */
    private ?array $deviceTypes = null;

    /**
     * Cluster data loaded from database.
     *
     * @var array<int, array>|null
     */
    private ?array $clusters = null;

    /**
     * Cache-hit signal for the most recent lookup, set by `do*` methods that
     * consult the in-memory map directly. Read by `trace*Lookup` to populate
     * the `lookup.cache_hit` span attribute. Only meaningful when registry
     * lookup tracing is enabled.
     */
    private bool $lastLookupHitCache = false;

    public function __construct(
        private readonly ?DeviceTypeRepository $deviceTypeRepository = null,
        private readonly ?ClusterRepository $clusterRepository = null,
        private readonly ?ClusterVersionRepository $clusterVersionRepository = null,
    ) {
    }

    // ========================================================================
    // CLUSTER METHODS
    // ========================================================================

    /**
     * Get the display name for a cluster.
     */
    public function getClusterName(int $id): string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterName($id);
        }

        return $this->traceClusterLookup('getClusterName', $id, fn (): string => $this->doGetClusterName($id));
    }

    private function doGetClusterName(int $id): string
    {
        $clusters = $this->loadClusters();
        $this->lastLookupHitCache = isset($clusters[$id]);
        if (isset($clusters[$id])) {
            return $clusters[$id]['name'];
        }

        // Fallback to hex ID if not in database
        return \sprintf('Cluster 0x%04X', $id);
    }

    /**
     * Get full metadata for a cluster.
     *
     * @return array{id: int, hexId: string, name: string, description: ?string, specVersion: ?string, category: ?string, isGlobal: bool, attributes?: array<int, mixed>, commands?: array<int, mixed>, features?: array<int, array{bit: int, code: string, name: string, summary?: string}>}|null
     */
    public function getClusterMetadata(int $id): ?array
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterMetadata($id);
        }

        return $this->traceClusterLookup('getClusterMetadata', $id, fn (): ?array => $this->doGetClusterMetadata($id));
    }

    /**
     * @return array{id: int, hexId: string, name: string, description: ?string, specVersion: ?string, category: ?string, isGlobal: bool, attributes?: array<int, mixed>, commands?: array<int, mixed>, features?: array<int, array{bit: int, code: string, name: string, summary?: string}>}|null
     */
    private function doGetClusterMetadata(int $id): ?array
    {
        $clusters = $this->loadClusters();
        $this->lastLookupHitCache = isset($clusters[$id]);

        $cluster = $clusters[$id] ?? null;
        if (null === $cluster) {
            return null;
        }

        $metadata = [
            'id' => (int) $cluster['id'],
            'hexId' => (string) $cluster['hexId'],
            'name' => (string) $cluster['name'],
            'description' => null !== $cluster['description'] ? (string) $cluster['description'] : null,
            'specVersion' => null !== $cluster['specVersion'] ? (string) $cluster['specVersion'] : null,
            'category' => null !== $cluster['category'] ? (string) $cluster['category'] : null,
            'isGlobal' => (bool) $cluster['isGlobal'],
        ];

        if (isset($cluster['attributes']) && \is_array($cluster['attributes'])) {
            $metadata['attributes'] = $cluster['attributes'];
        }
        if (isset($cluster['commands']) && \is_array($cluster['commands'])) {
            $metadata['commands'] = $cluster['commands'];
        }
        if (isset($cluster['features']) && \is_array($cluster['features'])) {
            $metadata['features'] = $cluster['features'];
        }

        return $metadata;
    }

    /**
     * Get the description for a cluster.
     */
    public function getClusterDescription(int $id): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterDescription($id);
        }

        return $this->traceClusterLookup('getClusterDescription', $id, fn (): ?string => $this->doGetClusterDescription($id));
    }

    private function doGetClusterDescription(int $id): ?string
    {
        $cluster = $this->doGetClusterMetadata($id);

        return $cluster['description'] ?? null;
    }

    /**
     * Get the category for a cluster.
     */
    public function getClusterCategory(int $id): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterCategory($id);
        }

        return $this->traceClusterLookup('getClusterCategory', $id, fn (): ?string => $this->doGetClusterCategory($id));
    }

    private function doGetClusterCategory(int $id): ?string
    {
        $cluster = $this->doGetClusterMetadata($id);

        return $cluster['category'] ?? null;
    }

    /**
     * Check if a cluster is a global/utility cluster.
     */
    public function isGlobalCluster(int $id): bool
    {
        $cluster = $this->getClusterMetadata($id);

        return $cluster['isGlobal'] ?? false;
    }

    /**
     * Check if a cluster is proprietary/manufacturer-specific.
     * Per Matter spec, cluster IDs >= 0xFC00 (64512) are manufacturer-specific.
     */
    public function isProprietaryCluster(int $id): bool
    {
        return $id >= 0xFC00;
    }

    /**
     * Get the hex ID for a cluster.
     */
    public function getClusterHexId(int $id): string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterHexId($id);
        }

        return $this->traceClusterLookup('getClusterHexId', $id, fn (): string => $this->doGetClusterHexId($id));
    }

    private function doGetClusterHexId(int $id): string
    {
        $cluster = $this->doGetClusterMetadata($id);

        return $cluster['hexId'] ?? \sprintf('0x%04X', $id);
    }

    /**
     * Get all cluster names from database.
     *
     * @return array<int, string>
     */
    public function getAllClusterNames(): array
    {
        $clusters = $this->loadClusters();

        return array_map(fn (array $c) => $c['name'], $clusters);
    }

    /**
     * Get the name of a command within a cluster by its ID.
     */
    public function getClusterCommandName(int $clusterId, int $commandId): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterCommandName($clusterId, $commandId);
        }

        return $this->traceClusterLookup(
            'getClusterCommandName',
            $clusterId,
            fn (): ?string => $this->doGetClusterCommandName($clusterId, $commandId),
        );
    }

    private function doGetClusterCommandName(int $clusterId, int $commandId): ?string
    {
        $clusters = $this->loadClusters();
        $cluster = $clusters[$clusterId] ?? null;
        $this->lastLookupHitCache = null !== $cluster;

        if (!$cluster) {
            return null;
        }

        $commands = $cluster['commands'] ?? [];
        foreach ($commands as $cmd) {
            if (($cmd['code'] ?? null) === $commandId) {
                return $cmd['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Get the name of an attribute within a cluster by its ID.
     */
    public function getClusterAttributeName(int $clusterId, int $attributeId): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetClusterAttributeName($clusterId, $attributeId);
        }

        return $this->traceClusterLookup(
            'getClusterAttributeName',
            $clusterId,
            fn (): ?string => $this->doGetClusterAttributeName($clusterId, $attributeId),
        );
    }

    private function doGetClusterAttributeName(int $clusterId, int $attributeId): ?string
    {
        $clusters = $this->loadClusters();
        $cluster = $clusters[$clusterId] ?? null;
        $this->lastLookupHitCache = null !== $cluster;

        if (!$cluster) {
            return null;
        }

        $attributes = $cluster['attributes'] ?? [];
        foreach ($attributes as $attr) {
            if (($attr['code'] ?? null) === $attributeId) {
                return $attr['name'] ?? null;
            }
        }

        return null;
    }

    /**
     * Get the spec version for a cluster.
     */
    public function getClusterSpecVersion(int $clusterId): ?string
    {
        $metadata = $this->getClusterMetadata($clusterId);

        return $metadata['specVersion'] ?? null;
    }

    /**
     * Get all commands defined in the spec for a cluster.
     *
     * @return array<int, array{id: int, name: string, optional: bool}>
     */
    public function getClusterCommands(int $clusterId): array
    {
        $metadata = $this->getClusterMetadata($clusterId);

        if (!$metadata) {
            return [];
        }

        $commands = [];
        foreach ($metadata['commands'] ?? [] as $cmd) {
            $id = $cmd['code'] ?? $cmd['id'] ?? null;
            if (null === $id) {
                continue;
            }
            $commands[] = [
                'id' => (int) $id,
                'name' => $cmd['name'] ?? "Command {$id}",
                'optional' => (bool) ($cmd['optional'] ?? false),
            ];
        }

        return $commands;
    }

    /**
     * Get all attributes defined in the spec for a cluster.
     *
     * @return array<int, array{id: int, name: string, optional: bool}>
     */
    public function getClusterAttributes(int $clusterId): array
    {
        $metadata = $this->getClusterMetadata($clusterId);

        if (!$metadata) {
            return [];
        }

        $attributes = [];
        foreach ($metadata['attributes'] ?? [] as $attr) {
            $id = $attr['code'] ?? $attr['id'] ?? null;
            if (null === $id) {
                continue;
            }
            // Skip global attributes (>= 65528)
            if ((int) $id >= 65528) {
                continue;
            }
            $attributes[] = [
                'id' => (int) $id,
                'name' => $attr['name'] ?? "Attribute {$id}",
                'optional' => (bool) ($attr['optional'] ?? false),
            ];
        }

        return $attributes;
    }

    /**
     * Decode a feature_map bitmask into human-readable features.
     *
     * @return array<array{code: string, name: string, summary: string, enabled: bool}>
     */
    public function decodeFeatureMap(int $clusterId, int $featureMap): array
    {
        $cluster = $this->getClusterMetadata($clusterId);
        if (!$cluster || empty($cluster['features'])) {
            return [];
        }

        $decoded = [];
        foreach ($cluster['features'] as $feature) {
            $enabled = ($featureMap & (1 << $feature['bit'])) !== 0;
            $decoded[] = [
                'code' => $feature['code'],
                'name' => $feature['name'],
                'summary' => $feature['summary'] ?? '',
                'enabled' => $enabled,
            ];
        }

        return $decoded;
    }

    /**
     * Check if a specific feature is enabled in a feature_map.
     *
     * @param int    $clusterId   The cluster ID
     * @param string $featureCode The feature code (e.g., "HEAT", "SCH")
     * @param int    $featureMap  The bitmask value from the device
     */
    public function hasFeature(int $clusterId, string $featureCode, int $featureMap): bool
    {
        $cluster = $this->getClusterMetadata($clusterId);
        if (!$cluster || empty($cluster['features'])) {
            return false;
        }

        foreach ($cluster['features'] as $feature) {
            if ($feature['code'] === $featureCode) {
                return ($featureMap & (1 << $feature['bit'])) !== 0;
            }
        }

        return false;
    }

    /**
     * Get the names of enabled features from a feature_map.
     *
     * @return array<string> Array of feature names that are enabled
     */
    public function getEnabledFeatureNames(int $clusterId, int $featureMap): array
    {
        $decoded = $this->decodeFeatureMap($clusterId, $featureMap);
        $names = [];
        foreach ($decoded as $feature) {
            if ($feature['enabled']) {
                $names[] = $feature['name'];
            }
        }

        return $names;
    }

    /**
     * Load cluster data from database.
     *
     * Hand-curated metadata (name, description, category, isGlobal) comes from
     * the Cluster entity. Spec data (attributes, commands, features, apiMaturity,
     * clusterRevision) is preferred from the latest ClusterVersion row when
     * available — that's the upstream-authoritative source. Cluster's JSON
     * columns remain a fallback for clusters that don't yet have a tagged
     * snapshot (master-only drafts, or pre-backfill data).
     *
     * @return array<int, array>
     */
    private function loadClusters(): array
    {
        if (null !== $this->clusters) {
            return $this->clusters;
        }

        $this->clusters = [];

        if (!$this->clusterRepository instanceof ClusterRepository) {
            return $this->clusters;
        }

        $clusterEntities = $this->clusterRepository->findAll();
        $latestSnapshots = $this->loadLatestClusterSnapshots();
        $earliestVersions = $this->loadEarliestVersionPerCluster();

        foreach ($clusterEntities as $cluster) {
            $this->clusters[$cluster->getId()] = $this->clusterEntityToArray(
                $cluster,
                $latestSnapshots[$cluster->getId()] ?? null,
                $earliestVersions[$cluster->getId()] ?? null,
            );
        }

        return $this->clusters;
    }

    /**
     * @return array<int, \App\Entity\ClusterVersion>
     */
    private function loadLatestClusterSnapshots(): array
    {
        if (!$this->clusterVersionRepository instanceof ClusterVersionRepository) {
            return [];
        }

        $latestVersion = $this->clusterVersionRepository->findLatestMatterVersion();
        if (null === $latestVersion) {
            return [];
        }

        $byId = [];
        foreach ($this->clusterVersionRepository->findByMatterVersion($latestVersion) as $snapshot) {
            $byId[$snapshot->getClusterId()] = $snapshot;
        }

        return $byId;
    }

    /**
     * Map of cluster id → earliest matterVersion the cluster appears in. Excludes
     * "master" because that's the work-in-progress ref, not a released spec.
     *
     * @return array<int, string>
     */
    private function loadEarliestVersionPerCluster(): array
    {
        if (!$this->clusterVersionRepository instanceof ClusterVersionRepository) {
            return [];
        }

        $earliest = [];
        foreach ($this->clusterVersionRepository->findAll() as $row) {
            if ('master' === $row->getMatterVersion()) {
                continue;
            }
            $clusterId = $row->getClusterId();
            if (!isset($earliest[$clusterId]) || $row->getMatterVersion() < $earliest[$clusterId]) {
                $earliest[$clusterId] = $row->getMatterVersion();
            }
        }

        return $earliest;
    }

    /**
     * Convert a Cluster entity to array format. Spec data comes from the
     * matching ClusterVersion snapshot when present; clusters without a
     * snapshot return null for those fields (which is correct — they
     * haven't shipped in any tagged Matter release).
     */
    private function clusterEntityToArray(Cluster $cluster, ?\App\Entity\ClusterVersion $snapshot = null, ?string $earliestMatterVersion = null): array
    {
        return [
            'id' => $cluster->getId(),
            'hexId' => $cluster->getHexId(),
            'name' => $cluster->getName(),
            'description' => $cluster->getDescription(),
            'specVersion' => $earliestMatterVersion,
            'category' => $cluster->getCategory(),
            'isGlobal' => $cluster->isGlobal(),
            'attributes' => $snapshot?->getAttributes(),
            'commands' => $snapshot?->getCommands(),
            'features' => $snapshot?->getFeatures(),
            'apiMaturity' => $snapshot?->getApiMaturity(),
            'clusterRevision' => $snapshot?->getClusterRevision(),
        ];
    }

    // ========================================================================
    // DEVICE TYPE METHODS
    // ========================================================================

    /**
     * Get the display name for a device type.
     */
    public function getDeviceTypeName(int $id): string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetDeviceTypeName($id);
        }

        return $this->traceDeviceTypeLookup('getDeviceTypeName', $id, fn (): string => $this->doGetDeviceTypeName($id));
    }

    private function doGetDeviceTypeName(int $id): string
    {
        $deviceTypes = $this->loadDeviceTypes();
        $this->lastLookupHitCache = isset($deviceTypes[$id]);
        if (isset($deviceTypes[$id])) {
            return $deviceTypes[$id]['name'];
        }

        return "Device Type $id";
    }

    /**
     * Get full metadata for a device type.
     *
     * @return array{name: string, specVersion: ?string, category: ?string, displayCategory: ?string, icon: ?string, description: ?string, id?: int, hexId?: string, class?: string, scope?: string, superset?: string, mandatoryServerClusters?: array, optionalServerClusters?: array, mandatoryClientClusters?: array, optionalClientClusters?: array, scoringWeights?: array{mandatoryServerWeight: float, mandatoryClientWeight: float, optionalServerWeight: float, optionalClientWeight: float, keyClientClusters: int[], keyClientBonus: float}}|null
     */
    public function getDeviceTypeMetadata(int $id): ?array
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetDeviceTypeMetadata($id);
        }

        return $this->traceDeviceTypeLookup('getDeviceTypeMetadata', $id, fn (): ?array => $this->doGetDeviceTypeMetadata($id));
    }

    /**
     * @return array{name: string, specVersion: ?string, category: ?string, displayCategory: ?string, icon: ?string, description: ?string, id?: int, hexId?: string, class?: string, scope?: string, superset?: string, mandatoryServerClusters?: array, optionalServerClusters?: array, mandatoryClientClusters?: array, optionalClientClusters?: array, scoringWeights?: array{mandatoryServerWeight: float, mandatoryClientWeight: float, optionalServerWeight: float, optionalClientWeight: float, keyClientClusters: int[], keyClientBonus: float}}|null
     */
    private function doGetDeviceTypeMetadata(int $id): ?array
    {
        $deviceTypes = $this->loadDeviceTypes();
        $this->lastLookupHitCache = isset($deviceTypes[$id]);

        $deviceType = $deviceTypes[$id] ?? null;
        if (null === $deviceType) {
            return null;
        }

        $metadata = [
            'name' => (string) $deviceType['name'],
            'specVersion' => null !== $deviceType['specVersion'] ? (string) $deviceType['specVersion'] : null,
            'category' => null !== $deviceType['category'] ? (string) $deviceType['category'] : null,
            'displayCategory' => null !== $deviceType['displayCategory'] ? (string) $deviceType['displayCategory'] : null,
            'icon' => null !== $deviceType['icon'] ? (string) $deviceType['icon'] : null,
            'description' => null !== $deviceType['description'] ? (string) $deviceType['description'] : null,
        ];

        if (isset($deviceType['id'])) {
            $metadata['id'] = (int) $deviceType['id'];
        }
        if (isset($deviceType['hexId'])) {
            $metadata['hexId'] = (string) $deviceType['hexId'];
        }
        if (isset($deviceType['class'])) {
            $metadata['class'] = (string) $deviceType['class'];
        }
        if (isset($deviceType['scope'])) {
            $metadata['scope'] = (string) $deviceType['scope'];
        }
        if (isset($deviceType['superset'])) {
            $metadata['superset'] = (string) $deviceType['superset'];
        }
        if (isset($deviceType['mandatoryServerClusters']) && \is_array($deviceType['mandatoryServerClusters'])) {
            $metadata['mandatoryServerClusters'] = $deviceType['mandatoryServerClusters'];
        }
        if (isset($deviceType['optionalServerClusters']) && \is_array($deviceType['optionalServerClusters'])) {
            $metadata['optionalServerClusters'] = $deviceType['optionalServerClusters'];
        }
        if (isset($deviceType['mandatoryClientClusters']) && \is_array($deviceType['mandatoryClientClusters'])) {
            $metadata['mandatoryClientClusters'] = $deviceType['mandatoryClientClusters'];
        }
        if (isset($deviceType['optionalClientClusters']) && \is_array($deviceType['optionalClientClusters'])) {
            $metadata['optionalClientClusters'] = $deviceType['optionalClientClusters'];
        }
        if (isset($deviceType['scoringWeights']) && \is_array($deviceType['scoringWeights'])) {
            $weights = $deviceType['scoringWeights'];
            $keyClientClusters = [];
            if (isset($weights['keyClientClusters']) && \is_array($weights['keyClientClusters'])) {
                foreach ($weights['keyClientClusters'] as $cluster) {
                    $keyClientClusters[] = (int) $cluster;
                }
            }
            $metadata['scoringWeights'] = [
                'mandatoryServerWeight' => (float) ($weights['mandatoryServerWeight'] ?? 0.0),
                'mandatoryClientWeight' => (float) ($weights['mandatoryClientWeight'] ?? 0.0),
                'optionalServerWeight' => (float) ($weights['optionalServerWeight'] ?? 0.0),
                'optionalClientWeight' => (float) ($weights['optionalClientWeight'] ?? 0.0),
                'keyClientClusters' => $keyClientClusters,
                'keyClientBonus' => (float) ($weights['keyClientBonus'] ?? 0.0),
            ];
        }

        return $metadata;
    }

    /**
     * Get the Matter specification version for a device type.
     */
    public function getDeviceTypeSpecVersion(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['specVersion'] ?? null;
    }

    /**
     * Get the icon identifier for a device type.
     */
    public function getDeviceTypeIcon(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['icon'] ?? null;
    }

    /**
     * Get the description for a device type.
     */
    public function getDeviceTypeDescription(int $id): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetDeviceTypeDescription($id);
        }

        return $this->traceDeviceTypeLookup('getDeviceTypeDescription', $id, fn (): ?string => $this->doGetDeviceTypeDescription($id));
    }

    private function doGetDeviceTypeDescription(int $id): ?string
    {
        $deviceType = $this->doGetDeviceTypeMetadata($id);

        return $deviceType['description'] ?? null;
    }

    /**
     * Get the spec category for a device type (e.g., 'lighting', 'hvac', 'sensors').
     */
    public function getDeviceTypeCategory(int $id): ?string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetDeviceTypeCategory($id);
        }

        return $this->traceDeviceTypeLookup('getDeviceTypeCategory', $id, fn (): ?string => $this->doGetDeviceTypeCategory($id));
    }

    private function doGetDeviceTypeCategory(int $id): ?string
    {
        $deviceType = $this->doGetDeviceTypeMetadata($id);

        return $deviceType['category'] ?? null;
    }

    /**
     * Get the display category for a device type (e.g., 'Lights', 'Climate', 'Sensors').
     */
    public function getDeviceTypeDisplayCategory(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['displayCategory'] ?? null;
    }

    /**
     * Get all device types that belong to a specific spec category.
     *
     * @return array<int, array>
     */
    public function getDeviceTypesByCategory(string $category): array
    {
        $deviceTypes = $this->loadDeviceTypes();

        return array_filter(
            $deviceTypes,
            fn (array $meta): bool => ($meta['category'] ?? '') === $category
        );
    }

    /**
     * Get all device types that belong to a specific display category.
     *
     * @return array<int, array>
     */
    public function getDeviceTypesByDisplayCategory(string $displayCategory): array
    {
        $deviceTypes = $this->loadDeviceTypes();

        return array_filter(
            $deviceTypes,
            fn (array $meta): bool => ($meta['displayCategory'] ?? '') === $displayCategory
        );
    }

    /**
     * Get all device types introduced in a specific Matter specification version.
     *
     * @return array<int, array>
     */
    public function getDeviceTypesBySpecVersion(string $specVersion): array
    {
        $deviceTypes = $this->loadDeviceTypes();

        return array_filter(
            $deviceTypes,
            fn (array $meta): bool => ($meta['specVersion'] ?? '') === $specVersion
        );
    }

    /**
     * Get all unique spec categories.
     *
     * @return string[]
     */
    public function getAllCategories(): array
    {
        $deviceTypes = $this->loadDeviceTypes();
        $categories = array_unique(
            array_filter(array_column($deviceTypes, 'category'))
        );

        return array_values($categories);
    }

    /**
     * Get all unique display categories.
     *
     * @return string[]
     */
    public function getAllDisplayCategories(): array
    {
        $deviceTypes = $this->loadDeviceTypes();
        $categories = array_unique(
            array_filter(array_column($deviceTypes, 'displayCategory'))
        );

        return array_values($categories);
    }

    /**
     * Get all unique spec versions.
     *
     * @return string[]
     */
    public function getAllSpecVersions(): array
    {
        $deviceTypes = $this->loadDeviceTypes();
        $versions = array_unique(
            array_filter(array_column($deviceTypes, 'specVersion'))
        );
        usort($versions, static fn (string $a, string $b): int => version_compare($a, $b));

        return array_values($versions);
    }

    /**
     * Get all device type names.
     *
     * @return array<int, string>
     */
    public function getAllDeviceTypeNames(): array
    {
        $deviceTypes = $this->loadDeviceTypes();

        return array_map(fn (array $meta) => $meta['name'], $deviceTypes);
    }

    /**
     * Get all device type metadata.
     *
     * @return array<int, array>
     */
    public function getAllDeviceTypeMetadata(): array
    {
        return $this->loadDeviceTypes();
    }

    /**
     * Load device type data from database.
     *
     * @return array<int, array>
     */
    private function loadDeviceTypes(): array
    {
        if (null !== $this->deviceTypes) {
            return $this->deviceTypes;
        }

        $this->deviceTypes = [];

        if (!$this->deviceTypeRepository instanceof DeviceTypeRepository) {
            return $this->deviceTypes;
        }

        $deviceTypeEntities = $this->deviceTypeRepository->findAll();

        foreach ($deviceTypeEntities as $deviceType) {
            $this->deviceTypes[$deviceType->getId()] = $this->deviceTypeEntityToArray($deviceType);
        }

        return $this->deviceTypes;
    }

    /**
     * Convert a DeviceType entity to array format for basic metadata.
     */
    private function deviceTypeEntityToArray(DeviceType $deviceType): array
    {
        return [
            'id' => $deviceType->getId(),
            'hexId' => $deviceType->getHexId(),
            'name' => $deviceType->getName(),
            'description' => $deviceType->getDescription(),
            'specVersion' => $deviceType->getSpecVersion(),
            'category' => $deviceType->getCategory(),
            'displayCategory' => $deviceType->getDisplayCategory(),
            'icon' => $deviceType->getIcon(),
            'class' => $deviceType->getDeviceClass(),
            'scope' => $deviceType->getScope(),
            'superset' => $deviceType->getSuperset(),
            'mandatoryServerClusters' => $deviceType->getMandatoryServerClusters(),
            'optionalServerClusters' => $deviceType->getOptionalServerClusters(),
            'mandatoryClientClusters' => $deviceType->getMandatoryClientClusters(),
            'optionalClientClusters' => $deviceType->getOptionalClientClusters(),
            'scoringWeights' => $deviceType->getScoringWeightsWithDefaults(),
        ];
    }

    // ========================================================================
    // EXTENDED DEVICE TYPE METHODS (kept for backward compatibility)
    // ========================================================================

    /**
     * Get extended device type data including cluster requirements.
     *
     * @return array|null The full device type data with cluster information
     */
    public function getExtendedDeviceType(int $id): ?array
    {
        // Now the same as getDeviceTypeMetadata since we load everything
        return $this->getDeviceTypeMetadata($id);
    }

    /**
     * Get all extended device type data.
     *
     * @return array<int, array>
     */
    public function getAllExtendedDeviceTypes(): array
    {
        return $this->loadDeviceTypes();
    }

    /**
     * Get mandatory server clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getMandatoryServerClusters(int $deviceTypeId): array
    {
        $deviceType = $this->getDeviceTypeMetadata($deviceTypeId);

        return $deviceType['mandatoryServerClusters'] ?? [];
    }

    /**
     * Get optional server clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getOptionalServerClusters(int $deviceTypeId): array
    {
        $deviceType = $this->getDeviceTypeMetadata($deviceTypeId);

        return $deviceType['optionalServerClusters'] ?? [];
    }

    /**
     * Get mandatory client clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getMandatoryClientClusters(int $deviceTypeId): array
    {
        $deviceType = $this->getDeviceTypeMetadata($deviceTypeId);

        return $deviceType['mandatoryClientClusters'] ?? [];
    }

    /**
     * Get optional client clusters for a device type.
     *
     * @return array<array{id: int, name: string}>
     */
    public function getOptionalClientClusters(int $deviceTypeId): array
    {
        $deviceType = $this->getDeviceTypeMetadata($deviceTypeId);

        return $deviceType['optionalClientClusters'] ?? [];
    }

    /**
     * Get device type superset (parent device type name).
     */
    public function getDeviceTypeSuperset(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['superset'] ?? null;
    }

    /**
     * Get device type class (Simple, Utility, Node, Dynamic).
     */
    public function getDeviceTypeClass(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['class'] ?? null;
    }

    /**
     * Get device type scope (Endpoint, Node).
     */
    public function getDeviceTypeScope(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['scope'] ?? null;
    }

    /**
     * Get scoring weights for a device type.
     *
     * @return array{mandatoryServerWeight: float, mandatoryClientWeight: float, optionalServerWeight: float, optionalClientWeight: float, keyClientClusters: int[], keyClientBonus: float}
     */
    public function getDeviceTypeScoringWeights(int $id): array
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

        $defaults = [
            'mandatoryServerWeight' => 0.40,
            'mandatoryClientWeight' => 0.20,
            'optionalServerWeight' => 0.25,
            'optionalClientWeight' => 0.15,
            'keyClientClusters' => [],
            'keyClientBonus' => 0.0,
        ];

        if (null === $deviceType || !isset($deviceType['scoringWeights'])) {
            return $defaults;
        }

        return $deviceType['scoringWeights'];
    }

    /**
     * Get the hex ID string for a device type (e.g., "0x0100").
     */
    public function getDeviceTypeHexId(int $id): string
    {
        if (!RegistryLookupTracing::enabled()) {
            return $this->doGetDeviceTypeHexId($id);
        }

        return $this->traceDeviceTypeLookup('getDeviceTypeHexId', $id, fn (): string => $this->doGetDeviceTypeHexId($id));
    }

    private function doGetDeviceTypeHexId(int $id): string
    {
        $deviceType = $this->doGetDeviceTypeMetadata($id);

        return $deviceType['hexId'] ?? sprintf('0x%04X', $id);
    }

    /**
     * Check if data is available for a device type.
     */
    public function hasExtendedData(int $id): bool
    {
        return null !== $this->getDeviceTypeMetadata($id);
    }

    // ========================================================================
    // CLUSTER GAP ANALYSIS
    // ========================================================================

    /**
     * Cluster equivalents: legacy cluster ID => replacement cluster ID.
     * Devices implementing the legacy version should be considered compliant.
     */
    private const array CLUSTER_EQUIVALENTS = [
        5 => 98,   // Scenes (1.0) → Scenes Management (1.4)
    ];

    /**
     * Check if a required cluster is satisfied by the actual clusters.
     * Considers legacy/replacement equivalents.
     *
     * @param int   $requiredClusterId The cluster ID required by the spec
     * @param int[] $actualClusters    The clusters the device actually implements
     */
    private function isClusterSatisfied(int $requiredClusterId, array $actualClusters): bool
    {
        // Direct match
        if (\in_array($requiredClusterId, $actualClusters, true)) {
            return true;
        }

        // Check for legacy/replacement equivalents
        foreach (self::CLUSTER_EQUIVALENTS as $legacy => $replacement) {
            if ($requiredClusterId === $replacement && \in_array($legacy, $actualClusters, true)) {
                return true; // Has legacy version, counts as compliant
            }
            if ($requiredClusterId === $legacy && \in_array($replacement, $actualClusters, true)) {
                return true; // Has new version, counts as compliant
            }
        }

        return false;
    }

    /**
     * Get spec version note for a cluster if it was introduced after 1.0.
     */
    public function getClusterSpecNote(int $clusterId): ?string
    {
        $metadata = $this->getClusterMetadata($clusterId);
        if (null === $metadata) {
            return null;
        }

        $specVersion = $metadata['specVersion'] ?? '1.0';

        // Check if this is a replacement cluster
        foreach (self::CLUSTER_EQUIVALENTS as $legacy => $replacement) {
            if ($clusterId === $replacement) {
                $legacyMeta = $this->getClusterMetadata($legacy);
                $legacyName = $legacyMeta['name'] ?? "Cluster $legacy";

                return "Added in Matter $specVersion (replaces $legacyName)";
            }
        }

        if ('1.0' !== $specVersion) {
            return "Added in Matter $specVersion";
        }

        return null;
    }

    /**
     * Analyze a device's cluster implementation against the spec requirements.
     *
     * @param int   $deviceTypeId         The device type ID
     * @param int[] $actualServerClusters Server clusters the device actually implements
     * @param int[] $actualClientClusters Client clusters the device actually implements
     *
     * @return array{
     *     deviceType: array|null,
     *     missingMandatoryServer: array,
     *     missingMandatoryClient: array,
     *     missingOptionalServer: array,
     *     missingOptionalClient: array,
     *     implementedOptionalServer: array,
     *     implementedOptionalClient: array,
     *     extraServer: array,
     *     extraClient: array,
     *     compliance: array{mandatory: bool, score: float, mandatoryScore: float, optionalScore: float, totalMandatory: int, implementedMandatory: int, totalOptional: int, implementedOptional: int},
     *     specVersion: string,
     * }
     */
    public function analyzeClusterGaps(
        int $deviceTypeId,
        array $actualServerClusters,
        array $actualClientClusters,
    ): array {
        $deviceType = $this->getDeviceTypeMetadata($deviceTypeId);

        if (null === $deviceType) {
            return [
                'deviceType' => null,
                'missingMandatoryServer' => [],
                'missingMandatoryClient' => [],
                'missingOptionalServer' => [],
                'missingOptionalClient' => [],
                'implementedOptionalServer' => [],
                'implementedOptionalClient' => [],
                'extraServer' => [],
                'extraClient' => [],
                'compliance' => [
                    'mandatory' => true,
                    'score' => 100.0,
                    'mandatoryScore' => 100.0,
                    'optionalScore' => 0.0,
                    'totalMandatory' => 0,
                    'implementedMandatory' => 0,
                    'totalOptional' => 0,
                    'implementedOptional' => 0,
                ],
                'specVersion' => '1.0',
            ];
        }

        $specVersion = $deviceType['specVersion'] ?? '1.0';

        $mandatoryServer = $deviceType['mandatoryServerClusters'] ?? [];
        $mandatoryClient = $deviceType['mandatoryClientClusters'] ?? [];
        $optionalServer = $deviceType['optionalServerClusters'] ?? [];
        $optionalClient = $deviceType['optionalClientClusters'] ?? [];

        // Extract IDs for comparison
        $mandatoryServerIds = array_column($mandatoryServer, 'id');
        $mandatoryClientIds = array_column($mandatoryClient, 'id');
        $optionalServerIds = array_column($optionalServer, 'id');
        $optionalClientIds = array_column($optionalClient, 'id');

        // Find missing mandatory clusters (considering equivalents like Scenes → Scenes Management)
        $missingMandatoryServerIds = array_filter(
            $mandatoryServerIds,
            fn (int $id): bool => !$this->isClusterSatisfied($id, $actualServerClusters)
        );
        $missingMandatoryClientIds = array_filter(
            $mandatoryClientIds,
            fn (int $id): bool => !$this->isClusterSatisfied($id, $actualClientClusters)
        );

        // Find missing optional clusters
        $missingOptionalServerIds = array_diff($optionalServerIds, $actualServerClusters);
        $missingOptionalClientIds = array_diff($optionalClientIds, $actualClientClusters);

        // Find implemented optional clusters
        $implementedOptionalServerIds = array_intersect($optionalServerIds, $actualServerClusters);
        $implementedOptionalClientIds = array_intersect($optionalClientIds, $actualClientClusters);

        // Find extra clusters (not in spec for this device type)
        $allSpecServerIds = array_merge($mandatoryServerIds, $optionalServerIds);
        $allSpecClientIds = array_merge($mandatoryClientIds, $optionalClientIds);
        $extraServerIds = array_diff($actualServerClusters, $allSpecServerIds);
        $extraClientIds = array_diff($actualClientClusters, $allSpecClientIds);

        // Build result arrays with names
        $missingMandatoryServer = array_filter($mandatoryServer, fn (array $c): bool => \in_array($c['id'], $missingMandatoryServerIds, true));
        $missingMandatoryClient = array_filter($mandatoryClient, fn (array $c): bool => \in_array($c['id'], $missingMandatoryClientIds, true));
        $missingOptionalServer = array_filter($optionalServer, fn (array $c): bool => \in_array($c['id'], $missingOptionalServerIds, true));
        $missingOptionalClient = array_filter($optionalClient, fn (array $c): bool => \in_array($c['id'], $missingOptionalClientIds, true));
        $implementedOptionalServer = array_filter($optionalServer, fn (array $c): bool => \in_array($c['id'], $implementedOptionalServerIds, true));
        $implementedOptionalClient = array_filter($optionalClient, fn (array $c): bool => \in_array($c['id'], $implementedOptionalClientIds, true));

        // Build extra clusters with names
        $extraServer = array_map(fn (int $id): array => ['id' => $id, 'name' => $this->getClusterName($id)], array_values($extraServerIds));
        $extraClient = array_map(fn (int $id): array => ['id' => $id, 'name' => $this->getClusterName($id)], array_values($extraClientIds));

        // Calculate compliance
        $totalMandatory = \count($mandatoryServerIds) + \count($mandatoryClientIds);
        $missingMandatory = \count($missingMandatoryServerIds) + \count($missingMandatoryClientIds);
        $mandatoryCompliant = 0 === $missingMandatory;

        // Score: mandatory compliance + optional implementation bonus
        $totalOptional = \count($optionalServerIds) + \count($optionalClientIds);
        $implementedOptional = \count($implementedOptionalServerIds) + \count($implementedOptionalClientIds);

        $mandatoryScore = $totalMandatory > 0 ? (($totalMandatory - $missingMandatory) / $totalMandatory) * 100 : 100;
        $optionalScore = $totalOptional > 0 ? ($implementedOptional / $totalOptional) * 100 : 0;

        // Weighted score: 70% mandatory, 30% optional
        $overallScore = ($mandatoryScore * 0.7) + ($optionalScore * 0.3);

        return [
            'deviceType' => $deviceType,
            'missingMandatoryServer' => array_values($missingMandatoryServer),
            'missingMandatoryClient' => array_values($missingMandatoryClient),
            'missingOptionalServer' => array_values($missingOptionalServer),
            'missingOptionalClient' => array_values($missingOptionalClient),
            'implementedOptionalServer' => array_values($implementedOptionalServer),
            'implementedOptionalClient' => array_values($implementedOptionalClient),
            'extraServer' => $extraServer,
            'extraClient' => $extraClient,
            'compliance' => [
                'mandatory' => $mandatoryCompliant,
                'score' => round($overallScore, 1),
                'mandatoryScore' => round($mandatoryScore, 1),
                'optionalScore' => round($optionalScore, 1),
                'totalMandatory' => $totalMandatory,
                'implementedMandatory' => $totalMandatory - $missingMandatory,
                'totalOptional' => $totalOptional,
                'implementedOptional' => $implementedOptional,
            ],
            'specVersion' => $specVersion,
        ];
    }

    /**
     * Analyze cluster gaps for a device across all its device types/endpoints.
     *
     * @param array<array<string, mixed>> $endpoints Endpoint data from database
     *
     * @return array<int, array> Analysis results keyed by device type ID
     */
    public function analyzeDeviceClusterGaps(array $endpoints): array
    {
        $analyses = [];

        foreach ($endpoints as $endpoint) {
            /** @var int[] $serverClusters */
            $serverClusters = $endpoint['server_clusters'] ?? [];
            /** @var int[] $clientClusters */
            $clientClusters = $endpoint['client_clusters'] ?? [];
            /** @var array<int|array{id?: int}> $deviceTypes */
            $deviceTypes = $endpoint['device_types'] ?? [];

            foreach ($deviceTypes as $dt) {
                $deviceTypeId = \is_array($dt) ? ($dt['id'] ?? null) : $dt;
                if (null === $deviceTypeId) {
                    continue;
                }

                // Skip system device types (Root Node, etc.) - they're not interesting for gap analysis
                if ($deviceTypeId < 256) {
                    continue;
                }

                if (!isset($analyses[$deviceTypeId])) {
                    $analyses[$deviceTypeId] = $this->analyzeClusterGaps(
                        (int) $deviceTypeId,
                        $serverClusters,
                        $clientClusters
                    );
                }
            }
        }

        return $analyses;
    }

    // ========================================================================
    // REGISTRY LOOKUP TRACING (opt-in via OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED)
    // ========================================================================

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function traceClusterLookup(string $method, int $clusterId, callable $fn): mixed
    {
        $clusters = $this->loadClusters();
        $hexId = $clusters[$clusterId]['hexId'] ?? \sprintf('0x%04X', $clusterId);

        $span = Tracer::start('matter_registry.lookup', [
            'lookup.kind' => 'cluster',
            'lookup.method' => $method,
            'cluster.hex_id' => $hexId,
        ]);
        try {
            $result = $fn();
            $span->setAttribute('lookup.cache_hit', $this->lastLookupHitCache);

            return $result;
        } finally {
            $span->end();
        }
    }

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    private function traceDeviceTypeLookup(string $method, int $deviceTypeId, callable $fn): mixed
    {
        $deviceTypes = $this->loadDeviceTypes();
        $hexId = $deviceTypes[$deviceTypeId]['hexId'] ?? \sprintf('0x%04X', $deviceTypeId);

        $span = Tracer::start('matter_registry.lookup', [
            'lookup.kind' => 'device_type',
            'lookup.method' => $method,
            'device_type.hex_id' => $hexId,
        ]);
        try {
            $result = $fn();
            $span->setAttribute('lookup.cache_hit', $this->lastLookupHitCache);

            return $result;
        } finally {
            $span->end();
        }
    }
}
