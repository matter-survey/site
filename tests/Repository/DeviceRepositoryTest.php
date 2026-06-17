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

    public function testGetDeviceTypeDistributionByVendorGroupsByTypeAndExcludesSystem(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $isNew = false;
        $deviceA = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 31410,
            'product_name' => 'Eve A',
        ], $isNew);
        $deviceB = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 31411,
            'product_name' => 'Eve B',
        ], $isNew);

        // Both devices get Root Node on ep0 and an On/Off Light on ep1.
        foreach ([$deviceA, $deviceB] as $id) {
            $this->repository->upsertEndpoint($id, [
                'endpoint_id' => 0,
                'device_types' => [['id' => 22, 'revision' => 1]],
                'server_clusters' => [],
                'client_clusters' => [],
            ]);
            $this->repository->upsertEndpoint($id, [
                'endpoint_id' => 1,
                'device_types' => [['id' => 256, 'revision' => 1]],
                'server_clusters' => [6],
                'client_clusters' => [],
            ]);
        }

        $rows = $this->repository->getDeviceTypeDistributionByVendor($vendor->getId());
        $byType = [];
        foreach ($rows as $r) {
            $byType[(int) $r['device_type_id']] = (int) $r['product_count'];
        }

        $this->assertArrayNotHasKey(22, $byType, 'Root Node should be excluded');
        $this->assertArrayHasKey(256, $byType, 'On/Off Light should be present');
        $this->assertGreaterThanOrEqual(2, $byType[256], 'Should count both Eve A and Eve B once each');
    }

    public function testGetClusterCapabilitiesByVendorExcludesUtilityClusters(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $isNew = false;
        $deviceId = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 31420,
            'product_name' => 'Eve Test Light',
        ], $isNew);

        // ep1: On/Off (6, application) + Basic Information (40, utility)
        $this->repository->upsertEndpoint($deviceId, [
            'endpoint_id' => 1,
            'device_types' => [['id' => 256, 'revision' => 1]],
            'server_clusters' => [6, 40],
            'client_clusters' => [],
        ]);

        $rows = $this->repository->getClusterCapabilitiesByVendor($vendor->getId());
        $clusterIds = array_map(static fn (array $r): int => (int) $r['cluster_id'], $rows);

        $this->assertContains(6, $clusterIds, 'On/Off (6) is an application cluster and should appear');
        $this->assertNotContains(40, $clusterIds, 'Basic Information (40) is utility and should be excluded');
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

    public function testCoordinationClusterDetection(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $vendor = $em->getRepository(Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $isNew = false;
        // Device with Groups (4), current Scenes Management (98), and Binding (30, client).
        $modernId = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 41001,
            'product_name' => 'Eve Coordination Modern',
        ], $isNew);
        $this->repository->upsertEndpoint($modernId, [
            'endpoint_id' => 1,
            'device_types' => [['id' => 256, 'revision' => 1]],
            'server_clusters' => [6, 4, 98],
            'client_clusters' => [30],
        ]);

        // Device with the deprecated Scenes cluster (5) only — no 98, no groups.
        $legacyId = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 41002,
            'product_name' => 'Eve Coordination Legacy',
        ], $isNew);
        $this->repository->upsertEndpoint($legacyId, [
            'endpoint_id' => 1,
            'device_types' => [['id' => 256, 'revision' => 1]],
            'server_clusters' => [6, 5],
            'client_clusters' => [],
        ]);

        // Device with no coordination clusters.
        $plainId = $this->repository->upsertDevice([
            'vendor_id' => 4874,
            'vendor_name' => $vendor->getName(),
            'vendor_fk' => $vendor->getId(),
            'product_id' => 41003,
            'product_name' => 'Eve Coordination None',
        ], $isNew);
        $this->repository->upsertEndpoint($plainId, [
            'endpoint_id' => 1,
            'device_types' => [['id' => 256, 'revision' => 1]],
            'server_clusters' => [6],
            'client_clusters' => [],
        ]);

        // Per-endpoint derivation flags.
        $modernEndpoints = $this->repository->getDeviceEndpoints($modernId);
        $this->assertTrue((bool) $modernEndpoints[0]['has_groups_cluster']);
        $this->assertTrue((bool) $modernEndpoints[0]['has_scenes_cluster']);
        $this->assertTrue((bool) $modernEndpoints[0]['has_binding_cluster']);

        $legacyEndpoints = $this->repository->getDeviceEndpoints($legacyId);
        $this->assertTrue((bool) $legacyEndpoints[0]['has_scenes_cluster'], 'Deprecated Scenes cluster (5) must count as scenes support');
        $this->assertFalse((bool) $legacyEndpoints[0]['has_groups_cluster']);

        // View-backed support columns via the coordination filters.
        $withGroups = $this->repository->getFilteredDevices(['groups' => true], 500, 0);
        $groupNames = array_column($withGroups, 'product_name');
        $this->assertContains('Eve Coordination Modern', $groupNames);
        $this->assertNotContains('Eve Coordination Legacy', $groupNames);
        $this->assertNotContains('Eve Coordination None', $groupNames);

        $withScenes = $this->repository->getFilteredDevices(['scenes' => true], 500, 0);
        $sceneNames = array_column($withScenes, 'product_name');
        $this->assertContains('Eve Coordination Modern', $sceneNames);
        $this->assertContains('Eve Coordination Legacy', $sceneNames, 'Legacy scenes device must match the scenes filter');
        $this->assertNotContains('Eve Coordination None', $sceneNames);
    }
}
