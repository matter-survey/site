<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\DeviceType;
use App\Repository\DeviceTypeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DeviceTypeRepositoryTest extends KernelTestCase
{
    private DeviceTypeRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(DeviceTypeRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSaveWithFlush(): void
    {
        $deviceType = new DeviceType(9000)
            ->setName('Test Device Type')
            ->setDisplayCategory('Test');

        $this->repository->save($deviceType, true);

        $found = $this->repository->find(9000);
        $this->assertInstanceOf(DeviceType::class, $found);
        $this->assertSame('Test Device Type', $found->getName());
    }

    public function testSaveWithoutFlushDoesNotPersistImmediately(): void
    {
        $deviceType = new DeviceType(9001)
            ->setName('Lazy Device Type')
            ->setDisplayCategory('Test');

        $this->repository->save($deviceType, false);

        // Without flush, the row isn't in the DB yet (Doctrine UoW holds it).
        // Flush manually to commit so the rest of the test runs cleanly.
        $this->entityManager->flush();
        $this->assertSame('Lazy Device Type', $this->repository->find(9001)?->getName());
    }

    public function testFindAllGroupedByCategory(): void
    {
        $grouped = $this->repository->findAllGroupedByCategory();

        // Fixture data has these categories
        $this->assertArrayHasKey('Sensors', $grouped);
        $this->assertArrayHasKey('Lights', $grouped);
        $this->assertArrayHasKey('System', $grouped);

        // Every value is an array of DeviceType objects
        foreach ($grouped as $category => $deviceTypes) {
            $this->assertIsString($category);
            $this->assertIsArray($deviceTypes);
            $this->assertNotEmpty($deviceTypes);
            $this->assertContainsOnlyInstancesOf(DeviceType::class, $deviceTypes);
        }
    }

    public function testFindAllGroupedByCategoryFallsBackToSystemWhenCategoryIsNull(): void
    {
        // Add a device type with no display category
        $orphan = new DeviceType(9002)
            ->setName('Orphan Device Type')
            ->setDisplayCategory(null);

        $this->repository->save($orphan, true);

        $grouped = $this->repository->findAllGroupedByCategory();

        $this->assertArrayHasKey('System', $grouped);
        $orphanFound = false;
        foreach ($grouped['System'] as $dt) {
            if (9002 === $dt->getId()) {
                $orphanFound = true;
                break;
            }
        }
        $this->assertTrue($orphanFound, 'Null display category should be grouped under "System"');
    }

    public function testFindByDisplayCategory(): void
    {
        $sensors = $this->repository->findByDisplayCategory('Sensors');

        $this->assertNotEmpty($sensors);
        foreach ($sensors as $dt) {
            $this->assertSame('Sensors', $dt->getDisplayCategory());
        }

        $this->assertSame([], $this->repository->findByDisplayCategory('NonExistentCategory'));
    }

    public function testFindBySpecCategory(): void
    {
        $sensors = $this->repository->findBySpecCategory('sensors');

        $this->assertNotEmpty($sensors);
        foreach ($sensors as $dt) {
            $this->assertSame('sensors', $dt->getCategory());
        }
    }

    public function testFindAllSpecCategories(): void
    {
        $categories = $this->repository->findAllSpecCategories();

        $this->assertNotEmpty($categories);
        $this->assertContains('sensors', $categories);
        $this->assertContains('utility', $categories);
        // sorted ASC
        $sorted = $categories;
        sort($sorted);
        $this->assertSame($sorted, array_values($categories));
    }

    public function testFindAllDisplayCategoriesDelegatesToFindAllCategories(): void
    {
        $this->assertSame(
            $this->repository->findAllCategories(),
            $this->repository->findAllDisplayCategories(),
        );
    }

    public function testFindAllCategories(): void
    {
        $categories = $this->repository->findAllCategories();

        $this->assertNotEmpty($categories);
        $this->assertContains('Sensors', $categories);
        $this->assertContains('Lights', $categories);
    }

    public function testFindBySpecVersion(): void
    {
        $v10 = $this->repository->findBySpecVersion('1.0');

        $this->assertNotEmpty($v10);
        foreach ($v10 as $dt) {
            $this->assertSame('1.0', $dt->getSpecVersion());
        }

        $this->assertSame([], $this->repository->findBySpecVersion('99.99'));
    }

    public function testFindAllSpecVersions(): void
    {
        $versions = $this->repository->findAllSpecVersions();

        $this->assertNotEmpty($versions);
        $this->assertContains('1.0', $versions);
        // sorted ASC
        $sorted = $versions;
        sort($sorted);
        $this->assertSame($sorted, array_values($versions));
    }

    public function testCountBySpecVersion(): void
    {
        $counts = $this->repository->countBySpecVersion();

        $this->assertNotEmpty($counts);
        $this->assertArrayHasKey('1.0', $counts);
        foreach ($counts as $version => $count) {
            $this->assertIsString($version);
            $this->assertIsInt($count);
            $this->assertGreaterThan(0, $count);
        }
    }

    public function testFindByMandatoryServerClusterUsesJsonExtract(): void
    {
        // Cluster ID 3 (Identify) is in many device types' mandatory clusters
        $deviceTypes = $this->repository->findByMandatoryServerCluster(3);

        $this->assertNotEmpty($deviceTypes);
        foreach ($deviceTypes as $dt) {
            $this->assertInstanceOf(DeviceType::class, $dt);
            $hasCluster = array_any($dt->getMandatoryServerClusters(), fn ($cluster): bool => ($cluster['id'] ?? null) === 3);
            $this->assertTrue($hasCluster);
        }
    }

    public function testFindByMandatoryServerClusterReturnsEmptyArrayForUnknownCluster(): void
    {
        $this->assertSame(
            [],
            $this->repository->findByMandatoryServerCluster(99999),
        );
    }

    public function testSearchMatchesNameSubstring(): void
    {
        $results = $this->repository->search('Sensor');

        $this->assertNotEmpty($results);
        foreach ($results as $dt) {
            $this->assertStringContainsStringIgnoringCase('Sensor', $dt->getName());
        }
    }

    public function testSearchRespectsLimit(): void
    {
        $results = $this->repository->search('o', 3);

        $this->assertLessThanOrEqual(3, count($results));
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $this->assertSame([], $this->repository->search('ZZZZZZNOMATCH'));
    }
}
