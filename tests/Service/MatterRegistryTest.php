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
}
