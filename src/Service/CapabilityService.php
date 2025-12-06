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

    public function __construct(
        string $projectDir,
        private MatterRegistry $matterRegistry,
    ) {
        $this->fixturesPath = $projectDir.'/fixtures/capabilities.yaml';
    }

    /**
     * Analyze device endpoints and return human-friendly capabilities.
     *
     * @param array<int, array{
     *     endpoint_id: int,
     *     device_types: array<int, array{id: int}|int>,
     *     server_clusters: array<int, int>,
     *     client_clusters: array<int, int>,
     *     server_cluster_details?: array<int, array{id: int, feature_map?: int}>
     * }> $endpoints
     *
     * @return array{
     *     supported: array<string, array{key: string, label: string, emoji: string, icon: string, description: string, category: string}>,
     *     unsupported: array<string, array{key: string, label: string, emoji: string, icon: string, description: string, category: string}>,
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
        $serverClusterDetails = []; // V3 telemetry: clusterId => {feature_map, ...}
        $deviceTypeCategory = null;

        foreach ($endpoints as $endpoint) {
            foreach ($endpoint['server_clusters'] as $clusterId) {
                $serverClusters[$clusterId] = true;
            }
            foreach ($endpoint['client_clusters'] as $clusterId) {
                $clientClusters[$clusterId] = true;
            }

            // Build cluster details lookup from V3 telemetry (if available)
            foreach ($endpoint['server_cluster_details'] ?? [] as $detail) {
                $serverClusterDetails[$detail['id']] = $detail;
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
                $clientClusters,
                $serverClusterDetails
            );

            // Get spec version from the capability's primary cluster
            $specVersion = null;
            $clusterDefs = $capability['clusters'] ?? [];
            if (!empty($clusterDefs)) {
                $primaryClusterId = $clusterDefs[0]['id'] ?? null;
                if (null !== $primaryClusterId) {
                    $specVersion = $this->matterRegistry->getClusterSpecVersion($primaryClusterId);
                }
            }

            $capInfo = [
                'key' => $capKey,
                'label' => $capability['label'],
                'emoji' => $capability['emoji'] ?? '',
                'icon' => $capability['icon'] ?? '',
                'description' => $capability['description'] ?? '',
                'category' => $capability['category'] ?? 'other',
                'specVersion' => $specVersion,
                'details' => null,
            ];

            $category = $capability['category'] ?? 'other';

            if ($isSupported) {
                // Enrich with cluster details for expandable view
                $capInfo['details'] = $this->getCapabilityClusterDetails(
                    $capability,
                    $serverClusters,
                    $clientClusters,
                    $serverClusterDetails
                );
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
     * Check if a capability is supported based on cluster presence and feature flags.
     *
     * @param array<string, mixed>             $capability
     * @param array<int, bool>                 $serverClusters
     * @param array<int, bool>                 $clientClusters
     * @param array<int, array<string, mixed>> $serverClusterDetails V3 telemetry cluster details
     */
    private function checkCapabilitySupport(
        array $capability,
        array $serverClusters,
        array $clientClusters,
        array $serverClusterDetails = [],
    ): bool {
        $clusters = $capability['clusters'] ?? [];

        if (empty($clusters)) {
            return false;
        }

        // For most capabilities, ANY matching cluster means it's supported
        foreach ($clusters as $clusterDef) {
            $clusterId = $clusterDef['id'] ?? null;
            $role = $clusterDef['role'] ?? 'server';
            $requiredFeatures = $clusterDef['features'] ?? [];

            if (null === $clusterId) {
                continue;
            }

            $hasCluster = 'client' === $role
                ? isset($clientClusters[$clusterId])
                : isset($serverClusters[$clusterId]);

            if (!$hasCluster) {
                continue;
            }

            // If no specific features required, having the cluster is enough
            if (empty($requiredFeatures)) {
                return true;
            }

            // Check if we have V3 telemetry details with feature_map
            $details = $serverClusterDetails[$clusterId] ?? null;
            if (null === $details || !isset($details['feature_map'])) {
                // V2 fallback: can't verify features, so assume supported if cluster exists
                return true;
            }

            $featureMap = (int) $details['feature_map'];

            // Check if ANY of the required features are enabled (OR logic)
            foreach ($requiredFeatures as $featureCode) {
                if ($this->matterRegistry->hasFeature($clusterId, $featureCode, $featureMap)) {
                    return true;
                }
            }

            // Has cluster but missing required features - continue checking other cluster definitions
        }

        return false;
    }

    /**
     * Get detailed cluster information for a capability's expandable view.
     *
     * Compares the Matter spec (all possible commands/attributes) against
     * what the device actually implements, marking each as implemented or not.
     *
     * @param array<string, mixed>             $capability           The capability definition from YAML
     * @param array<int, bool>                 $serverClusters       Map of available server cluster IDs
     * @param array<int, bool>                 $clientClusters       Map of available client cluster IDs
     * @param array<int, array<string, mixed>> $serverClusterDetails V3 telemetry with accepted_command_list, attribute_list
     *
     * @return array{
     *     clusterId: int,
     *     clusterName: string,
     *     specVersion: ?string,
     *     actions: array<int, array{id: int, technical: string, friendly: string, implemented: bool, optional: bool}>,
     *     statuses: array<int, array{id: int, technical: string, friendly: string, implemented: bool, optional: bool}>,
     *     features: array<int, array{name: string, enabled: bool}>
     * }|null
     */
    private function getCapabilityClusterDetails(
        array $capability,
        array $serverClusters,
        array $clientClusters,
        array $serverClusterDetails,
    ): ?array {
        $clusters = $capability['clusters'] ?? [];

        if (empty($clusters)) {
            return null;
        }

        // Find the first matching cluster that is present
        foreach ($clusters as $clusterDef) {
            $clusterId = $clusterDef['id'] ?? null;
            $role = $clusterDef['role'] ?? 'server';

            if (null === $clusterId) {
                continue;
            }

            $hasCluster = 'client' === $role
                ? isset($clientClusters[$clusterId])
                : isset($serverClusters[$clusterId]);

            if (!$hasCluster) {
                continue;
            }

            // Found a matching cluster - build the details
            $clusterName = $this->matterRegistry->getClusterName($clusterId);
            $specVersion = $this->matterRegistry->getClusterSpecVersion($clusterId);
            $actions = [];
            $statuses = [];
            $features = [];

            // Get telemetry data if available (V3)
            $telemetryDetails = $serverClusterDetails[$clusterId] ?? null;
            $acceptedCommands = $telemetryDetails['accepted_command_list'] ?? [];
            $attributeList = $telemetryDetails['attribute_list'] ?? [];
            $featureMap = $telemetryDetails['feature_map'] ?? null;

            // Convert to lookup maps for quick checking
            $implementedCommands = array_flip(array_map('intval', $acceptedCommands));
            $implementedAttributes = array_flip(array_map('intval', $attributeList));

            // Build friendly action mappings from capability definition
            $friendlyActions = [];
            foreach ($capability['actions'] ?? [] as $actionDef) {
                if (isset($actionDef['cmd'])) {
                    $friendlyActions[(int) $actionDef['cmd']] = $actionDef['friendly'] ?? '';
                }
            }

            // Build friendly status mappings from capability definition
            $friendlyStatuses = [];
            foreach ($capability['statuses'] ?? [] as $statusDef) {
                if (isset($statusDef['attr'])) {
                    $friendlyStatuses[(int) $statusDef['attr']] = $statusDef['friendly'] ?? '';
                }
            }

            // Get all commands from the spec and compare against implementation
            $specCommands = $this->matterRegistry->getClusterCommands($clusterId);
            $hasV3Telemetry = !empty($acceptedCommands);

            if (!empty($specCommands)) {
                // We have spec data - show full spec with implementation status
                foreach ($specCommands as $cmd) {
                    $cmdId = $cmd['id'];
                    $technicalName = $cmd['name'];
                    $friendlyName = $friendlyActions[$cmdId] ?? $this->humanizeTechnicalName($technicalName);
                    $isOptional = $cmd['optional'];

                    // Check if implemented (only if we have V3 telemetry)
                    $implemented = $hasV3Telemetry ? isset($implementedCommands[$cmdId]) : null;

                    $actions[] = [
                        'id' => $cmdId,
                        'technical' => $technicalName,
                        'friendly' => $friendlyName,
                        'implemented' => $implemented,
                        'optional' => $isOptional,
                    ];
                }
            } elseif ($hasV3Telemetry) {
                // No spec data but have telemetry - show only implemented commands
                foreach ($acceptedCommands as $cmdId) {
                    $cmdId = (int) $cmdId;
                    $technicalName = $this->matterRegistry->getClusterCommandName($clusterId, $cmdId) ?? "Command {$cmdId}";
                    $friendlyName = $friendlyActions[$cmdId] ?? $this->humanizeTechnicalName($technicalName);

                    $actions[] = [
                        'id' => $cmdId,
                        'technical' => $technicalName,
                        'friendly' => $friendlyName,
                        'implemented' => true,
                        'optional' => false,
                    ];
                }
            } elseif (!empty($friendlyActions)) {
                // Fall back to capability definition (no V3 telemetry, no spec)
                foreach ($friendlyActions as $cmdId => $friendly) {
                    $technicalName = $this->matterRegistry->getClusterCommandName($clusterId, $cmdId) ?? "Command {$cmdId}";
                    $actions[] = [
                        'id' => $cmdId,
                        'technical' => $technicalName,
                        'friendly' => $friendly,
                        'implemented' => null,
                        'optional' => false,
                    ];
                }
            }

            // Get all attributes from the spec and compare against implementation
            $specAttributes = $this->matterRegistry->getClusterAttributes($clusterId);
            $hasV3TelemetryAttrs = !empty($attributeList);

            if (!empty($specAttributes)) {
                // We have spec data - show full spec with implementation status
                foreach ($specAttributes as $attr) {
                    $attrId = $attr['id'];
                    $technicalName = $attr['name'];
                    $friendlyName = $friendlyStatuses[$attrId] ?? $this->humanizeTechnicalName($technicalName);
                    $isOptional = $attr['optional'];

                    // Check if implemented (only if we have V3 telemetry)
                    $implemented = $hasV3TelemetryAttrs ? isset($implementedAttributes[$attrId]) : null;

                    $statuses[] = [
                        'id' => $attrId,
                        'technical' => $technicalName,
                        'friendly' => $friendlyName,
                        'implemented' => $implemented,
                        'optional' => $isOptional,
                    ];
                }
            } elseif ($hasV3TelemetryAttrs) {
                // No spec data but have telemetry - show only implemented attributes
                foreach ($attributeList as $attrId) {
                    $attrId = (int) $attrId;
                    if ($attrId >= 65528) {
                        continue; // Skip global attributes
                    }

                    $technicalName = $this->matterRegistry->getClusterAttributeName($clusterId, $attrId) ?? "Attribute {$attrId}";
                    $friendlyName = $friendlyStatuses[$attrId] ?? $this->humanizeTechnicalName($technicalName);

                    $statuses[] = [
                        'id' => $attrId,
                        'technical' => $technicalName,
                        'friendly' => $friendlyName,
                        'implemented' => true,
                        'optional' => false,
                    ];
                }
            } elseif (!empty($friendlyStatuses)) {
                // Fall back to capability definition (no V3 telemetry)
                foreach ($friendlyStatuses as $attrId => $friendly) {
                    $technicalName = $this->matterRegistry->getClusterAttributeName($clusterId, $attrId) ?? "Attribute {$attrId}";
                    $statuses[] = [
                        'id' => $attrId,
                        'technical' => $technicalName,
                        'friendly' => $friendly,
                        'implemented' => null,
                        'optional' => false,
                    ];
                }
            }

            // Process features if available
            if (null !== $featureMap && $featureMap > 0) {
                $decodedFeatures = $this->matterRegistry->decodeFeatureMap($clusterId, $featureMap);
                foreach ($decodedFeatures as $feature) {
                    if ($feature['enabled']) {
                        $features[] = [
                            'name' => $feature['name'],
                            'enabled' => true,
                        ];
                    }
                }
            }

            // Only return details if we have meaningful content
            if (empty($actions) && empty($statuses) && empty($features)) {
                return null;
            }

            return [
                'clusterId' => $clusterId,
                'clusterName' => $clusterName,
                'specVersion' => $specVersion,
                'actions' => $actions,
                'statuses' => $statuses,
                'features' => $features,
            ];
        }

        return null;
    }

    /**
     * Convert a technical camelCase or PascalCase name to human-readable text.
     */
    private function humanizeTechnicalName(string $name): string
    {
        // Insert space before capital letters
        $spaced = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $name);
        // Handle acronyms (e.g., "OnOff" -> "On Off")
        $spaced = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $spaced);

        return ucfirst(strtolower($spaced));
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
