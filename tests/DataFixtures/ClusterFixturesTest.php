<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\Entity\Cluster;
use App\Entity\ClusterVersion;
use App\Repository\ClusterRepository;
use App\Repository\ClusterVersionRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test that ClusterFixtures + ClusterVersionFixtures together load cluster
 * data correctly. Hand-curated annotations (name, description, category,
 * isGlobal) live on the Cluster entity; per-Matter-version spec data
 * (attributes, commands, features) lives on ClusterVersion rows.
 */
final class ClusterFixturesTest extends KernelTestCase
{
    private ClusterRepository $clusters;
    private ClusterVersionRepository $versions;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clusters = self::getContainer()->get(ClusterRepository::class);
        $this->versions = self::getContainer()->get(ClusterVersionRepository::class);
    }

    private function latest(int $clusterId): ClusterVersion
    {
        $latest = $this->versions->findLatestMatterVersion();
        $this->assertNotNull($latest, 'Expected at least one ClusterVersion row in fixtures');

        $snapshot = $this->versions->find(['clusterId' => $clusterId, 'matterVersion' => $latest]);
        $this->assertInstanceOf(ClusterVersion::class, $snapshot, sprintf('Expected cluster %d snapshot at %s', $clusterId, $latest));

        return $snapshot;
    }

    public function testOnOffClusterHasAttributes(): void
    {
        $cluster = $this->clusters->find(6);

        $this->assertInstanceOf(Cluster::class, $cluster);
        $this->assertSame('On/Off', $cluster->getName());

        $attributes = $this->latest(6)->getAttributes();
        $this->assertNotNull($attributes);
        $this->assertIsArray($attributes);
        $this->assertNotEmpty($attributes);

        $onOffAttr = array_filter($attributes, fn (array $a): bool => 'OnOff' === $a['name']);
        $this->assertNotEmpty($onOffAttr, 'OnOff attribute should exist in the latest snapshot');
    }

    public function testOnOffClusterHasCommands(): void
    {
        $commands = $this->latest(6)->getCommands();
        $this->assertNotNull($commands);
        $this->assertIsArray($commands);
        $this->assertNotEmpty($commands);

        $offCmd = array_filter($commands, fn (array $c): bool => 'Off' === $c['name']);
        $this->assertNotEmpty($offCmd, 'Off command should exist');

        $onCmd = array_filter($commands, fn (array $c): bool => 'On' === $c['name']);
        $this->assertNotEmpty($onCmd, 'On command should exist');
    }

    public function testOnOffClusterHasFeatures(): void
    {
        $features = $this->latest(6)->getFeatures();
        $this->assertNotNull($features);
        $this->assertIsArray($features);
        $this->assertNotEmpty($features);

        $lightingFeature = array_filter($features, fn (array $f): bool => 'LT' === $f['code']);
        $this->assertNotEmpty($lightingFeature, 'Lighting (LT) feature should exist');
    }

    public function testLevelControlClusterHasAllSpecData(): void
    {
        $cluster = $this->clusters->find(8);

        $this->assertInstanceOf(Cluster::class, $cluster);
        $this->assertSame('Level Control', $cluster->getName());

        $snapshot = $this->latest(8);
        $this->assertNotEmpty($snapshot->getAttributes() ?? []);
        $this->assertNotEmpty($snapshot->getCommands() ?? []);
        $this->assertNotEmpty($snapshot->getFeatures() ?? []);
    }

    public function testAttributeStructure(): void
    {
        $attributes = $this->latest(6)->getAttributes() ?? [];

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
        $commands = $this->latest(6)->getCommands() ?? [];

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
        $features = $this->latest(6)->getFeatures() ?? [];

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
        // Binding (0x001E = 30) is a global cluster with minimal spec data;
        // verify the snapshot lookup either returns null arrays or empty
        // arrays without throwing.
        $cluster = $this->clusters->find(30);
        $this->assertInstanceOf(Cluster::class, $cluster);

        $snapshot = $this->latest(30);
        $attributes = $snapshot->getAttributes();
        $commands = $snapshot->getCommands();
        $features = $snapshot->getFeatures();

        $this->assertThat($attributes, $this->logicalOr($this->isNull(), $this->isArray()));
        $this->assertThat($commands, $this->logicalOr($this->isNull(), $this->isArray()));
        $this->assertThat($features, $this->logicalOr($this->isNull(), $this->isArray()));
    }
}
