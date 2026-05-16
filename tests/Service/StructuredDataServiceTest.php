<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Entity\Product;
use App\Entity\Vendor;
use App\Service\AeoLedeService;
use App\Service\StructuredDataService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Unit tests for StructuredDataService. Uses a stub UrlGeneratorInterface so
 * the service can be exercised without booting Symfony. Verifies the
 * schema.org type per entity, that dateModified is emitted as YYYY-MM-DD,
 * that sameAs is included only when vendorLandingPageURL is set, that the
 * JSON-LD description equals the AeoLedeService output byte-for-byte, that
 * BreadcrumbList items are 1-indexed and contiguous, and that the Dataset
 * payload includes name, description, creator, license, temporalCoverage,
 * and dateModified.
 */
final class StructuredDataServiceTest extends TestCase
{
    private StructuredDataService $svc;
    private AeoLedeService $lede;

    protected function setUp(): void
    {
        $this->lede = new AeoLedeService();
        $this->svc = new StructuredDataService(
            $this->lede,
            new StubUrlGenerator(),
            'https://matter-survey.org',
        );
    }

    public function testDeviceJsonLdHasExpectedShape(): void
    {
        $product = new Product()
            ->setVendorId(0x115F)
            ->setProductId(0x2002)
            ->setVendorName('Aqara')
            ->setProductName('FP2')
            ->setSlug('aqara-fp2-4447-8194');

        $modified = new \DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $jsonLd = $this->svc->deviceJsonLd(
            $product,
            endpointCount: 3,
            dateModified: $modified,
        );

        $this->assertSame('https://schema.org', $jsonLd['@context']);
        $this->assertSame('Product', $jsonLd['@type']);
        $this->assertSame('Aqara FP2', $jsonLd['name']);
        $this->assertSame('2026-05-12', $jsonLd['dateModified']);
        $this->assertSame($this->lede->ledeForDevice($product, 3), $jsonLd['description'], 'JSON-LD description must equal AeoLedeService output');
        $this->assertSame('Aqara', $jsonLd['manufacturer']['name']);
    }

    public function testVendorJsonLdIncludesSameAsWhenLandingPageSet(): void
    {
        $vendor = new Vendor()
            ->setName('Aqara')
            ->setSlug('aqara')
            ->setVendorLandingPageURL('https://www.aqara.com');

        $jsonLd = $this->svc->vendorJsonLd(
            $vendor,
            productCount: 47,
            dateModified: new \DateTimeImmutable('2026-05-10T00:00:00Z'),
        );

        $this->assertSame('Organization', $jsonLd['@type']);
        $this->assertSame(['https://www.aqara.com'], $jsonLd['sameAs']);
        $this->assertSame('2026-05-10', $jsonLd['dateModified']);
        $this->assertSame($this->lede->ledeForVendor($vendor, 47), $jsonLd['description']);
    }

    public function testVendorJsonLdOmitsSameAsWhenLandingPageNull(): void
    {
        $vendor = new Vendor()->setName('NoSite')->setSlug('nosite');

        $jsonLd = $this->svc->vendorJsonLd(
            $vendor,
            productCount: 1,
            dateModified: new \DateTimeImmutable('2026-05-10'),
        );

        $this->assertArrayNotHasKey('sameAs', $jsonLd);
    }

    public function testVendorJsonLdOmitsSameAsWhenLandingPageEmpty(): void
    {
        $vendor = new Vendor()->setName('EmptySite')->setSlug('emptysite')
            ->setVendorLandingPageURL('');

        $jsonLd = $this->svc->vendorJsonLd(
            $vendor,
            productCount: 1,
            dateModified: new \DateTimeImmutable('2026-05-10'),
        );

        $this->assertArrayNotHasKey('sameAs', $jsonLd);
    }

    public function testVendorJsonLdOmitsSameAsWhenLandingPageInvalid(): void
    {
        $vendor = new Vendor()->setName('Junk')->setSlug('junk')
            ->setVendorLandingPageURL('not-a-url');

        $jsonLd = $this->svc->vendorJsonLd(
            $vendor,
            productCount: 1,
            dateModified: new \DateTimeImmutable('2026-05-10'),
        );

        $this->assertArrayNotHasKey('sameAs', $jsonLd);
    }

    public function testClusterJsonLdHasDefinedTermShape(): void
    {
        $cluster = new Cluster(0x0006)
            ->setName('OnOff')
            ->setDescription('provides on/off control for endpoints');

        $jsonLd = $this->svc->clusterJsonLd(
            $cluster,
            totalDevices: 100,
            mandatoryForCount: 12,
            dateModified: new \DateTimeImmutable('2026-05-16'),
            commandCount: 3,
            attributeCount: 1,
        );

        $this->assertSame('DefinedTerm', $jsonLd['@type']);
        $this->assertSame('OnOff', $jsonLd['name']);
        $this->assertSame('0x0006', $jsonLd['termCode']);
        $this->assertSame('2026-05-16', $jsonLd['dateModified']);
        $this->assertSame($this->lede->ledeForCluster($cluster, 12, 3, 1), $jsonLd['description']);
    }

