<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\Vendor;
use PHPUnit\Framework\TestCase;

class ProductTest extends TestCase
{
    public function testNewProductHasDefaultValues(): void
    {
        $product = new Product();

        $this->assertNull($product->getId());
        $this->assertNull($product->getSlug());
        $this->assertNull($product->getVendorName());
        $this->assertNull($product->getProductName());
        $this->assertNull($product->getFirstSeen());
        $this->assertNull($product->getLastSeen());
        $this->assertSame(0, $product->getSubmissionCount());
        $this->assertNull($product->getVendor());
        $this->assertNull($product->getDeviceTypeId());
        $this->assertNull($product->getPartNumber());
        $this->assertNull($product->getProductUrl());
        $this->assertNull($product->getSupportUrl());
        $this->assertNull($product->getUserManualUrl());
        $this->assertNull($product->getDiscoveryCapabilitiesBitmask());
        $this->assertNull($product->getCommissioningCustomFlow());
        $this->assertNull($product->getCommissioningCustomFlowUrl());
        $this->assertNull($product->getCommissioningInitialStepsHint());
        $this->assertNull($product->getCommissioningInitialStepsInstruction());
        $this->assertNull($product->getMaintenanceUrl());
        $this->assertNull($product->getFactoryResetStepsHint());
        $this->assertNull($product->getFactoryResetStepsInstruction());
        $this->assertNull($product->getCommissioningSecondaryStepsHint());
        $this->assertNull($product->getCommissioningSecondaryStepsInstruction());
        $this->assertNull($product->getCommissioningFallbackUrl());
        $this->assertNull($product->getIcdUserActiveModeTriggerHint());
        $this->assertNull($product->getIcdUserActiveModeTriggerInstruction());
        $this->assertNull($product->getLsfUrl());
        $this->assertNull($product->getLsfRevision());
        $this->assertNull($product->getCertifiedSoftwareVersions());
        $this->assertNull($product->getConnectivityTypes());
        $this->assertNull($product->getCertificationDate());
        $this->assertNull($product->getCertificateId());
        $this->assertNull($product->getSoftwareVersionString());
        $this->assertNull($product->getScore());
        $this->assertCount(0, $product->getVersions());
        $this->assertCount(0, $product->getEndpoints());
    }

    public function testIdentityFieldsRoundTrip(): void
    {
        $product = (new Product())
            ->setVendorId(4874)
            ->setProductId(100)
            ->setVendorName('Eve')
            ->setProductName('Eve Motion')
            ->setSlug('eve-motion-4874-100');

        $this->assertSame(4874, $product->getVendorId());
        $this->assertSame(100, $product->getProductId());
        $this->assertSame('Eve', $product->getVendorName());
        $this->assertSame('Eve Motion', $product->getProductName());
        $this->assertSame('eve-motion-4874-100', $product->getSlug());
    }

    public function testTimestampsAndCountersRoundTrip(): void
    {
        $first = new \DateTime('2025-01-01 00:00:00');
        $last = new \DateTime('2025-06-01 12:00:00');

        $product = (new Product())
            ->setFirstSeen($first)
            ->setLastSeen($last)
            ->setSubmissionCount(42);

        $this->assertSame($first, $product->getFirstSeen());
        $this->assertSame($last, $product->getLastSeen());
        $this->assertSame(42, $product->getSubmissionCount());
    }

    public function testIncrementSubmissionCountUpdatesCountAndLastSeen(): void
    {
        $product = new Product();
        $before = new \DateTimeImmutable();

        $product->incrementSubmissionCount();

        $this->assertSame(1, $product->getSubmissionCount());
        $this->assertNotNull($product->getLastSeen());
        $this->assertGreaterThanOrEqual($before->getTimestamp(), $product->getLastSeen()->getTimestamp());

        $product->incrementSubmissionCount();
        $this->assertSame(2, $product->getSubmissionCount());
    }

    public function testVendorRelation(): void
    {
        $vendor = new Vendor();
        $product = (new Product())->setVendor($vendor);

        $this->assertSame($vendor, $product->getVendor());

        $product->setVendor(null);
        $this->assertNull($product->getVendor());
    }

