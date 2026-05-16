<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Entity\Product;
use App\Entity\Vendor;
use App\Service\AeoLedeService;
use PHPUnit\Framework\TestCase;

/**
 * Pure-unit tests for AeoLedeService: no kernel boot, no DB. Tests cover the
 * happy path for each entity type plus edge cases (zero counts, missing
 * descriptions, missing names) called out in tasks 3.3.
 */
final class AeoLedeServiceTest extends TestCase
{
    private AeoLedeService $svc;

    protected function setUp(): void
    {
        $this->svc = new AeoLedeService();
    }

    public function testDeviceLedeHappyPath(): void
    {
        $product = new Product()
            ->setVendorId(0x115F)
            ->setProductId(0x2002)
            ->setVendorName('Aqara')
            ->setProductName('FP2');

        $lede = $this->svc->ledeForDevice($product, endpointCount: 3);

        $this->assertStringContainsString('FP2', $lede);
        $this->assertStringContainsString('Aqara', $lede);
        $this->assertStringContainsString('0x115F', $lede);
        $this->assertStringContainsString('0x2002', $lede);
        $this->assertStringContainsString('3 endpoints', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testDeviceLedeSingularEndpoint(): void
    {
        $product = new Product()
            ->setVendorId(0x115F)
            ->setProductId(0x2002)
            ->setVendorName('Aqara')
            ->setProductName('Mini Plug');

        $lede = $this->svc->ledeForDevice($product, endpointCount: 1);

        $this->assertStringContainsString('1 endpoint', $lede);
        $this->assertStringNotContainsString('1 endpoints', $lede);
    }

    public function testDeviceLedeZeroEndpointsOmitsEndpointClause(): void
    {
        $product = new Product()
            ->setVendorId(0x115F)
            ->setProductId(0x2002)
            ->setVendorName('Aqara')
            ->setProductName('FP2');

        $lede = $this->svc->ledeForDevice($product, endpointCount: 0);

        $this->assertStringNotContainsString('0 endpoint', $lede);
        $this->assertStringContainsString('FP2', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testDeviceLedeMissingProductNameFallsBack(): void
    {
        $product = new Product()
            ->setVendorId(0x115F)
            ->setProductId(0x2002)
            ->setVendorName('Aqara');
        // productName intentionally null

        $lede = $this->svc->ledeForDevice($product, endpointCount: 2);

        $this->assertStringContainsString('Aqara', $lede);
        $this->assertStringContainsString('0x115F', $lede);
        $this->assertStringContainsString('0x2002', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testVendorLedeHappyPath(): void
    {
        $vendor = new Vendor()->setName('Aqara');

        $lede = $this->svc->ledeForVendor($vendor, productCount: 47);

        $this->assertStringContainsString('Aqara', $lede);
        $this->assertStringContainsString('47', $lede);
        $this->assertStringContainsString('Matter', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testVendorLedeSingularProduct(): void
    {
        $vendor = new Vendor()->setName('Tiny Vendor');

        $lede = $this->svc->ledeForVendor($vendor, productCount: 1);

        $this->assertStringContainsString('1 Matter-certified product', $lede);
        $this->assertStringNotContainsString('1 Matter-certified products', $lede);
    }

    public function testVendorLedeZeroProductsOmitsProductClause(): void
    {
        $vendor = new Vendor()->setName('New Vendor');

        $lede = $this->svc->ledeForVendor($vendor, productCount: 0);

        $this->assertStringContainsString('New Vendor', $lede);
        $this->assertStringNotContainsString('0 Matter-certified product', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testClusterLedeHappyPath(): void
    {
        $cluster = new Cluster(0x0006)
            ->setName('OnOff')
            ->setDescription('provides on/off control for endpoints');

        $lede = $this->svc->ledeForCluster($cluster, mandatoryForCount: 12, commandCount: 3, attributeCount: 1);

        $this->assertStringContainsString('OnOff cluster', $lede);
        $this->assertStringContainsString('0x0006', $lede);
        $this->assertStringContainsString('provides on/off control', $lede);
        $this->assertStringContainsString('3 commands', $lede);
        $this->assertStringContainsString('1 attribute', $lede);
        $this->assertStringContainsString('12 device types', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testClusterLedeSingularCounts(): void
    {
        $cluster = new Cluster(0x0028)
            ->setName('BasicInformation')
            ->setDescription('reports basic device information');

        $lede = $this->svc->ledeForCluster($cluster, mandatoryForCount: 1, commandCount: 1, attributeCount: 1);

        $this->assertStringContainsString('1 command', $lede);
        $this->assertStringNotContainsString('1 commands', $lede);
        $this->assertStringContainsString('1 attribute', $lede);
        $this->assertStringNotContainsString('1 attributes', $lede);
        $this->assertStringContainsString('1 device type', $lede);
        $this->assertStringNotContainsString('1 device types', $lede);
    }

    public function testClusterLedeMissingDescriptionOmitsClause(): void
    {
        $cluster = new Cluster(0x9999)
            ->setName('Custom');

        $lede = $this->svc->ledeForCluster($cluster, mandatoryForCount: 0);

        $this->assertStringContainsString('Custom cluster', $lede);
        $this->assertStringContainsString('0x9999', $lede);
        $this->assertStringNotContainsString('0 commands', $lede);
        $this->assertStringNotContainsString('0 attributes', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testDeviceTypeLedeHappyPath(): void
    {
        $deviceType = new DeviceType(0x0100)
            ->setName('OnOff Light')
            ->setDescription('defines a basic on/off lighting endpoint')
            ->setMandatoryServerClusters([0x0003, 0x0004, 0x0006])
            ->setOptionalServerClusters([0x0008, 0x0029]);

        $lede = $this->svc->ledeForDeviceType($deviceType, totalDevices: 0);

        $this->assertStringContainsString('OnOff Light', $lede);
        $this->assertStringContainsString('0x0100', $lede);
        $this->assertStringContainsString('basic on/off lighting', $lede);
        $this->assertStringContainsString('3 mandatory', $lede);
        $this->assertStringContainsString('2 optional', $lede);
        $this->assertEndsWithSingleSentence($lede);
    }

    public function testDeviceTypeLedeSingularCounts(): void
    {
        $deviceType = new DeviceType(0x010A)
            ->setName('OnOff Plug-in Unit')
            ->setMandatoryServerClusters([0x0006])
            ->setOptionalServerClusters([0x0008]);

        $lede = $this->svc->ledeForDeviceType($deviceType, totalDevices: 0);

        $this->assertStringContainsString('1 mandatory server cluster', $lede);
        $this->assertStringNotContainsString('1 mandatory server clusters', $lede);
        $this->assertStringContainsString('1 optional server cluster', $lede);
        $this->assertStringNotContainsString('1 optional server clusters', $lede);
    }

    public function testEachLedeIsOneSentence(): void
    {
        // Belt and suspenders: a sentinel across all four entity types that
        // none of them produce double-periods or stray exclamation marks.
        $product = new Product()
            ->setVendorId(1)->setProductId(1)->setVendorName('V')->setProductName('P');
        $vendor = new Vendor()->setName('V');
        $cluster = new Cluster(1)->setName('C')->setDescription('does X');
        $deviceType = new DeviceType(1)->setName('DT')->setDescription('describes Y');

        foreach ([
            $this->svc->ledeForDevice($product, 1),
            $this->svc->ledeForVendor($vendor, 1),
            $this->svc->ledeForCluster($cluster, 1),
            $this->svc->ledeForDeviceType($deviceType, 1),
        ] as $lede) {
            $this->assertEndsWithSingleSentence($lede);
            $this->assertStringNotContainsString('..', $lede);
            $this->assertStringNotContainsString(' .', $lede);
        }
    }

    /**
     * Lede sentences may be 1-2 grammatical sentences (e.g. cluster ledes
     * separate the descriptive clause from the count clause). The invariant
     * is: ends with a single period, no double periods, no stray spaces
     * before punctuation, no exclamations or question marks (declarative
     * only).
     */
    private function assertEndsWithSingleSentence(string $sentence): void
    {
        $this->assertNotEmpty($sentence, 'lede must not be empty');
        $this->assertSame('.', substr($sentence, -1), "lede must end with a period: '$sentence'");
        $this->assertStringNotContainsString('..', $sentence, "lede must not contain double periods: '$sentence'");
        $this->assertStringNotContainsString(' .', $sentence, "lede must not contain spaces before periods: '$sentence'");
        $this->assertStringNotContainsString('!', $sentence, "lede must be declarative: '$sentence'");
        $this->assertStringNotContainsString('?', $sentence, "lede must be declarative: '$sentence'");
    }
}
