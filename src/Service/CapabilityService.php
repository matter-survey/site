<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\Yaml\Yaml;

/**
 * Translates Matter clusters into human-friendly device capabilities.
 *
 * This service maps technical cluster IDs to user-understandable features like
 * "Brightness dimming" or "Temperature sensor", making device capabilities
 * accessible to non-technical users.
 */
class CapabilityService
{
    /** @var array<string, mixed>|null */
    private ?array $capabilityData = null;

    private string $fixturesPath;

    public function __construct(string $projectDir)
    {
        $this->fixturesPath = $projectDir.'/fixtures/capabilities.yaml';
    }

    /**
     * Analyze device endpoints and return human-friendly capabilities.
     *
     * @param array<int, array{
     *     endpoint_id: int,
     *     device_types: array<int, array{id: int}|int>,
     *     server_clusters: array<int, int>,
     *     client_clusters: array<int, int>
     * }> $endpoints
     *
     * @return array{
     *     supported: array<string, array{label: string, emoji: string, icon: string, description: string, category: string}>,
     *     unsupported: array<string, array{label: string, emoji: string, icon: string, description: string, category: string}>,
     *     byCategory: array<string, array{supported: array<string, mixed>, unsupported: array<string, mixed>}>,
     *     summary: array{total: int, supported: int, percentage: int},
     *     standouts: array<int, string>,
     *     missing: array<int, string>
     * }
     */
    public function analyzeCapabilities(array $endpoints): array
    {
        $data = $this->loadCapabilityData();
        $capabilities = $data['capabilities'] ?? [];
        $categories = $data['categories'] ?? [];

        // Collect all clusters from all endpoints
        $serverClusters = [];
        $clientClusters = [];
        $deviceTypeCategory = null;

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['server_clusters'] as $clusterId) {
                $serverClusters[$clusterId] = true;
            }
            foreach ($endpoint['client_clusters'] as $clusterId) {
                $clientClusters[$clusterId] = true;
            }

