<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\Entity\Cluster;
use App\Repository\ClusterRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test that ClusterFixtures properly loads cluster data including ZAP spec data
 * (attributes, commands, features) from fixtures/clusters.yaml.
 */
class ClusterFixturesTest extends KernelTestCase
{
    private ClusterRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ClusterRepository::class);
    }

    public function testOnOffClusterHasAttributes(): void
    {
        $cluster = $this->repository->find(6); // On/Off cluster

        $this->assertNotNull($cluster);
        $this->assertSame('On/Off', $cluster->getName());

        $attributes = $cluster->getAttributes();
        $this->assertNotNull($attributes);
        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        // Verify OnOff attribute exists
        $onOffAttr = array_filter($attributes, fn ($a) => 'OnOff' === $a['name']);
        $this->assertNotEmpty($onOffAttr, 'OnOff attribute should exist');
    }

    public function testOnOffClusterHasCommands(): void
    {
        $cluster = $this->repository->find(6);

        $commands = $cluster->getCommands();
        $this->assertNotNull($commands);
        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);

        // Verify Off command exists
        $offCmd = array_filter($commands, fn ($c) => 'Off' === $c['name']);
        $this->assertNotEmpty($offCmd, 'Off command should exist');

        // Verify On command exists
        $onCmd = array_filter($commands, fn ($c) => 'On' === $c['name']);
        $this->assertNotEmpty($onCmd, 'On command should exist');
    }

    public function testOnOffClusterHasFeatures(): void
    {
        $cluster = $this->repository->find(6);

        $features = $cluster->getFeatures();
        $this->assertNotNull($features);
        $this->assertIsArray($features);
        $this->assertNotEmpty($features);

        // Verify Lighting feature exists
        $lightingFeature = array_filter($features, fn ($f) => 'LT' === $f['code']);
        $this->assertNotEmpty($lightingFeature, 'Lighting (LT) feature should exist');
    }

    public function testLevelControlClusterHasAllSpecData(): void
    {
        $cluster = $this->repository->find(8); // Level Control

        $this->assertNotNull($cluster);
        $this->assertSame('Level Control', $cluster->getName());

        // Should have attributes
        $this->assertNotNull($cluster->getAttributes());
        $this->assertNotEmpty($cluster->getAttributes());

        // Should have commands
        $this->assertNotNull($cluster->getCommands());
        $this->assertNotEmpty($cluster->getCommands());

        // Should have features
        $this->assertNotNull($cluster->getFeatures());
        $this->assertNotEmpty($cluster->getFeatures());
    }

    public function testAttributeStructure(): void
    {
        $cluster = $this->repository->find(6);
        $attributes = $cluster->getAttributes();

        $firstAttr = $attributes[0];

        $this->assertArrayHasKey('code', $firstAttr);
        $this->assertArrayHasKey('name', $firstAttr);
        $this->assertArrayHasKey('type', $firstAttr);
        $this->assertArrayHasKey('writable', $firstAttr);
        $this->assertArrayHasKey('optional', $firstAttr);

        $this->assertIsInt($firstAttr['code']);
        $this->assertIsString($firstAttr['name']);
        $this->assertIsBool($firstAttr['writable']);
        $this->assertIsBool($firstAttr['optional']);
    }

    public function testCommandStructure(): void
    {
        $cluster = $this->repository->find(6);
        $commands = $cluster->getCommands();

        $firstCmd = $commands[0];

        $this->assertArrayHasKey('code', $firstCmd);
        $this->assertArrayHasKey('name', $firstCmd);
        $this->assertArrayHasKey('direction', $firstCmd);
        $this->assertArrayHasKey('optional', $firstCmd);
        $this->assertArrayHasKey('parameters', $firstCmd);

        $this->assertIsInt($firstCmd['code']);
        $this->assertIsString($firstCmd['name']);
        $this->assertIsString($firstCmd['direction']);
        $this->assertIsBool($firstCmd['optional']);
        $this->assertIsArray($firstCmd['parameters']);
    }

    public function testFeatureStructure(): void
    {
        $cluster = $this->repository->find(6);
        $features = $cluster->getFeatures();

        $firstFeature = $features[0];

        $this->assertArrayHasKey('bit', $firstFeature);
        $this->assertArrayHasKey('code', $firstFeature);
        $this->assertArrayHasKey('name', $firstFeature);

        $this->assertIsInt($firstFeature['bit']);
        $this->assertIsString($firstFeature['code']);
        $this->assertIsString($firstFeature['name']);
    }

    public function testClusterWithoutZapDataHandledGracefully(): void
    {
        // Find a cluster that might not have ZAP data
        // The Binding cluster (30) may have minimal or no ZAP data
        $cluster = $this->repository->find(30);

        $this->assertNotNull($cluster);

        // These should not throw errors even if null/empty
        $attributes = $cluster->getAttributes();
        $commands = $cluster->getCommands();
        $features = $cluster->getFeatures();

        // Values should be either null or arrays
        $this->assertTrue(null === $attributes || \is_array($attributes));
        $this->assertTrue(null === $commands || \is_array($commands));
        $this->assertTrue(null === $features || \is_array($features));
    }
}
