<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cluster;
use PHPUnit\Framework\TestCase;

class ClusterTest extends TestCase
{
    public function testConstructorSetsIdAndHexId(): void
    {
        $cluster = new Cluster(6);

        $this->assertSame(6, $cluster->getId());
        $this->assertSame('0x0006', $cluster->getHexId());
    }

    public function testSetAttributes(): void
    {
        $cluster = new Cluster(6);
        $attributes = [
            ['code' => 0, 'name' => 'OnOff', 'type' => 'boolean', 'writable' => false, 'optional' => false],
            ['code' => 16384, 'name' => 'GlobalSceneControl', 'type' => 'boolean', 'writable' => false, 'optional' => true],
        ];

        $cluster->setAttributes($attributes);

        $this->assertSame($attributes, $cluster->getAttributes());
    }

    public function testSetCommands(): void
    {
        $cluster = new Cluster(6);
        $commands = [
            ['code' => 0, 'name' => 'Off', 'direction' => 'client→server', 'optional' => false, 'parameters' => []],
            ['code' => 1, 'name' => 'On', 'direction' => 'client→server', 'optional' => true, 'parameters' => []],
        ];

        $cluster->setCommands($commands);

        $this->assertSame($commands, $cluster->getCommands());
    }

    public function testSetFeatures(): void
    {
        $cluster = new Cluster(6);
        $features = [
            ['bit' => 0, 'code' => 'LT', 'name' => 'Lighting', 'summary' => 'Behavior that supports lighting applications.'],
            ['bit' => 1, 'code' => 'DF', 'name' => 'DeadFrontBehavior', 'summary' => 'Device has Dead Front behavior'],
        ];

        $cluster->setFeatures($features);

        $this->assertSame($features, $cluster->getFeatures());
    }

    public function testNullableJsonFields(): void
    {
        $cluster = new Cluster(6);

        $this->assertNull($cluster->getAttributes());
        $this->assertNull($cluster->getCommands());
        $this->assertNull($cluster->getFeatures());
    }

    public function testSetName(): void
    {
        $cluster = new Cluster(6);
        $cluster->setName('On/Off');

        $this->assertSame('On/Off', $cluster->getName());
    }

    public function testSetDescription(): void
    {
        $cluster = new Cluster(6);
        $cluster->setDescription('Controls on/off state');

        $this->assertSame('Controls on/off state', $cluster->getDescription());
    }

    public function testSetCategory(): void
    {
        $cluster = new Cluster(6);
        $cluster->setCategory('lighting');

        $this->assertSame('lighting', $cluster->getCategory());
    }

    public function testSetIsGlobal(): void
    {
        $cluster = new Cluster(6);
        $cluster->setIsGlobal(true);

        $this->assertTrue($cluster->isGlobal());
    }

    public function testFluentInterface(): void
    {
        $cluster = new Cluster(6);

        $result = $cluster
            ->setName('On/Off')
            ->setDescription('Test')
            ->setCategory('lighting')
            ->setIsGlobal(false)
            ->setAttributes([])
            ->setCommands([])
            ->setFeatures([]);

        $this->assertSame($cluster, $result);
    }
}