    public function testDeviceTypeJsonLdHasDefinedTermShape(): void
    {
        $deviceType = new DeviceType(0x0100)
            ->setName('OnOff Light')
            ->setDescription('defines a basic on/off lighting endpoint')
            ->setMandatoryServerClusters([3, 4, 6])
            ->setOptionalServerClusters([8]);

        $jsonLd = $this->svc->deviceTypeJsonLd(
            $deviceType,
            totalDevices: 250,
            dateModified: new \DateTimeImmutable('2026-05-16'),
        );

        $this->assertSame('DefinedTerm', $jsonLd['@type']);
        $this->assertSame('OnOff Light', $jsonLd['name']);
        $this->assertSame('0x0100', $jsonLd['termCode']);
        $this->assertSame('2026-05-16', $jsonLd['dateModified']);
        $this->assertSame($this->lede->ledeForDeviceType($deviceType, 250), $jsonLd['description']);
    }

    public function testDatasetJsonLdHasRequiredFields(): void
    {
        $jsonLd = $this->svc->datasetJsonLd(
            name: 'Matter Cluster Statistics',
            description: 'Aggregate cluster usage across all submitted Matter devices.',
            dateModified: new \DateTimeImmutable('2026-05-16'),
            coverageStart: new \DateTimeImmutable('2024-01-01'),
        );

        $this->assertSame('Dataset', $jsonLd['@type']);
        $this->assertSame('Matter Cluster Statistics', $jsonLd['name']);
        $this->assertSame('2026-05-16', $jsonLd['dateModified']);
        $this->assertSame('2024-01-01/..', $jsonLd['temporalCoverage']);
        $this->assertSame('Organization', $jsonLd['creator']['@type']);
        $this->assertSame('Matter Survey', $jsonLd['creator']['name']);
        $this->assertSame('https://matter-survey.org', $jsonLd['creator']['url']);
        $this->assertSame('https://creativecommons.org/publicdomain/zero/1.0/', $jsonLd['license']);
    }

    public function testDatasetJsonLdWithCoverageEnd(): void
    {
        $jsonLd = $this->svc->datasetJsonLd(
            name: 'Snapshot',
            description: 'A bounded snapshot.',
            dateModified: new \DateTimeImmutable('2026-05-16'),
            coverageStart: new \DateTimeImmutable('2024-01-01'),
            coverageEnd: new \DateTimeImmutable('2026-05-01'),
        );

        $this->assertSame('2024-01-01/2026-05-01', $jsonLd['temporalCoverage']);
    }

    public function testBreadcrumbListIsContiguousAndOneIndexed(): void
    {
        $jsonLd = $this->svc->breadcrumbListJsonLd([
            ['name' => 'Home', 'url' => 'https://matter-survey.org/'],
            ['name' => 'Devices', 'url' => 'https://matter-survey.org/devices'],
            ['name' => 'Aqara', 'url' => 'https://matter-survey.org/vendor/aqara'],
            ['name' => 'FP2', 'url' => 'https://matter-survey.org/device/aqara-fp2'],
        ]);

        $this->assertSame('BreadcrumbList', $jsonLd['@type']);
        $this->assertCount(4, $jsonLd['itemListElement']);

        foreach ($jsonLd['itemListElement'] as $i => $item) {
            $this->assertSame($i + 1, $item['position'], "position $i+1");
            $this->assertSame('ListItem', $item['@type']);
            $this->assertArrayHasKey('name', $item);
            $this->assertArrayHasKey('item', $item);
        }
    }

    public function testBreadcrumbListEmpty(): void
    {
        $jsonLd = $this->svc->breadcrumbListJsonLd([]);

        $this->assertSame('BreadcrumbList', $jsonLd['@type']);
        $this->assertSame([], $jsonLd['itemListElement']);
    }
}

/**
 * Minimal in-test URL generator stub. Returns a deterministic absolute URL
 * built from the given route + params so tests don't need a kernel.
 */
final class StubUrlGenerator implements UrlGeneratorInterface
{
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $base = 'https://matter-survey.org';
        $route = '/'.str_replace('_', '/', $name);
        $qs = [] !== $parameters ? '?'.http_build_query($parameters) : '';

        return self::ABSOLUTE_URL === $referenceType ? $base.$route.$qs : $route.$qs;
    }

    public function setContext(\Symfony\Component\Routing\RequestContext $context): void
    {
    }

    public function getContext(): \Symfony\Component\Routing\RequestContext
    {
        return new \Symfony\Component\Routing\RequestContext();
    }
}