    public function testMetadataFieldsRoundTrip(): void
    {
        $product = (new Product())
            ->setDeviceTypeId(266)
            ->setPartNumber('PN-001')
            ->setProductUrl('https://example.com/product')
            ->setSupportUrl('https://example.com/support')
            ->setUserManualUrl('https://example.com/manual');

        $this->assertSame(266, $product->getDeviceTypeId());
        $this->assertSame('PN-001', $product->getPartNumber());
        $this->assertSame('https://example.com/product', $product->getProductUrl());
        $this->assertSame('https://example.com/support', $product->getSupportUrl());
        $this->assertSame('https://example.com/manual', $product->getUserManualUrl());
    }

    public function testCommissioningAndMaintenanceFieldsRoundTrip(): void
    {
        $product = (new Product())
            ->setDiscoveryCapabilitiesBitmask(6)
            ->setCommissioningCustomFlow(1)
            ->setCommissioningCustomFlowUrl('https://example.com/flow')
            ->setCommissioningInitialStepsHint(2)
            ->setCommissioningInitialStepsInstruction('Long-press button')
            ->setMaintenanceUrl('https://example.com/maintenance')
            ->setFactoryResetStepsHint(4)
            ->setFactoryResetStepsInstruction('Hold reset 10s')
            ->setCommissioningSecondaryStepsHint(8)
            ->setCommissioningSecondaryStepsInstruction('Scan QR')
            ->setCommissioningFallbackUrl('https://example.com/fallback')
            ->setIcdUserActiveModeTriggerHint(16)
            ->setIcdUserActiveModeTriggerInstruction('Wake the device');

        $this->assertSame(6, $product->getDiscoveryCapabilitiesBitmask());
        $this->assertSame(1, $product->getCommissioningCustomFlow());
        $this->assertSame('https://example.com/flow', $product->getCommissioningCustomFlowUrl());
        $this->assertSame(2, $product->getCommissioningInitialStepsHint());
        $this->assertSame('Long-press button', $product->getCommissioningInitialStepsInstruction());
        $this->assertSame('https://example.com/maintenance', $product->getMaintenanceUrl());
        $this->assertSame(4, $product->getFactoryResetStepsHint());
        $this->assertSame('Hold reset 10s', $product->getFactoryResetStepsInstruction());
        $this->assertSame(8, $product->getCommissioningSecondaryStepsHint());
        $this->assertSame('Scan QR', $product->getCommissioningSecondaryStepsInstruction());
        $this->assertSame('https://example.com/fallback', $product->getCommissioningFallbackUrl());
        $this->assertSame(16, $product->getIcdUserActiveModeTriggerHint());
        $this->assertSame('Wake the device', $product->getIcdUserActiveModeTriggerInstruction());
    }

    public function testDclMetadataFieldsRoundTrip(): void
    {
        $certDate = new \DateTime('2025-03-15');

        $product = (new Product())
            ->setLsfUrl('https://example.com/lsf')
            ->setLsfRevision(3)
            ->setCertifiedSoftwareVersions([1, 2, 3])
            ->setCertificationDate($certDate)
            ->setCertificateId('CSA2444CMAT43775-24')
            ->setSoftwareVersionString('2.0');

        $this->assertSame('https://example.com/lsf', $product->getLsfUrl());
        $this->assertSame(3, $product->getLsfRevision());
        $this->assertSame([1, 2, 3], $product->getCertifiedSoftwareVersions());
        $this->assertSame($certDate, $product->getCertificationDate());
        $this->assertSame('CSA2444CMAT43775-24', $product->getCertificateId());
        $this->assertSame('2.0', $product->getSoftwareVersionString());
    }

    public function testConnectivityTypesSetterAccepts(): void
    {
        $product = (new Product())->setConnectivityTypes(['wifi', 'thread']);

        $this->assertSame(['wifi', 'thread'], $product->getConnectivityTypes());
    }

