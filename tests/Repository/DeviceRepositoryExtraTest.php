<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\DeviceRepository;
use App\Service\MatterRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Coverage-focused tests for DeviceRepository methods that the existing
 * test only touched via integration paths. Exercises the stats/facets,
 * cluster/device-type slicers, and pagination/count parity.
 */
final class DeviceRepositoryExtraTest extends KernelTestCase
{
    private DeviceRepository $repository;
    private MatterRegistry $matterRegistry;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DeviceRepository::class);
        $this->matterRegistry = self::getContainer()->get(MatterRegistry::class);
    }

    public function testGetAllDevicesAndDeviceCountParity(): void
    {
        $total = $this->repository->getDeviceCount();

        $all = $this->repository->getAllDevices(1000, 0);
        $this->assertCount($total, $all);
    }

    public function testGetAllDevicesPagination(): void
    {
        $all = $this->repository->getAllDevices(100, 0);
        if (count($all) < 2) {
            $this->markTestSkipped('Need at least 2 fixture devices for pagination check');
        }

        $first = $this->repository->getAllDevices(1, 0);
        $second = $this->repository->getAllDevices(1, 1);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertNotSame($first[0]['id'], $second[0]['id']);
    }

    public function testGetFilteredDevicesNoFilters(): void
    {
        $devices = $this->repository->getFilteredDevices([], 200, 0);
        $count = $this->repository->getFilteredDeviceCount([]);

        $this->assertCount($count, $devices);
        $this->assertGreaterThan(0, $count);
    }

    public function testGetFilteredDevicesByConnectivityFilter(): void
    {
        // Exercise the filtered query path; specific filter validation is
        // covered indirectly via the controller filter tests.
        $devices = $this->repository->getFilteredDevices(['connectivity' => ['wifi']], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['connectivity' => ['wifi']]);

        $this->assertCount($count, $devices);
    }

    public function testGetFilteredDevicesByBindingFilter(): void
    {
        $devices = $this->repository->getFilteredDevices(['binding' => 'yes'], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['binding' => 'yes']);

        $this->assertCount($count, $devices);
    }

    public function testGetFilteredDevicesByDeviceTypeFilter(): void
    {
        $devices = $this->repository->getFilteredDevices(['device_types' => [266]], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['device_types' => [266]]);

        $this->assertCount($count, $devices);
    }

    public function testGetTopVendorsReturnsAList(): void
    {
        $top = $this->repository->getTopVendors(5);

        $this->assertIsArray($top);
        $this->assertLessThanOrEqual(5, count($top));
        foreach ($top as $row) {
            $this->assertArrayHasKey('vendor_name', $row);
        }
    }

    public function testGetRecentDevices(): void
    {
        $recent = $this->repository->getRecentDevices(3);
        $this->assertIsArray($recent);
        $this->assertLessThanOrEqual(3, count($recent));
    }

    public function testCountVendorsAndDevicesAccessors(): void
    {
        $devices = $this->repository->countDevices();
        $vendors = $this->repository->countVendors();

        $this->assertGreaterThan(0, $devices);
        $this->assertGreaterThan(0, $vendors);
    }

    public function testGetClusterStatsAndDeviceTypeStats(): void
    {
        $clusters = $this->repository->getClusterStats();
        $deviceTypes = $this->repository->getDeviceTypeStats();

        $this->assertIsArray($clusters);
        $this->assertIsArray($deviceTypes);
    }

    public function testGetCategoryAndSpecVersionDistribution(): void
    {
        $byCategory = $this->repository->getCategoryDistribution($this->matterRegistry);
        $bySpec = $this->repository->getSpecVersionDistribution($this->matterRegistry);

        $this->assertIsArray($byCategory);
        $this->assertIsArray($bySpec);
    }

    public function testGetConnectivityAndBindingFacets(): void
    {
        $connectivity = $this->repository->getConnectivityFacets();
        $binding = $this->repository->getBindingFacets();
        $star = $this->repository->getStarRatingFacets();
        $capability = $this->repository->getCapabilityFacets();
        $vendor = $this->repository->getVendorFacets(20);
        $deviceType = $this->repository->getDeviceTypeFacets(15);

        $this->assertIsArray($connectivity);
        $this->assertIsArray($binding);
        $this->assertIsArray($star);
        $this->assertIsArray($capability);
        $this->assertIsArray($vendor);
        $this->assertIsArray($deviceType);
    }

    public function testGetDevicesByDeviceType(): void
    {
        // Device type 266 (On/Off Plug) — fixture devices include it
        $devices = $this->repository->getDevicesByDeviceType(266, 50, 0, 'recent');
        $count = $this->repository->countDevicesByDeviceType(266);

        $this->assertIsArray($devices);
        $this->assertIsInt($count);
        $this->assertLessThanOrEqual($count, count($devices));
    }

    public function testGetDevicesByDeviceTypePopularSort(): void
    {
        $devices = $this->repository->getDevicesByDeviceType(266, 50, 0, 'popular');
        $this->assertIsArray($devices);
    }

    public function testGetDevicesWithServerClusterAndCount(): void
    {
        // Cluster 6 (On/Off) — common, plenty of fixture devices have it
        $devices = $this->repository->getDevicesWithServerCluster(6, null, 5);
        $count = $this->repository->countDevicesWithServerCluster(6);

        $this->assertIsArray($devices);
        $this->assertIsInt($count);
        $this->assertLessThanOrEqual(5, count($devices));
    }

    public function testGetDevicesWithClientClusterAndCount(): void
    {
        // Cluster 29 (Descriptor) — appears as client cluster sometimes
        $devices = $this->repository->getDevicesWithClientCluster(29, null, 5);
        $count = $this->repository->countDevicesWithClientCluster(29);

        $this->assertIsArray($devices);
        $this->assertIsInt($count);
    }

    public function testBindingHelpers(): void
    {
        $devices = $this->repository->getBindingCapableDevices(10);
        $byCategory = $this->repository->getBindingByCategory($this->matterRegistry);

        $this->assertIsArray($devices);
        $this->assertIsArray($byCategory);
    }

    public function testVersionAndMultiVersionHelpers(): void
    {
        $versionStats = $this->repository->getVersionStats();
        $multiVersion = $this->repository->getProductsWithMultipleVersions(10);

        $this->assertIsArray($versionStats);
        $this->assertIsArray($multiVersion);
    }

    public function testClusterCoOccurrence(): void
    {
        $rows = $this->repository->getClusterCoOccurrence(5);
        $this->assertIsArray($rows);
        $this->assertLessThanOrEqual(5, count($rows));
    }

    public function testGetFilteredDevicesByVendorFk(): void
    {
        $em = self::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $vendor = $em->getRepository(\App\Entity\Vendor::class)->findOneBy(['specId' => 4874]);
        $this->assertNotNull($vendor);

        $devices = $this->repository->getFilteredDevices(['vendor' => $vendor->getId()], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['vendor' => $vendor->getId()]);

        $this->assertCount($count, $devices);
        foreach ($devices as $d) {
            $this->assertSame($vendor->getId(), (int) $d['vendor_fk']);
        }
    }

    public function testGetFilteredDevicesBySearch(): void
    {
        $devices = $this->repository->getFilteredDevices(['search' => 'Eve'], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['search' => 'Eve']);

        $this->assertCount($count, $devices);
        $this->assertGreaterThan(0, $count);
    }

    public function testGetFilteredDevicesByMinRating(): void
    {
        // min_rating filter joins device_scores; even with an empty scores
        // table, the query should run and produce a deterministic result.
        $devices = $this->repository->getFilteredDevices(['min_rating' => 3], 200, 0);
        $count = $this->repository->getFilteredDeviceCount(['min_rating' => 3]);

        $this->assertCount($count, $devices);
    }

    public function testGetFilteredDevicesByCompatibleWithEmptyArray(): void
    {
        // Empty owned-device array short-circuits to "no compatible devices"
        // path → all results filtered out by `1=0`.
        $devices = $this->repository->getFilteredDevices(['compatible_with' => []], 200, 0);

        // `!empty([])` is false → branch is skipped → returns everything.
        $this->assertGreaterThan(0, count($devices));
    }

    public function testGetFilteredDevicesByCompatibleWithUnknownDevicesGetsNoResults(): void
    {
        // Owned IDs that have no co-occurrence → `findCompatibleDevices` returns
        // empty array → the `1=0` branch fires and the query returns nothing.
        $devices = $this->repository->getFilteredDevices(
            ['compatible_with' => [99999991, 99999992]],
            200,
            0,
        );

        $this->assertSame([], $devices);
    }

    public function testGetFilteredDevicesByCapability(): void
    {
        // Capability 'dimming' maps to cluster 8 (Level Control). Fixture
        // devices include lights that have this cluster.
        $devices = $this->repository->getFilteredDevices(
            ['capabilities' => ['dimming']],
            200,
            0,
        );
        $count = $this->repository->getFilteredDeviceCount(['capabilities' => ['dimming']]);

        $this->assertCount($count, $devices);
    }

    public function testGetFilteredDevicesByMultipleCapabilitiesIntersects(): void
    {
        // Devices with BOTH dimming (cluster 8) AND full_color (cluster 768).
        // Extended Color Lights typically have both.
        $devices = $this->repository->getFilteredDevices(
            ['capabilities' => ['dimming', 'full_color']],
            200,
            0,
        );

        $this->assertIsArray($devices);
    }

    public function testGetFilteredDevicesByCapabilityIgnoresUnknownKeys(): void
    {
        // Unknown capability keys are silently filtered out before the
        // subquery is assembled.
        $allDevices = $this->repository->getFilteredDevices([], 200, 0);
        $devices = $this->repository->getFilteredDevices(
            ['capabilities' => ['not_a_real_capability']],
            200,
            0,
        );

        $this->assertCount(count($allDevices), $devices);
    }
}
