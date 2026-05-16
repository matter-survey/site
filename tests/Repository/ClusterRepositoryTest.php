<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Cluster;
use App\Repository\ClusterRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ClusterRepositoryTest extends KernelTestCase
{
    private ClusterRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ClusterRepository::class);
    }

    public function testSaveAndFlush(): void
    {
        $cluster = new Cluster(9000)
            ->setName('Test Cluster')
            ->setCategory('test')
            ->setIsGlobal(false);

        $this->repository->save($cluster, false);
        $this->repository->flush();

        $found = $this->repository->find(9000);
        $this->assertInstanceOf(Cluster::class, $found);
        $this->assertSame('Test Cluster', $found->getName());
    }

    public function testSaveWithFlushPersistsImmediately(): void
    {
        $cluster = new Cluster(9001)
            ->setName('Eager Cluster')
            ->setCategory('test')
            ->setIsGlobal(false);

        $this->repository->save($cluster, true);

        $this->assertInstanceOf(Cluster::class, $this->repository->find(9001));
    }

    public function testFindByHexIdMatchesUppercase(): void
    {
        $found = $this->repository->findByHexId('0x0006');

        $this->assertInstanceOf(Cluster::class, $found);
        $this->assertSame('On/Off', $found->getName());
    }

    public function testFindByHexIdIsCaseInsensitive(): void
    {
        $upper = $this->repository->findByHexId('0X001D');
        $lower = $this->repository->findByHexId('0x001d');

        $this->assertInstanceOf(Cluster::class, $upper);
        $this->assertInstanceOf(Cluster::class, $lower);
        $this->assertSame($upper->getId(), $lower->getId());
    }

    public function testFindByHexIdReturnsNullForUnknown(): void
    {
        $this->assertNotInstanceOf(Cluster::class, $this->repository->findByHexId('0xFFFE'));
    }

    public function testFindAllGroupedByCategory(): void
    {
        $grouped = $this->repository->findAllGroupedByCategory();

        $this->assertArrayHasKey('utility', $grouped);
        $this->assertArrayHasKey('general', $grouped);

        foreach ($grouped as $category => $clusters) {
            $this->assertIsString($category);
            $this->assertContainsOnlyInstancesOf(Cluster::class, $clusters);
        }
    }

    public function testFindAllGroupedByCategoryFallsBackToOtherWhenCategoryIsNull(): void
    {
        $orphan = new Cluster(9002)
            ->setName('Orphan Cluster')
            ->setCategory(null)
            ->setIsGlobal(false);

        $this->repository->save($orphan, true);

        $grouped = $this->repository->findAllGroupedByCategory();

        $this->assertArrayHasKey('Other', $grouped);
        $found = false;
        foreach ($grouped['Other'] as $cluster) {
            if (9002 === $cluster->getId()) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testFindByCategory(): void
    {
        $utility = $this->repository->findByCategory('utility');

        $this->assertNotEmpty($utility);
        foreach ($utility as $cluster) {
            $this->assertSame('utility', $cluster->getCategory());
        }

        $this->assertSame([], $this->repository->findByCategory('nonexistent'));
    }

    public function testFindAllCategoriesSortedAndNonNull(): void
    {
        $categories = $this->repository->findAllCategories();

        $this->assertNotEmpty($categories);
        $this->assertContains('utility', $categories);
        $this->assertContains('general', $categories);

        $sorted = $categories;
        sort($sorted);
        $this->assertSame($sorted, array_values($categories));
    }

    public function testFindGlobalClustersReturnsOnlyGlobal(): void
    {
        $globals = $this->repository->findGlobalClusters();

        $this->assertNotEmpty($globals);
        foreach ($globals as $cluster) {
            $this->assertTrue($cluster->isGlobal());
        }
    }

    public function testFindApplicationClustersReturnsOnlyNonGlobal(): void
    {
        $app = $this->repository->findApplicationClusters();

        $this->assertNotEmpty($app);
        foreach ($app as $cluster) {
            $this->assertFalse($cluster->isGlobal());
        }
    }

    public function testGlobalAndApplicationAreDisjoint(): void
    {
        $globalIds = array_map(fn (Cluster $c): int => $c->getId(), $this->repository->findGlobalClusters());
        $appIds = array_map(fn (Cluster $c): int => $c->getId(), $this->repository->findApplicationClusters());

        $this->assertSame([], array_intersect($globalIds, $appIds));
    }

    public function testSearchMatchesNameSubstring(): void
    {
        $results = $this->repository->search('On/Off');

        $this->assertNotEmpty($results);
        $names = array_map(fn (Cluster $c): string => $c->getName(), $results);
        $this->assertContains('On/Off', $names);
    }

    public function testSearchRespectsLimit(): void
    {
        $results = $this->repository->search('e', 5);

        $this->assertLessThanOrEqual(5, count($results));
    }

    public function testSearchReturnsEmptyForNoMatch(): void
    {
        $this->assertSame([], $this->repository->search('ZZZZZZNOMATCH'));
    }
}
