<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Vendor;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class DeviceRepositoryTest extends KernelTestCase
{
    private DeviceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(DeviceRepository::class);
    }

    public function testIsNameAmbiguousFlagsDuplicateNamesPerVendor(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor, 'Eve fixture vendor (specId 4874) should exist');

        $isNew = false;
        $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 9999,
            'product_name' => 'Eve Motion',
        ], $isNew);

        $devices = $this->repository->getFilteredDevices([], 200, 0);

        $eveMotion = array_values(array_filter(
            $devices,
            fn (array $d) => 'Eve Motion' === ($d['product_name'] ?? null)
        ));
        $this->assertCount(2, $eveMotion, 'Both Eve Motion entries should be returned');
        foreach ($eveMotion as $row) {
            $this->assertTrue(
                (bool) $row['is_name_ambiguous'],
                'Eve Motion rows should be flagged ambiguous when duplicates exist'
            );
        }

        $unique = array_values(array_filter(
            $devices,
            fn (array $d) => 'Hue Bridge' === ($d['product_name'] ?? null)
        ));
        $this->assertCount(1, $unique);
        $this->assertFalse(
            (bool) $unique[0]['is_name_ambiguous'],
            'A product with a unique name should not be flagged ambiguous'
        );
    }

    public function testGetDeviceBySlugAttachesAmbiguityFlag(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $isNew = false;
        $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 9999,
            'product_name' => 'Eve Motion',
        ], $isNew);

        $devices = $this->repository->getFilteredDevices([], 200, 0);
        $someEveMotion = null;
        foreach ($devices as $d) {
            if ('Eve Motion' === ($d['product_name'] ?? null)) {
                $someEveMotion = $d;
                break;
            }
        }
        $this->assertNotNull($someEveMotion);

        $bySlug = $this->repository->getDeviceBySlug($someEveMotion['slug']);
        $this->assertNotNull($bySlug);
        $this->assertTrue((bool) $bySlug['is_name_ambiguous']);
    }
}
