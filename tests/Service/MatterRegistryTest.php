<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MatterRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for MatterRegistry service.
 *
 * These tests require the database fixtures to be loaded.
 * Run `make test-reset` before running tests if fixtures are missing.
 */
class MatterRegistryTest extends KernelTestCase
{
    private MatterRegistry $registry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->registry = self::getContainer()->get(MatterRegistry::class);
    }

    public function testGetClusterNameReturnsKnownCluster(): void
    {
        $this->assertEquals('On/Off', $this->registry->getClusterName(6));
        $this->assertEquals('Level Control', $this->registry->getClusterName(8));
        $this->assertEquals('Color Control', $this->registry->getClusterName(768));
        $this->assertEquals('Thermostat', $this->registry->getClusterName(513));
    }

    public function testGetClusterNameReturnsFormattedHexForUnknown(): void
    {
        $name = $this->registry->getClusterName(0x9999);
        $this->assertEquals('Cluster 0x9999', $name);
    }

    public function testGetDeviceTypeNameReturnsKnownType(): void
    {
        $this->assertEquals('On/Off Light', $this->registry->getDeviceTypeName(256));
        $this->assertEquals('Dimmable Light', $this->registry->getDeviceTypeName(257));
        $this->assertEquals('Thermostat', $this->registry->getDeviceTypeName(769));
    }

    public function testGetDeviceTypeNameReturnsDefaultForUnknown(): void
    {
        $name = $this->registry->getDeviceTypeName(99999);
        $this->assertEquals('Device Type 99999', $name);
    }

    public function testGetDeviceTypeMetadataReturnsFullMetadata(): void
    {
        $metadata = $this->registry->getDeviceTypeMetadata(256);

        $this->assertIsArray($metadata);
        $this->assertEquals('On/Off Light', $metadata['name']);
        $this->assertEquals('1.0', $metadata['specVersion']);
        $this->assertEquals('lighting', $metadata['category']);
        $this->assertEquals('Lights', $metadata['displayCategory']);
        $this->assertEquals('lightbulb', $metadata['icon']);
        $this->assertArrayHasKey('description', $metadata);
    }

    public function testGetDeviceTypeMetadataReturnsNullForUnknown(): void
    {
        $this->assertNull($this->registry->getDeviceTypeMetadata(99999));
    }

    public function testGetDeviceTypeSpecVersion(): void
    {
        $this->assertEquals('1.0', $this->registry->getDeviceTypeSpecVersion(256));
        $this->assertEquals('1.2', $this->registry->getDeviceTypeSpecVersion(43)); // Fan
        $this->assertNull($this->registry->getDeviceTypeSpecVersion(99999));
    }

    public function testGetDeviceTypeIcon(): void
    {
        $this->assertEquals('lightbulb', $this->registry->getDeviceTypeIcon(256));
        $this->assertEquals('thermometer', $this->registry->getDeviceTypeIcon(769));
        $this->assertNull($this->registry->getDeviceTypeIcon(99999));
    }

    public function testGetDeviceTypeCategory(): void
    {
        $this->assertEquals('lighting', $this->registry->getDeviceTypeCategory(256));
        $this->assertEquals('hvac', $this->registry->getDeviceTypeCategory(769));
        $this->assertEquals('sensors', $this->registry->getDeviceTypeCategory(770));
        $this->assertNull($this->registry->getDeviceTypeCategory(99999));
    }

    public function testGetDeviceTypeDisplayCategory(): void
    {
        $this->assertEquals('Lights', $this->registry->getDeviceTypeDisplayCategory(256));
        $this->assertEquals('Climate', $this->registry->getDeviceTypeDisplayCategory(769));
        $this->assertEquals('Sensors', $this->registry->getDeviceTypeDisplayCategory(770));
        $this->assertNull($this->registry->getDeviceTypeDisplayCategory(99999));
    }

    public function testGetDeviceTypesByCategory(): void
    {
        $lightingDevices = $this->registry->getDeviceTypesByCategory('lighting');

        $this->assertIsArray($lightingDevices);
        $this->assertNotEmpty($lightingDevices);

        foreach ($lightingDevices as $device) {
            $this->assertEquals('lighting', $device['category']);
        }
    }

    public function testGetDeviceTypesByDisplayCategory(): void
    {
        $sensors = $this->registry->getDeviceTypesByDisplayCategory('Sensors');

        $this->assertIsArray($sensors);
        $this->assertNotEmpty($sensors);

        foreach ($sensors as $device) {
            $this->assertEquals('Sensors', $device['displayCategory']);
        }
    }

    public function testGetDeviceTypesBySpecVersion(): void
    {
        $v10Devices = $this->registry->getDeviceTypesBySpecVersion('1.0');

        $this->assertIsArray($v10Devices);
        $this->assertNotEmpty($v10Devices);

        foreach ($v10Devices as $device) {
            $this->assertEquals('1.0', $device['specVersion']);
        }
    }

    public function testGetAllCategories(): void
    {
        $categories = $this->registry->getAllCategories();

        $this->assertIsArray($categories);
        $this->assertContains('lighting', $categories);
        $this->assertContains('hvac', $categories);
        $this->assertContains('sensors', $categories);
    }

    public function testGetAllDisplayCategories(): void
    {
        $categories = $this->registry->getAllDisplayCategories();

        $this->assertIsArray($categories);
        $this->assertContains('Lights', $categories);
        $this->assertContains('Climate', $categories);
        $this->assertContains('Sensors', $categories);
    }

    public function testGetAllSpecVersions(): void
    {
        $versions = $this->registry->getAllSpecVersions();

        $this->assertIsArray($versions);
        $this->assertContains('1.0', $versions);
        // Versions should be sorted
        $this->assertEquals($versions, array_values($versions));
    }

    public function testGetAllClusterNames(): void
    {
        $clusters = $this->registry->getAllClusterNames();

        $this->assertIsArray($clusters);
        $this->assertNotEmpty($clusters);
        $this->assertEquals('On/Off', $clusters[6]);
    }

    public function testGetAllDeviceTypeNames(): void
    {
        $names = $this->registry->getAllDeviceTypeNames();

        $this->assertIsArray($names);
        $this->assertNotEmpty($names);
        $this->assertEquals('On/Off Light', $names[256]);
    }

    public function testGetAllDeviceTypeMetadata(): void
    {
        $metadata = $this->registry->getAllDeviceTypeMetadata();

        $this->assertIsArray($metadata);
        $this->assertNotEmpty($metadata);
        $this->assertArrayHasKey(256, $metadata);
    }

    public function testGetClusterMetadata(): void
    {
        $metadata = $this->registry->getClusterMetadata(6);

        $this->assertIsArray($metadata);
        $this->assertEquals('On/Off', $metadata['name']);
        $this->assertEquals('0x0006', $metadata['hexId']);
        $this->assertArrayHasKey('description', $metadata);
        $this->assertArrayHasKey('category', $metadata);
    }

    public function testGetMandatoryServerClusters(): void
    {
        $clusters = $this->registry->getMandatoryServerClusters(256); // On/Off Light

        $this->assertIsArray($clusters);
        $this->assertNotEmpty($clusters);

        // On/Off Light requires Identify, Groups, Scenes Management, On/Off
        $clusterIds = array_column($clusters, 'id');
        $this->assertContains(3, $clusterIds); // Identify
        $this->assertContains(6, $clusterIds); // On/Off
    }

    public function testGetExtendedDeviceType(): void
    {
        $deviceType = $this->registry->getExtendedDeviceType(256);

        $this->assertIsArray($deviceType);
        $this->assertEquals('On/Off Light', $deviceType['name']);
        $this->assertArrayHasKey('mandatoryServerClusters', $deviceType);
        $this->assertArrayHasKey('optionalServerClusters', $deviceType);
    }

    public function testHasExtendedData(): void
    {
        $this->assertTrue($this->registry->hasExtendedData(256));
        $this->assertFalse($this->registry->hasExtendedData(99999));
    }

    // ========================================================================
    // Cluster Gap Analysis Tests
    // ========================================================================

    public function testAnalyzeClusterGapsWithFullCompliance(): void
    {
        // On/Off Light (256) requires: Identify (3), Groups (4), Scenes Management (98), On/Off (6)
        $actualServerClusters = [3, 4, 6, 98, 29]; // All mandatory + Descriptor
        $actualClientClusters = [];

        $analysis = $this->registry->analyzeClusterGaps(256, $actualServerClusters, $actualClientClusters);

        $this->assertIsArray($analysis);
        $this->assertNotNull($analysis['deviceType']);
        $this->assertEquals('On/Off Light', $analysis['deviceType']['name']);
        $this->assertTrue($analysis['compliance']['mandatory']);
        $this->assertEmpty($analysis['missingMandatoryServer']);
        $this->assertEmpty($analysis['missingMandatoryClient']);
    }

    public function testAnalyzeClusterGapsWithMissingMandatory(): void
    {
        // On/Off Light without the On/Off cluster
        $actualServerClusters = [3, 4, 98, 29]; // Missing On/Off (6)
        $actualClientClusters = [];

        $analysis = $this->registry->analyzeClusterGaps(256, $actualServerClusters, $actualClientClusters);

        $this->assertFalse($analysis['compliance']['mandatory']);
        $this->assertNotEmpty($analysis['missingMandatoryServer']);

        $missingIds = array_column($analysis['missingMandatoryServer'], 'id');
        $this->assertContains(6, $missingIds); // On/Off should be missing
    }

    public function testAnalyzeClusterGapsWithOptionalClusters(): void
    {
        // On/Off Light with optional Level Control implemented
        $actualServerClusters = [3, 4, 6, 98, 29, 8]; // Mandatory + Level Control (8) optional
        $actualClientClusters = [];

        $analysis = $this->registry->analyzeClusterGaps(256, $actualServerClusters, $actualClientClusters);

        $this->assertTrue($analysis['compliance']['mandatory']);

        // Check if Level Control is in implemented optional
        $implementedOptionalIds = array_column($analysis['implementedOptionalServer'], 'id');
        $this->assertContains(8, $implementedOptionalIds);

        // Optional score should be > 0 since we implemented an optional cluster
        $this->assertGreaterThan(0, $analysis['compliance']['implementedOptional']);
    }

    public function testAnalyzeClusterGapsWithExtraClusters(): void
    {
        // On/Off Light with an extra cluster not in spec
        $actualServerClusters = [3, 4, 6, 98, 29, 513]; // Mandatory + Thermostat (513) which is not for lights
        $actualClientClusters = [];

        $analysis = $this->registry->analyzeClusterGaps(256, $actualServerClusters, $actualClientClusters);

        $this->assertNotEmpty($analysis['extraServer']);

        $extraIds = array_column($analysis['extraServer'], 'id');
        $this->assertContains(513, $extraIds); // Thermostat should be extra
    }

    public function testAnalyzeClusterGapsWithUnknownDeviceType(): void
    {
        $analysis = $this->registry->analyzeClusterGaps(99999, [6, 8], []);

        $this->assertNull($analysis['deviceType']);
        $this->assertEmpty($analysis['missingMandatoryServer']);
        $this->assertEmpty($analysis['missingOptionalServer']);
        $this->assertTrue($analysis['compliance']['mandatory']);
        $this->assertEquals(100.0, $analysis['compliance']['score']);
    }

    public function testAnalyzeClusterGapsScoreCalculation(): void
    {
        // Test score is calculated correctly
        // On/Off Light: 4 mandatory clusters, several optional
        $actualServerClusters = [3, 4, 6, 98]; // All mandatory
        $actualClientClusters = [];

        $analysis = $this->registry->analyzeClusterGaps(256, $actualServerClusters, $actualClientClusters);

        // Mandatory score should be 100% (all implemented)
        $this->assertEquals(100.0, $analysis['compliance']['mandatoryScore']);

        // Overall score = 70% mandatory + 30% optional
        // With 100% mandatory and 0% optional: 70 + 0 = 70
        $this->assertEquals(70.0, $analysis['compliance']['score']);
    }

    public function testAnalyzeClusterGapsIncludesClientClusters(): void
    {
        // Test that client clusters are analyzed properly
        // Create a scenario with client clusters by checking door lock
        $mandatoryServer = $this->registry->getMandatoryServerClusters(769);
        $optionalClient = $this->registry->getOptionalClientClusters(769);

        // Provide server clusters and some client clusters
        $serverClusterIds = array_column($mandatoryServer, 'id');
        $clientClusterIds = !empty($optionalClient) ? [array_column($optionalClient, 'id')[0]] : [];

        $analysis = $this->registry->analyzeClusterGaps(769, $serverClusterIds, $clientClusterIds);

        // Should have analysis result with proper structure
        $this->assertIsArray($analysis);
        $this->assertArrayHasKey('missingMandatoryClient', $analysis);
        $this->assertArrayHasKey('missingOptionalClient', $analysis);
        $this->assertArrayHasKey('implementedOptionalClient', $analysis);
    }

    public function testAnalyzeDeviceClusterGapsWithMultipleEndpoints(): void
    {
        $endpoints = [
            [
                'device_types' => [256], // On/Off Light
                'server_clusters' => [3, 4, 6, 98, 29],
                'client_clusters' => [],
            ],
            [
                'device_types' => [769], // Door Lock
                'server_clusters' => [3, 4, 29, 257, 101], // Typical door lock clusters
                'client_clusters' => [],
            ],
        ];

        $analyses = $this->registry->analyzeDeviceClusterGaps($endpoints);

        $this->assertIsArray($analyses);
        $this->assertArrayHasKey(256, $analyses);
        $this->assertArrayHasKey(769, $analyses);
    }

    public function testAnalyzeDeviceClusterGapsSkipsSystemDeviceTypes(): void
    {
        $endpoints = [
            [
                'device_types' => [22, 256], // Root Node (22) + On/Off Light (256)
                'server_clusters' => [3, 4, 6, 98, 29],
                'client_clusters' => [],
            ],
        ];

        $analyses = $this->registry->analyzeDeviceClusterGaps($endpoints);

        // Should not include Root Node (22) since it's < 256
        $this->assertArrayNotHasKey(22, $analyses);
        $this->assertArrayHasKey(256, $analyses);
    }

    public function testAnalyzeDeviceClusterGapsHandlesObjectDeviceTypes(): void
    {
        // Test with device types as objects (as they come from JSON)
        $endpoints = [
            [
                'device_types' => [['id' => 256]], // Object format
                'server_clusters' => [3, 4, 6, 98, 29],
                'client_clusters' => [],
            ],
        ];

        $analyses = $this->registry->analyzeDeviceClusterGaps($endpoints);

        $this->assertArrayHasKey(256, $analyses);
    }

    public function testGetClusterSpecVersion(): void
    {
        // Test with known cluster (On/Off - cluster ID 6)
        $specVersion = $this->registry->getClusterSpecVersion(6);

        $this->assertNotNull($specVersion);
        $this->assertIsString($specVersion);
    }

    public function testGetClusterSpecVersionReturnsNullForUnknown(): void
    {
        $specVersion = $this->registry->getClusterSpecVersion(999999);

        $this->assertNull($specVersion);
    }

    public function testGetClusterCommands(): void
    {
        // Test with On/Off cluster (ID 6) which has known commands
        $commands = $this->registry->getClusterCommands(6);

        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);

        // Check structure of first command
        $firstCommand = $commands[0];
        $this->assertArrayHasKey('id', $firstCommand);
        $this->assertArrayHasKey('name', $firstCommand);
        $this->assertArrayHasKey('optional', $firstCommand);
        $this->assertIsInt($firstCommand['id']);
        $this->assertIsString($firstCommand['name']);
        $this->assertIsBool($firstCommand['optional']);
    }

    public function testGetClusterCommandsReturnsEmptyForUnknown(): void
    {
        $commands = $this->registry->getClusterCommands(999999);

        $this->assertIsArray($commands);
        $this->assertEmpty($commands);
    }

    public function testGetClusterAttributes(): void
    {
        // Test with On/Off cluster (ID 6) which has known attributes
        $attributes = $this->registry->getClusterAttributes(6);

        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        // Check structure of first attribute
        $firstAttribute = $attributes[0];
        $this->assertArrayHasKey('id', $firstAttribute);
        $this->assertArrayHasKey('name', $firstAttribute);
        $this->assertArrayHasKey('optional', $firstAttribute);
        $this->assertIsInt($firstAttribute['id']);
        $this->assertIsString($firstAttribute['name']);
        $this->assertIsBool($firstAttribute['optional']);
    }

    public function testGetClusterAttributesReturnsEmptyForUnknown(): void
    {
        $attributes = $this->registry->getClusterAttributes(999999);

        $this->assertIsArray($attributes);
        $this->assertEmpty($attributes);
    }

    public function testGetClusterAttributesExcludesGlobalAttributes(): void
    {
        // Global attributes have IDs >= 65528, they should be excluded
        $attributes = $this->registry->getClusterAttributes(6);

        foreach ($attributes as $attr) {
            $this->assertLessThan(65528, $attr['id'], 'Global attributes should be excluded');
        }
    }
}