    public function testMergeConnectivityTypesAddsNewValuesSortedAndUnique(): void
    {
        $product = new Product();
        $product->setConnectivityTypes(['wifi']);

        $product->mergeConnectivityTypes(['thread', 'wifi']);

        // merged + unique + sorted alphabetically
        $this->assertSame(['thread', 'wifi'], $product->getConnectivityTypes());
    }

    public function testMergeConnectivityTypesFromNullStartsFresh(): void
    {
        $product = new Product();

        $product->mergeConnectivityTypes(['ethernet', 'wifi']);

        $this->assertSame(['ethernet', 'wifi'], $product->getConnectivityTypes());
    }

    public function testMergeConnectivityTypesWithEmptyArrayNullsField(): void
    {
        $product = new Product();

        // Starting from null, merging empty array yields empty merged array.
        // The setter coalesces empty to null per the implementation.
        $product->mergeConnectivityTypes([]);

        $this->assertNull($product->getConnectivityTypes());
    }

    public function testGenerateSlugWithProductName(): void
    {
        $this->assertSame(
            'eve-motion-4874-100',
            Product::generateSlug('Eve Motion', 4874, 100),
        );
    }

    public function testGenerateSlugStripsSpecialCharactersAndCollapsesDashes(): void
    {
        $this->assertSame(
            'philips-hue-go-2-4107-50',
            Product::generateSlug('Philips Hue / Go 2!', 4107, 50),
        );
    }

    public function testGenerateSlugCollapsesUnderscoresAndMultipleSpaces(): void
    {
        // Underscores aren't in the allow-list, so they're stripped before the
        // whitespace/underscore collapsing step ever sees them. Trailing spaces
        // collapse to a single dash, then trim removes it.
        $this->assertSame(
            'foobar-1-2',
            Product::generateSlug('foo___bar   ', 1, 2),
        );
    }

    public function testGenerateSlugWithNullProductName(): void
    {
        $this->assertSame(
            'product-4874-100',
            Product::generateSlug(null, 4874, 100),
        );
    }

    public function testGenerateSlugWithEmptyProductName(): void
    {
        $this->assertSame(
            'product-1-2',
            Product::generateSlug('', 1, 2),
        );
    }

    public function testGenerateSlugWithOnlySpecialCharactersFallsBackToVendorProductId(): void
    {
        // Name reduces to empty after sanitization → "product-{vid}-{pid}"
        $this->assertSame(
            'product-1-2',
            Product::generateSlug('!!!@@@###', 1, 2),
        );
    }

    public function testFluentInterface(): void
    {
        $product = new Product();
        $result = $product
            ->setVendorId(1)
            ->setProductId(2)
            ->setVendorName('Vendor')
            ->setProductName('Product')
            ->setSlug('vendor-product-1-2')
            ->setFirstSeen(new \DateTime())
            ->setLastSeen(new \DateTime())
            ->setSubmissionCount(0)
            ->setDeviceTypeId(1)
            ->setPartNumber('PN')
            ->setProductUrl('u')
            ->setSupportUrl('u')
            ->setUserManualUrl('u')
            ->setDiscoveryCapabilitiesBitmask(0)
            ->setCommissioningCustomFlow(0)
            ->setCommissioningCustomFlowUrl('u')
            ->setCommissioningInitialStepsHint(0)
            ->setCommissioningInitialStepsInstruction('s')
            ->setMaintenanceUrl('u')
            ->setFactoryResetStepsHint(0)
            ->setFactoryResetStepsInstruction('s')
            ->setCommissioningSecondaryStepsHint(0)
            ->setCommissioningSecondaryStepsInstruction('s')
            ->setCommissioningFallbackUrl('u')
            ->setIcdUserActiveModeTriggerHint(0)
            ->setIcdUserActiveModeTriggerInstruction('s')
            ->setLsfUrl('u')
            ->setLsfRevision(0)
            ->setCertifiedSoftwareVersions(null)
            ->setConnectivityTypes(null)
            ->setCertificationDate(new \DateTime())
            ->setCertificateId('c')
            ->setSoftwareVersionString('1.0');

        $this->assertSame($product, $result);
    }
}
