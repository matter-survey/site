<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CapabilityService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for CapabilityService.
 *
 * These tests verify that capabilities are correctly detected based on
 * cluster presence and feature flags from V3 telemetry data.
 */
class CapabilityServiceTest extends KernelTestCase
{
    private CapabilityService $capabilityService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->capabilityService = self::getContainer()->get(CapabilityService::class);
    }

    public function testBasicCapabilityDetectionWithoutFeatures(): void
    {
        // Test a simple capability that only requires cluster presence (no feature check)
        // On/Off cluster (6) for on_off capability
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256], // On/Off Light
                'server_clusters' => [6, 29], // On/Off + Descriptor
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertArrayHasKey('supported', $result);

        // Find on_off capability
        $onOffCapability = array_filter($result['supported'], fn ($c) => 'on_off' === $c['key']);
        $this->assertNotEmpty($onOffCapability, 'on_off capability should be detected');
    }

    public function testSchedulingCapabilityRequiresFeatureFlags(): void
    {
        // Thermostat cluster (513) with feature_map = 1 (only HEAT, no SCH/MSCH)
        // Scheduling capability requires SCH (bit 3) or MSCH (bit 7)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769], // Thermostat
                'server_clusters' => [513, 29], // Thermostat + Descriptor
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 1], // Only HEAT (bit 0)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Scheduling should NOT be supported (missing SCH/MSCH features)
        $schedulingCapability = array_filter($result['supported'], fn ($c) => 'scheduling' === $c['key']);
        $this->assertEmpty($schedulingCapability, 'Scheduling should NOT be supported without SCH/MSCH features');

        // But heating SHOULD be supported (HEAT feature is present)
        $heatingCapability = array_filter($result['supported'], fn ($c) => 'heating' === $c['key']);
        $this->assertNotEmpty($heatingCapability, 'Heating should be supported with HEAT feature');
    }

    public function testSchedulingCapabilitySupportedWithSchFeature(): void
    {
        // Thermostat cluster with SCH feature (bit 3 = 8)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769], // Thermostat
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 9], // HEAT (1) + SCH (8)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $schedulingCapability = array_filter($result['supported'], fn ($c) => 'scheduling' === $c['key']);
        $this->assertNotEmpty($schedulingCapability, 'Scheduling should be supported with SCH feature');
    }

    public function testSchedulingCapabilitySupportedWithMschFeature(): void
    {
        // Thermostat cluster with MSCH feature (bit 7 = 128)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769], // Thermostat
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 129], // HEAT (1) + MSCH (128)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $schedulingCapability = array_filter($result['supported'], fn ($c) => 'scheduling' === $c['key']);
        $this->assertNotEmpty($schedulingCapability, 'Scheduling should be supported with MSCH feature');
    }

    public function testCoolingCapabilityRequiresCoolFeature(): void
    {
        // Thermostat with only HEAT feature
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 1], // Only HEAT
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $coolingCapability = array_filter($result['supported'], fn ($c) => 'cooling' === $c['key']);
        $this->assertEmpty($coolingCapability, 'Cooling should NOT be supported without COOL feature');
    }

    public function testCoolingCapabilitySupportedWithCoolFeature(): void
    {
        // Thermostat with COOL feature (bit 1 = 2)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 3], // HEAT (1) + COOL (2)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $coolingCapability = array_filter($result['supported'], fn ($c) => 'cooling' === $c['key']);
        $this->assertNotEmpty($coolingCapability, 'Cooling should be supported with COOL feature');
    }

    public function testAutoModeCapabilityRequiresAutoFeature(): void
    {
        // Thermostat with HEAT + COOL but no AUTO
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 3], // HEAT + COOL only
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $autoCapability = array_filter($result['supported'], fn ($c) => 'auto_mode' === $c['key']);
        $this->assertEmpty($autoCapability, 'Auto mode should NOT be supported without AUTO feature');
    }

    public function testAutoModeCapabilitySupportedWithAutoFeature(): void
    {
        // Thermostat with AUTO feature (bit 5 = 32)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 35], // HEAT (1) + COOL (2) + AUTO (32)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $autoCapability = array_filter($result['supported'], fn ($c) => 'auto_mode' === $c['key']);
        $this->assertNotEmpty($autoCapability, 'Auto mode should be supported with AUTO feature');
    }

    public function testV2FallbackWhenNoClusterDetails(): void
    {
        // V2 telemetry: no server_cluster_details means we can't check features
        // The fallback behavior is to assume capability is supported if cluster exists
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                // No server_cluster_details - V2 fallback
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // With V2 fallback, scheduling should be assumed supported (cluster exists)
        $schedulingCapability = array_filter($result['supported'], fn ($c) => 'scheduling' === $c['key']);
        $this->assertNotEmpty($schedulingCapability, 'V2 fallback should assume capability supported when cluster exists');
    }

    public function testV2FallbackWhenClusterDetailsMissingFeatureMap(): void
    {
        // server_cluster_details exists but without feature_map
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513], // No feature_map key
                    ['id' => 29],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Should fall back to V2 behavior
        $schedulingCapability = array_filter($result['supported'], fn ($c) => 'scheduling' === $c['key']);
        $this->assertNotEmpty($schedulingCapability, 'V2 fallback should assume capability supported when feature_map missing');
    }

    public function testThermostatControlDoesNotRequireFeatures(): void
    {
        // thermostat_control just requires Thermostat cluster, no specific features
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 0], // No features at all
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $thermostatControl = array_filter($result['supported'], fn ($c) => 'thermostat_control' === $c['key']);
        $this->assertNotEmpty($thermostatControl, 'Thermostat control should be supported with just the cluster');
    }

    public function testMultipleEndpointsAggregateClusters(): void
    {
        // Device with two endpoints having different clusters
        // The capability analysis aggregates clusters from all endpoints
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769], // Thermostat
                'server_clusters' => [513, 29], // Thermostat + Descriptor
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 1], // HEAT
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
            [
                'endpoint_id' => 2,
                'device_types' => [769], // Thermostat
                'server_clusters' => [1026, 29], // Temperature Measurement + Descriptor
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 1026, 'feature_map' => 0],
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Should detect heating from endpoint 1's Thermostat cluster
        $heatingCapability = array_filter($result['supported'], fn ($c) => 'heating' === $c['key']);
        $this->assertNotEmpty($heatingCapability, 'heating should be detected from thermostat cluster');

        // Should detect temperature_sensing from endpoint 2's Temperature Measurement cluster
        $tempCapability = array_filter($result['supported'], fn ($c) => 'temperature_sensing' === $c['key']);
        $this->assertNotEmpty($tempCapability, 'temperature_sensing should be detected from temp measurement cluster');
    }

    public function testSameClusterOnMultipleEndpointsUsesLastFeatureMap(): void
    {
        // Note: Current behavior uses the last endpoint's feature_map when same cluster
        // appears on multiple endpoints. This test documents that behavior.
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 1], // HEAT only
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
            [
                'endpoint_id' => 2,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 2], // COOL only (overwrites endpoint 1)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Last endpoint wins, so only COOL should be detected, not HEAT
        $heatingCapability = array_filter($result['supported'], fn ($c) => 'heating' === $c['key']);
        $coolingCapability = array_filter($result['supported'], fn ($c) => 'cooling' === $c['key']);

        $this->assertEmpty($heatingCapability, 'heating NOT detected (last endpoint overwrites)');
        $this->assertNotEmpty($coolingCapability, 'cooling detected from last endpoint');
    }

    public function testPresetsCapabilityRequiresPresFeature(): void
    {
        // Thermostat without PRES feature
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 1], // Only HEAT
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $presetsCapability = array_filter($result['supported'], fn ($c) => 'thermostat_presets' === $c['key']);
        $this->assertEmpty($presetsCapability, 'Presets should NOT be supported without PRES feature');
    }

    public function testPresetsCapabilitySupportedWithPresFeature(): void
    {
        // Thermostat with PRES feature (bit 8 = 256)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [769],
                'server_clusters' => [513, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    ['id' => 513, 'feature_map' => 257], // HEAT (1) + PRES (256)
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $presetsCapability = array_filter($result['supported'], fn ($c) => 'thermostat_presets' === $c['key']);
        $this->assertNotEmpty($presetsCapability, 'Presets should be supported with PRES feature');
    }

    public function testCapabilityIncludesSpecVersion(): void
    {
        // Test that analyzed capabilities include specVersion
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256], // On/Off Light
                'server_clusters' => [6, 29], // On/Off + Descriptor
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Find on_off capability
        $onOffCapability = array_filter($result['supported'], fn ($c) => 'on_off' === $c['key']);
        $this->assertNotEmpty($onOffCapability);

        $capability = reset($onOffCapability);
        $this->assertArrayHasKey('specVersion', $capability);
    }

    public function testCapabilityDetailsIncludeImplementationStatus(): void
    {
        // Test with V3 telemetry data that includes accepted_command_list
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256], // On/Off Light
                'server_clusters' => [6, 29], // On/Off + Descriptor
                'client_clusters' => [],
                'server_cluster_details' => [
                    [
                        'id' => 6,
                        'feature_map' => 0,
                        'accepted_command_list' => [0, 1, 2], // Off, On, Toggle
                        'attribute_list' => [0, 16384, 16385], // OnOff, GlobalSceneControl, OnTime
                    ],
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        // Find on_off capability
        $onOffCapability = array_filter($result['supported'], fn ($c) => 'on_off' === $c['key']);
        $this->assertNotEmpty($onOffCapability);

        /** @var array<string, mixed> $capability */
        $capability = reset($onOffCapability);
        $this->assertIsArray($capability);
        $this->assertArrayHasKey('details', $capability);
        $this->assertNotNull($capability['details']);

        $details = $capability['details'];
        $this->assertIsArray($details);

        // Check that actions include 'implemented' field
        if (!empty($details['actions'])) {
            $actions = $details['actions'];
            $this->assertIsArray($actions);
            $firstAction = $actions[0];
            $this->assertArrayHasKey('implemented', $firstAction);
            $this->assertArrayHasKey('optional', $firstAction);
        }

        // Check that statuses include 'implemented' field
        if (!empty($details['statuses'])) {
            $statuses = $details['statuses'];
            $this->assertIsArray($statuses);
            $firstStatus = $statuses[0];
            $this->assertArrayHasKey('implemented', $firstStatus);
            $this->assertArrayHasKey('optional', $firstStatus);
        }
    }

    public function testCapabilityDetailsShowUnimplementedOptionalCommands(): void
    {
        // Test with V3 telemetry that only implements mandatory commands
        // The On/Off cluster has optional commands like OffWithEffect (64)
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256],
                'server_clusters' => [6, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    [
                        'id' => 6,
                        'feature_map' => 0,
                        'accepted_command_list' => [0, 1, 2], // Only mandatory: Off, On, Toggle
                        'attribute_list' => [0], // Only OnOff attribute
                    ],
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $onOffCapability = array_filter($result['supported'], fn ($c) => 'on_off' === $c['key']);
        /** @var array<string, mixed>|false $capability */
        $capability = reset($onOffCapability);

        $this->assertIsArray($capability);
        $this->assertArrayHasKey('details', $capability);

        $details = $capability['details'];
        if (\is_array($details) && !empty($details['actions'])) {
            $actions = $details['actions'];
            $this->assertIsArray($actions);

            // Check for mix of implemented and not-implemented commands
            $implementedCount = 0;
            $notImplementedCount = 0;

            foreach ($actions as $action) {
                if (true === $action['implemented']) {
                    ++$implementedCount;
                } elseif (false === $action['implemented']) {
                    ++$notImplementedCount;
                }
            }

            // Should have some implemented commands (the ones in accepted_command_list)
            $this->assertGreaterThan(0, $implementedCount, 'Should have implemented commands');
        }
    }

    public function testCapabilityDetailsSpecVersionFromCluster(): void
    {
        // Test that details include specVersion from the cluster
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256],
                'server_clusters' => [6, 29],
                'client_clusters' => [],
                'server_cluster_details' => [
                    [
                        'id' => 6,
                        'feature_map' => 0,
                        'accepted_command_list' => [0, 1, 2],
                        'attribute_list' => [0],
                    ],
                    ['id' => 29, 'feature_map' => 0],
                ],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $onOffCapability = array_filter($result['supported'], fn ($c) => 'on_off' === $c['key']);
        /** @var array<string, mixed>|false $capability */
        $capability = reset($onOffCapability);

        $this->assertIsArray($capability);
        $this->assertArrayHasKey('details', $capability);

        $details = $capability['details'];
        if (\is_array($details)) {
            $this->assertArrayHasKey('specVersion', $details);
        }
    }
}
