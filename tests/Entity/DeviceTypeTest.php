<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\DeviceType;
use PHPUnit\Framework\TestCase;

final class DeviceTypeTest extends TestCase
{
    public function testConstructorSetsIdHexIdAndTimestamps(): void
    {
        $deviceType = new DeviceType(266);

        $this->assertSame(266, $deviceType->getId());
        $this->assertSame('0x010A', $deviceType->getHexId());
        $this->assertInstanceOf(\DateTimeInterface::class, $deviceType->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $deviceType->getUpdatedAt());
    }

    public function testHexIdIsPaddedToFourDigits(): void
    {
        $this->assertSame('0x000A', new DeviceType(10)->getHexId());
        $this->assertSame('0x0FFF', new DeviceType(4095)->getHexId());
    }

    public function testNameAndDescriptionRoundTrip(): void
    {
        $deviceType = new DeviceType(266)
            ->setName('On/Off Plug-in Unit')
            ->setDescription('A plug-in module with on/off control.');

        $this->assertSame('On/Off Plug-in Unit', $deviceType->getName());
        $this->assertSame('A plug-in module with on/off control.', $deviceType->getDescription());
    }

    public function testTaxonomyFieldsRoundTrip(): void
    {
        $deviceType = new DeviceType(266)
            ->setSpecVersion('1.4')
            ->setCategory('plugs')
            ->setDisplayCategory('Plugs & Outlets')
            ->setDeviceClass('Simple')
            ->setScope('Endpoint')
            ->setSuperset('on_off_light')
            ->setIcon('plug')
            ->setHexId('0x010A');

        $this->assertSame('1.4', $deviceType->getSpecVersion());
        $this->assertSame('plugs', $deviceType->getCategory());
        $this->assertSame('Plugs & Outlets', $deviceType->getDisplayCategory());
        $this->assertSame('Simple', $deviceType->getDeviceClass());
        $this->assertSame('Endpoint', $deviceType->getScope());
        $this->assertSame('on_off_light', $deviceType->getSuperset());
        $this->assertSame('plug', $deviceType->getIcon());
        $this->assertSame('0x010A', $deviceType->getHexId());
    }

    public function testClusterCollectionsRoundTrip(): void
    {
        $deviceType = new DeviceType(266)
            ->setMandatoryServerClusters([3, 4, 6])
            ->setOptionalServerClusters([8])
            ->setMandatoryClientClusters([29])
            ->setOptionalClientClusters([1024]);

        $this->assertSame([3, 4, 6], $deviceType->getMandatoryServerClusters());
        $this->assertSame([8], $deviceType->getOptionalServerClusters());
        $this->assertSame([29], $deviceType->getMandatoryClientClusters());
        $this->assertSame([1024], $deviceType->getOptionalClientClusters());
    }

    public function testTotalClusterCounts(): void
    {
        $deviceType = new DeviceType(266)
            ->setMandatoryServerClusters([3, 4, 6])
            ->setOptionalServerClusters([8])
            ->setMandatoryClientClusters([29])
            ->setOptionalClientClusters([1024, 2048]);

        $this->assertSame(4, $deviceType->getTotalServerClusters());
        $this->assertSame(3, $deviceType->getTotalClientClusters());
        $this->assertSame(7, $deviceType->getTotalClusters());
    }

    public function testScoringWeightsRoundTrip(): void
    {
        $weights = ['mandatoryServerWeight' => 0.5, 'keyClientClusters' => [29]];

        $deviceType = new DeviceType(266)->setScoringWeights($weights);

        $this->assertSame($weights, $deviceType->getScoringWeights());
    }

    public function testScoringWeightsWithDefaultsWhenUnset(): void
    {
        $deviceType = new DeviceType(266);

        $defaults = $deviceType->getScoringWeightsWithDefaults();

        $this->assertEqualsWithDelta(0.40, $defaults['mandatoryServerWeight'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.20, $defaults['mandatoryClientWeight'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.25, $defaults['optionalServerWeight'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.15, $defaults['optionalClientWeight'], PHP_FLOAT_EPSILON);
        $this->assertSame([], $defaults['keyClientClusters']);
        $this->assertEqualsWithDelta(0.0, $defaults['keyClientBonus'], PHP_FLOAT_EPSILON);
    }

    public function testScoringWeightsWithDefaultsMergesOverrides(): void
    {
        $deviceType = new DeviceType(266)
            ->setScoringWeights([
                'mandatoryServerWeight' => 0.60,
                'keyClientClusters' => [29],
                'keyClientBonus' => 0.10,
            ]);

        $merged = $deviceType->getScoringWeightsWithDefaults();

        // overridden
        $this->assertEqualsWithDelta(0.60, $merged['mandatoryServerWeight'], PHP_FLOAT_EPSILON);
        $this->assertSame([29], $merged['keyClientClusters']);
        $this->assertEqualsWithDelta(0.10, $merged['keyClientBonus'], PHP_FLOAT_EPSILON);
        // default preserved
        $this->assertEqualsWithDelta(0.20, $merged['mandatoryClientWeight'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.25, $merged['optionalServerWeight'], PHP_FLOAT_EPSILON);
        $this->assertEqualsWithDelta(0.15, $merged['optionalClientWeight'], PHP_FLOAT_EPSILON);
    }

    public function testTimestampSetters(): void
    {
        $createdAt = new \DateTime('2025-01-01');
        $updatedAt = new \DateTime('2025-06-01');

        $deviceType = new DeviceType(266)
            ->setCreatedAt($createdAt)
            ->setUpdatedAt($updatedAt);

        $this->assertSame($createdAt, $deviceType->getCreatedAt());
        $this->assertSame($updatedAt, $deviceType->getUpdatedAt());
    }

    public function testFluentInterface(): void
    {
        $deviceType = new DeviceType(266);
        $result = $deviceType
            ->setName('n')
            ->setDescription('d')
            ->setHexId('0x010A')
            ->setSpecVersion('1.4')
            ->setCategory('c')
            ->setDisplayCategory('dc')
            ->setDeviceClass('dcl')
            ->setScope('s')
            ->setSuperset('sup')
            ->setIcon('i')
            ->setMandatoryServerClusters([])
            ->setOptionalServerClusters([])
            ->setMandatoryClientClusters([])
            ->setOptionalClientClusters([])
            ->setScoringWeights(null)
            ->setCreatedAt(new \DateTime())
            ->setUpdatedAt(new \DateTime());

        $this->assertSame($deviceType, $result);
    }
}
