<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Vendor;
use App\Repository\DeviceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeviceRepositoryTest extends KernelTestCase
{
    private DeviceRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DeviceRepository::class);
    }

    public function testIsNameAmbiguousFlagsDuplicateNamesPerVendor(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
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
            fn (array $d): bool => 'Eve Motion' === ($d['product_name'] ?? null)
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
            fn (array $d): bool => 'Hue Bridge' === ($d['product_name'] ?? null)
        ));
        $this->assertCount(1, $unique);
        $this->assertFalse(
            (bool) $unique[0]['is_name_ambiguous'],
            'A product with a unique name should not be flagged ambiguous'
        );
    }

    public function testGetTopDeviceTypesByVendorExcludesSystemTypes(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $isNew = false;
        $deviceId = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 31337,
            'product_name' => 'Eve System Probe',
        ], $isNew);

        // Endpoint 0: Root Node (id 22, a system type).
        $this->repository->upsertEndpoint($deviceId, [
            'endpoint_id' => 0,
            'device_types' => [['id' => 22, 'revision' => 1]],
            'server_clusters' => [],
            'client_clusters' => [],
        ]);
        // Endpoint 1: On/Off Light (id 256, a real application type).
        $this->repository->upsertEndpoint($deviceId, [
            'endpoint_id' => 1,
            'device_types' => [['id' => 256, 'revision' => 1]],
            'server_clusters' => [6],
            'client_clusters' => [],
        ]);

        $byVendor = $this->repository->getTopDeviceTypesByVendor(4);
        $ids = $byVendor[$vendor->getId()] ?? [];

        $this->assertNotContains(22, $ids, 'Root Node should be filtered out');
        $this->assertContains(256, $ids, 'Application device types should still appear');
    }

    public function testGetDeviceBySlugAttachesAmbiguityFlag(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
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
        $someEveMotion = array_find($devices, fn ($d): bool => 'Eve Motion' === ($d['product_name'] ?? null));
        $this->assertNotNull($someEveMotion);

        $bySlug = $this->repository->getDeviceBySlug($someEveMotion['slug']);
        $this->assertNotNull($bySlug);
        $this->assertTrue((bool) $bySlug['is_name_ambiguous']);
    }
}
