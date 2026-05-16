<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Cluster;
use App\Repository\ClusterRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ClusterRepositoryTest extends KernelTestCase
{
    private ClusterRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(ClusterRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSaveAndFlush(): void
    {
        $cluster = (new Cluster(9000))
            ->setName('Test Cluster')
            ->setCategory('test')
            ->setIsGlobal(false);

        $this->repository->save($cluster, false);
        $this->repository->flush();

        $found = $this->repository->find(9000);
        $this->assertNotNull($found);
        $this->assertSame('Test Cluster', $found->getName());
    }

    public function testSaveWithFlushPersistsImmediately(): void
    {
        $cluster = (new Cluster(9001))
            ->setName('Eager Cluster')
            ->setCategory('test')
            ->setIsGlobal(false);

        $this->repository->save($cluster, true);

        $this->assertNotNull($this->repository->find(9001));
    }

    public function testFindByHexIdMatchesUppercase(): void
    {
        $found = $this->repository->findByHexId('0x0006');

        $this->assertNotNull($found);
        $this->assertSame('On/Off', $found->getName());
    }

    public function testFindByHexIdIsCaseInsensitive(): void
    {
        $upper = $this->repository->findByHexId('0X001D');
        $lower = $this->repository->findByHexId('0x001d');

        $this->assertNotNull($upper);
        $this->assertNotNull($lower);
        $this->assertSame($upper->getId(), $lower->getId());
    }

    public function testFindByHexIdReturnsNullForUnknown(): void
    {
        $this->assertNull($this->repository->findByHexId('0xFFFE'));
    }

    public function testFindAllGroupedByCategory(): void
    {
        $grouped = $this->repository->findAllGroupedByCategory();

        $this->assertArrayHasKey('utility', $grouped);
        $this->assertArrayHasKey('general', $grouped);

        foreach ($grouped as $category => $clusters) {
            $this->assertIsString($category);
            foreach ($clusters as $cluster) {
                $this->assertInstanceOf(Cluster::class, $cluster);
            }
        }
    }

    public function testFindAllGroupedByCategoryFallsBackToOtherWhenCategoryIsNull(): void
    {
        $orphan = (new Cluster(9002))
            ->setName('Orphan Cluster')
            ->setCategory(null)
            ->setIsGlobal(false);

        $this->repository->save($orphan, true);

        $grouped = $this->repository->findAllGroupedByCategory();

        $this->assertArrayHasKey('Other', $grouped);
        $found = false;
        foreach ($grouped['Other'] as $cluster) {
            if ($cluster->getId() === 9002) {
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
        $globalIds = array_map(fn (Cluster $c) => $c->getId(), $this->repository->findGlobalClusters());
        $appIds = array_map(fn (Cluster $c) => $c->getId(), $this->repository->findApplicationClusters());

        $this->assertSame([], array_intersect($globalIds, $appIds));
    }

    public function testSearchMatchesNameSubstring(): void
    {
        $results = $this->repository->search('On/Off');

        $this->assertNotEmpty($results);
        $names = array_map(fn (Cluster $c) => $c->getName(), $results);
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
