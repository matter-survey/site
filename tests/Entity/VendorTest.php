<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Vendor;
use PHPUnit\Framework\TestCase;

final class VendorTest extends TestCase
{
    public function testDefaults(): void
    {
        $vendor = new Vendor();

        $this->assertNull($vendor->getId());
        $this->assertNull($vendor->getSpecId());
        $this->assertSame(0, $vendor->getDeviceCount());
        $this->assertNull($vendor->getCompanyLegalName());
        $this->assertNull($vendor->getVendorLandingPageURL());
        $this->assertInstanceOf(\DateTimeInterface::class, $vendor->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $vendor->getUpdatedAt());
    }

    public function testFieldsRoundTrip(): void
    {
        $vendor = new Vendor()
            ->setName('Acme')
            ->setSlug('acme-1')
            ->setSpecId(1234)
            ->setDeviceCount(5)
            ->setCompanyLegalName('Acme Industries Inc.')
            ->setVendorLandingPageURL('https://acme.example.com');

        $this->assertSame('Acme', $vendor->getName());
        $this->assertSame('acme-1', $vendor->getSlug());
        $this->assertSame(1234, $vendor->getSpecId());
        $this->assertSame(5, $vendor->getDeviceCount());
        $this->assertSame('Acme Industries Inc.', $vendor->getCompanyLegalName());
        $this->assertSame('https://acme.example.com', $vendor->getVendorLandingPageURL());
    }

    public function testIncrementDeviceCount(): void
    {
        $vendor = new Vendor();
        $this->assertSame(0, $vendor->getDeviceCount());

        $vendor->incrementDeviceCount();
        $this->assertSame(1, $vendor->getDeviceCount());

        $vendor->incrementDeviceCount();
        $this->assertSame(2, $vendor->getDeviceCount());
    }

    public function testTimestampSetters(): void
    {
        $created = new \DateTime('2025-01-01');
        $updated = new \DateTime('2025-06-01');

        $vendor = new Vendor()->setCreatedAt($created)->setUpdatedAt($updated);

        $this->assertSame($created, $vendor->getCreatedAt());
        $this->assertSame($updated, $vendor->getUpdatedAt());
    }

    public function testGenerateSlugWithName(): void
    {
        $this->assertSame('acme-co', Vendor::generateSlug('Acme & Co.'));
    }

    public function testGenerateSlugFallsBackToSpecIdWhenNameProducesEmpty(): void
    {
        $this->assertSame('vendor-42', Vendor::generateSlug('!!!', 42));
    }

    public function testGenerateSlugFallsBackToUnknownWhenNoNameOrSpecId(): void
    {
        $this->assertSame('vendor-unknown', Vendor::generateSlug('!!!'));
    }

    public function testGenerateSlugEmptyNameWithSpecId(): void
    {
        $this->assertSame('vendor-99', Vendor::generateSlug('', 99));
    }

    public function testCanonicalSlugAppendsSpecId(): void
    {
        $this->assertSame('tasmota-5181', Vendor::canonicalSlug('Tasmota', 5181));
    }

    public function testCanonicalSlugAvoidsDoubleSuffixWhenNameSlugifiesToEmpty(): void
    {
        $this->assertSame('vendor-42', Vendor::canonicalSlug('!!!', 42));
    }

    public function testFluentInterface(): void
    {
        $vendor = new Vendor();
        $result = $vendor
            ->setName('n')
            ->setSlug('s')
            ->setSpecId(1)
            ->setDeviceCount(0)
            ->setCompanyLegalName('l')
            ->setVendorLandingPageURL('u')
            ->setCreatedAt(new \DateTime())
            ->setUpdatedAt(new \DateTime());

        $this->assertSame($vendor, $result);
    }
}
