<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Cluster;
use PHPUnit\Framework\TestCase;

final class ClusterTest extends TestCase
{
    public function testConstructorSetsIdAndHexId(): void
    {
        $cluster = new Cluster(6);

        $this->assertSame(6, $cluster->getId());
        $this->assertSame('0x0006', $cluster->getHexId());
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
            ->setHexId('0x0006')
            ->setCreatedAt(new \DateTime())
            ->setUpdatedAt(new \DateTime());

        $this->assertSame($cluster, $result);
    }

    public function testHexIdRoundTrip(): void
    {
        $cluster = new Cluster(6)->setHexId('0x00FF');

        $this->assertSame('0x00FF', $cluster->getHexId());
    }

    public function testTimestampsRoundTrip(): void
    {
        $created = new \DateTime('2025-01-01');
        $updated = new \DateTime('2025-02-01');

        $cluster = new Cluster(6)
            ->setCreatedAt($created)
            ->setUpdatedAt($updated);

        $this->assertSame($created, $cluster->getCreatedAt());
        $this->assertSame($updated, $cluster->getUpdatedAt());
    }
}
