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
     * @var array<int, array>|null
     */
    private ?array $deviceTypes = null;

    /**
     * Cluster data loaded from database.
     * @var array<int, array>|null
     */
    private ?array $clusters = null;

    public function __construct(
        private ?DeviceTypeRepository $deviceTypeRepository = null,
        private ?ClusterRepository $clusterRepository = null,
    ) {}

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
        return array_map(fn(array $c) => $c['name'], $clusters);
    }

    /**
     * Load cluster data from database.
     *
     * @return array<int, array>
     */
    private function loadClusters(): array
    {
        if ($this->clusters !== null) {
            return $this->clusters;
        }

        $this->clusters = [];

        if ($this->clusterRepository === null) {
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
     * @return array{name: string, specVersion: ?string, category: ?string, displayCategory: ?string, icon: ?string, description: ?string}|null
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
            fn(array $meta) => ($meta['category'] ?? '') === $category
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
            fn(array $meta) => ($meta['displayCategory'] ?? '') === $displayCategory
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
            fn(array $meta) => ($meta['specVersion'] ?? '') === $specVersion
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
        return array_map(fn(array $meta) => $meta['name'], $deviceTypes);
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
        if ($this->deviceTypes !== null) {
            return $this->deviceTypes;
        }

        $this->deviceTypes = [];

        if ($this->deviceTypeRepository === null) {
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
        return $this->getDeviceTypeMetadata($id) !== null;
    }
}