            // Try to determine device type category from first non-system endpoint
            if (null === $deviceTypeCategory && $endpoint['endpoint_id'] > 0) {
                $deviceTypeCategory = $this->inferCategoryFromDeviceTypes($endpoint['device_types']);
            }
        }

        // Determine which capabilities are relevant for this device type
        $relevantCapabilities = $this->getRelevantCapabilities($deviceTypeCategory);

        $supported = [];
        $unsupported = [];
        $byCategory = [];

        // Initialize categories
        foreach ($categories as $catKey => $catLabel) {
            $byCategory[$catKey] = [
                'label' => $catLabel,
                'supported' => [],
                'unsupported' => [],
            ];
        }

        // Check each capability
        foreach ($capabilities as $capKey => $capability) {
            // Skip capabilities not relevant to this device type
            if (!empty($relevantCapabilities) && !\in_array($capKey, $relevantCapabilities, true)) {
                continue;
            }

            $isSupported = $this->checkCapabilitySupport(
                $capability,
                $serverClusters,
                $clientClusters
            );

            $capInfo = [
                'key' => $capKey,
                'label' => $capability['label'],
                'emoji' => $capability['emoji'] ?? '',
                'icon' => $capability['icon'] ?? '',
                'description' => $capability['description'] ?? '',
                'category' => $capability['category'] ?? 'other',
            ];

            $category = $capability['category'] ?? 'other';

            if ($isSupported) {
                $supported[$capKey] = $capInfo;
                if (isset($byCategory[$category])) {
                    $byCategory[$category]['supported'][$capKey] = $capInfo;
                }
            } else {
                $unsupported[$capKey] = $capInfo;
                if (isset($byCategory[$category])) {
                    $byCategory[$category]['unsupported'][$capKey] = $capInfo;
                }
            }
        }

        // Remove empty categories
        $byCategory = array_filter($byCategory, function ($cat) {
            return !empty($cat['supported']) || !empty($cat['unsupported']);
        });

        // Calculate summary
        $total = \count($supported) + \count($unsupported);
        $supportedCount = \count($supported);
        $percentage = $total > 0 ? (int) round(($supportedCount / $total) * 100) : 0;

        // Identify standouts (rare capabilities this device has)
        $standouts = $this->identifyStandouts($supported, $deviceTypeCategory);

        // Identify notable missing features
        $missing = $this->identifyMissing($unsupported, $deviceTypeCategory);

        return [
            'supported' => $supported,
            'unsupported' => $unsupported,
            'byCategory' => $byCategory,
            'summary' => [
                'total' => $total,
                'supported' => $supportedCount,
                'percentage' => $percentage,
            ],
            'standouts' => $standouts,
            'missing' => $missing,
            'deviceCategory' => $deviceTypeCategory,
        ];
    }

    /**
     * Check if a capability is supported based on cluster presence.
     *
     * @param array<string, mixed> $capability
     * @param array<int, bool>     $serverClusters
     * @param array<int, bool>     $clientClusters
     */
    private function checkCapabilitySupport(
        array $capability,
        array $serverClusters,
        array $clientClusters,
    ): bool {
        $clusters = $capability['clusters'] ?? [];

        if (empty($clusters)) {
            return false;
        }

        // For most capabilities, ANY matching cluster means it's supported
        foreach ($clusters as $clusterDef) {
            $clusterId = $clusterDef['id'] ?? null;
            $role = $clusterDef['role'] ?? 'server';

            if (null === $clusterId) {
                continue;
            }

            $hasCluster = 'client' === $role
                ? isset($clientClusters[$clusterId])
                : isset($serverClusters[$clusterId]);

            if ($hasCluster) {
                // TODO: Check features if specified
                // For now, just having the cluster is enough
                return true;
            }
        }

        return false;
    }

    /**
     * Infer the device category from device types.
     *
     * @param array<int, array{id: int}|int> $deviceTypes
     */
    private function inferCategoryFromDeviceTypes(array $deviceTypes): ?string
    {
        // Map device type IDs to categories
        $deviceTypeCategories = [
            // Lighting
            256 => 'lighting',  // On/Off Light
            257 => 'lighting',  // Dimmable Light
            268 => 'lighting',  // Color Temperature Light
            269 => 'lighting',  // Extended Color Light
            271 => 'lighting',  // Mounted On/Off Control
            272 => 'lighting',  // Mounted Dimmable Load Control

            // Plugs/Outlets
            266 => 'plugs',     // On/Off Plug-in Unit
            267 => 'plugs',     // Dimmable Plug-In Unit

            // Switches
            259 => 'switches',  // On/Off Light Switch
            260 => 'switches',  // Dimmer Switch
            261 => 'switches',  // Color Dimmer Switch
            15 => 'switches',   // Generic Switch

            // Sensors
            262 => 'sensors',   // Motion Sensor (Occupancy)
            263 => 'sensors',   // Contact Sensor
            770 => 'sensors',   // Temperature Sensor
            775 => 'sensors',   // Humidity Sensor
            21 => 'sensors',    // Light Sensor
            1026 => 'sensors',  // Flow Sensor

            // Climate
            769 => 'climate',   // Thermostat
            43 => 'climate',    // Fan
            114 => 'climate',   // Room Air Conditioner

            // Locks
            10 => 'locks',      // Door Lock
            11 => 'locks',      // Door Lock Controller

            // Window Coverings
            514 => 'window_coverings',  // Window Covering

            // Media
            40 => 'media',      // Basic Video Player
            35 => 'media',      // Casting Video Player
            34 => 'media',      // Speaker
            36 => 'media',      // Content App

            // Safety
            17 => 'safety',     // Smoke/CO Alarm
        ];

        foreach ($deviceTypes as $dt) {
            $dtId = \is_array($dt) ? $dt['id'] : $dt;
            if (isset($deviceTypeCategories[$dtId])) {
                return $deviceTypeCategories[$dtId];
            }
        }

        return null;
    }

    /**
     * Get capabilities relevant to a device category.
     *
     * @return array<int, string>
     */
    private function getRelevantCapabilities(?string $category): array
    {
        if (null === $category) {
            return []; // Show all capabilities
        }

        $data = $this->loadCapabilityData();
        $mapping = $data['device_type_relevant_capabilities'] ?? [];

        return $mapping[$category] ?? [];
    }

    /**
     * Identify standout capabilities (rare/notable features).
     *
     * @param array<string, array<string, mixed>> $supported
     *
     * @return array<int, string>
     */
    private function identifyStandouts(array $supported, ?string $category): array
    {
        // Capabilities that are notable when present
        $notableCapabilities = [
            'energy_monitoring' => 'Energy monitoring',
            'binding' => 'Device-to-device control',
            'full_color' => 'Full RGB color',
            'occupancy_response' => 'Responds to motion sensors',
            'air_quality_sensing' => 'Air quality monitoring',
            'scheduling' => 'Built-in scheduling',
        ];

        $standouts = [];
        foreach ($notableCapabilities as $key => $label) {
            if (isset($supported[$key])) {
                $standouts[] = $supported[$key]['label'];
            }
        }

        return \array_slice($standouts, 0, 3); // Max 3 standouts
    }

    /**
     * Identify notable missing capabilities.
     *
     * @param array<string, array<string, mixed>> $unsupported
     *
     * @return array<int, string>
     */
    private function identifyMissing(array $unsupported, ?string $category): array
    {
        // Capabilities that are notable when missing (by category)
        $notableMissing = [
            'lighting' => ['full_color', 'dimming', 'energy_monitoring'],
            'plugs' => ['energy_monitoring', 'dimming'],
            'climate' => ['humidity_sensing', 'scheduling'],
            'locks' => ['pin_codes', 'user_management'],
        ];

        $missing = [];
        $toCheck = $notableMissing[$category] ?? ['binding', 'energy_monitoring'];

        foreach ($toCheck as $key) {
            if (isset($unsupported[$key])) {
                $missing[] = $unsupported[$key]['label'];
            }
        }

        return \array_slice($missing, 0, 3); // Max 3 missing
    }

    /**
     * Load capability definitions from YAML.
     *
     * @return array<string, mixed>
     */
    private function loadCapabilityData(): array
    {
        if (null === $this->capabilityData) {
            if (file_exists($this->fixturesPath)) {
                $this->capabilityData = Yaml::parseFile($this->fixturesPath);
            } else {
                $this->capabilityData = ['capabilities' => [], 'categories' => []];
            }
        }

        return $this->capabilityData;
    }

    /**
     * Get all category labels.
     *
     * @return array<string, string>
     */
    public function getCategories(): array
    {
        $data = $this->loadCapabilityData();

        return $data['categories'] ?? [];
    }
}
