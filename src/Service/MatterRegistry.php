<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Repository\ClusterRepository;
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

    public function __construct(
        private ?DeviceTypeRepository $deviceTypeRepository = null,
        private ?ClusterRepository $clusterRepository = null,
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
        $clusters = $this->loadClusters();
        if (isset($clusters[$id])) {
            return $clusters[$id]['name'];
        }

        // Fallback to hex ID if not in database
        return \sprintf('Cluster 0x%04X', $id);
    }

    /**
     * Get full metadata for a cluster.
     *
     * @return array{id: int, hexId: string, name: string, description: ?string, specVersion: ?string, category: ?string, isGlobal: bool}|null
     */
    public function getClusterMetadata(int $id): ?array
    {
        $clusters = $this->loadClusters();

        return $clusters[$id] ?? null;
    }

    /**
     * Get the description for a cluster.
     */
    public function getClusterDescription(int $id): ?string
    {
        $cluster = $this->getClusterMetadata($id);

        return $cluster['description'] ?? null;
    }

    /**
     * Get the category for a cluster.
     */
    public function getClusterCategory(int $id): ?string
    {
        $cluster = $this->getClusterMetadata($id);

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
     * Get the hex ID for a cluster.
     */
    public function getClusterHexId(int $id): string
    {
        $cluster = $this->getClusterMetadata($id);

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
     * Load cluster data from database.
     *
     * @return array<int, array>
     */
    private function loadClusters(): array
    {
        if (null !== $this->clusters) {
            return $this->clusters;
        }

        $this->clusters = [];

        if (null === $this->clusterRepository) {
            return $this->clusters;
        }

        $clusterEntities = $this->clusterRepository->findAll();

        foreach ($clusterEntities as $cluster) {
            $this->clusters[$cluster->getId()] = $this->clusterEntityToArray($cluster);
        }

        return $this->clusters;
    }

    /**
     * Convert a Cluster entity to array format.
     */
    private function clusterEntityToArray(Cluster $cluster): array
    {
        return [
            'id' => $cluster->getId(),
            'hexId' => $cluster->getHexId(),
            'name' => $cluster->getName(),
            'description' => $cluster->getDescription(),
            'specVersion' => $cluster->getSpecVersion(),
            'category' => $cluster->getCategory(),
            'isGlobal' => $cluster->isGlobal(),
            'attributes' => $cluster->getAttributes(),
            'commands' => $cluster->getCommands(),
            'features' => $cluster->getFeatures(),
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
        $deviceTypes = $this->loadDeviceTypes();
        if (isset($deviceTypes[$id])) {
            return $deviceTypes[$id]['name'];
        }

        return "Device Type $id";
    }

    /**
     * Get full metadata for a device type.
     *
     * @return array{name: string, specVersion: ?string, category: ?string, displayCategory: ?string, icon: ?string, description: ?string, id?: int, hexId?: string, class?: string, scope?: string, superset?: string, mandatoryServerClusters?: array, optionalServerClusters?: array, mandatoryClientClusters?: array, optionalClientClusters?: array}|null
     */
    public function getDeviceTypeMetadata(int $id): ?array
    {
        $deviceTypes = $this->loadDeviceTypes();

        return $deviceTypes[$id] ?? null;
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
        $deviceType = $this->getDeviceTypeMetadata($id);

        return $deviceType['description'] ?? null;
    }

    /**
     * Get the spec category for a device type (e.g., 'lighting', 'hvac', 'sensors').
     */
    public function getDeviceTypeCategory(int $id): ?string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

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
            fn (array $meta) => ($meta['category'] ?? '') === $category
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
            fn (array $meta) => ($meta['displayCategory'] ?? '') === $displayCategory
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
            fn (array $meta) => ($meta['specVersion'] ?? '') === $specVersion
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
        usort($versions, 'version_compare');

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

        if (null === $this->deviceTypeRepository) {
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
     * Get the hex ID string for a device type (e.g., "0x0100").
     */
    public function getDeviceTypeHexId(int $id): string
    {
        $deviceType = $this->getDeviceTypeMetadata($id);

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
    private const CLUSTER_EQUIVALENTS = [
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
            fn (int $id) => !$this->isClusterSatisfied($id, $actualServerClusters)
        );
        $missingMandatoryClientIds = array_filter(
            $mandatoryClientIds,
            fn (int $id) => !$this->isClusterSatisfied($id, $actualClientClusters)
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
        $missingMandatoryServer = array_filter($mandatoryServer, fn ($c) => \in_array($c['id'], $missingMandatoryServerIds, true));
        $missingMandatoryClient = array_filter($mandatoryClient, fn ($c) => \in_array($c['id'], $missingMandatoryClientIds, true));
        $missingOptionalServer = array_filter($optionalServer, fn ($c) => \in_array($c['id'], $missingOptionalServerIds, true));
        $missingOptionalClient = array_filter($optionalClient, fn ($c) => \in_array($c['id'], $missingOptionalClientIds, true));
        $implementedOptionalServer = array_filter($optionalServer, fn ($c) => \in_array($c['id'], $implementedOptionalServerIds, true));
        $implementedOptionalClient = array_filter($optionalClient, fn ($c) => \in_array($c['id'], $implementedOptionalClientIds, true));

        // Build extra clusters with names
        $extraServer = array_map(fn ($id) => ['id' => $id, 'name' => $this->getClusterName($id)], array_values($extraServerIds));
        $extraClient = array_map(fn ($id) => ['id' => $id, 'name' => $this->getClusterName($id)], array_values($extraClientIds));

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
}
