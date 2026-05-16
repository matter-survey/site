<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductRepositoryTest extends KernelTestCase
{
    private ProductRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(ProductRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFindByVendorAndProductId(): void
    {
        // Create a test product
        $product = new Product();
        $product->setVendorId(9999);
        $product->setProductId(1);
        $product->setVendorName('Test Vendor');
        $product->setProductName('Test Product');

        $this->repository->save($product, true);

        // Find it
        $found = $this->repository->findByVendorAndProductId(9999, 1);
        $this->assertInstanceOf(Product::class, $found);
        $this->assertSame('Test Product', $found->getProductName());

        // Not found
        $notFound = $this->repository->findByVendorAndProductId(9999, 999);
        $this->assertNotInstanceOf(Product::class, $notFound);
    }

    public function testFindByVendorSpecId(): void
    {
        // Create test products for a vendor
        for ($i = 1; $i <= 3; ++$i) {
            $product = new Product();
            $product->setVendorId(8888);
            $product->setProductId($i);
            $product->setProductName("Product $i");
            $this->repository->save($product);
        }
        $this->entityManager->flush();

        // Find by specId
        $products = $this->repository->findByVendorSpecId(8888);
        $this->assertCount(3, $products);

        // Check ordering (by productName ASC)
        $this->assertEquals('Product 1', $products[0]->getProductName());

        // Test limit
        $limited = $this->repository->findByVendorSpecId(8888, 2);
        $this->assertCount(2, $limited);

        // Non-existent vendor
        $empty = $this->repository->findByVendorSpecId(7777);
        $this->assertCount(0, $empty);
    }

    public function testCountByVendorSpecId(): void
    {
        // Create test products
        for ($i = 1; $i <= 5; ++$i) {
            $product = new Product();
            $product->setVendorId(6666);
            $product->setProductId($i);
            $product->setProductName("Product $i");
            $this->repository->save($product);
        }
        $this->entityManager->flush();

        $count = $this->repository->countByVendorSpecId(6666);
        $this->assertSame(5, $count);

        // Non-existent vendor
        $zeroCount = $this->repository->countByVendorSpecId(5555);
        $this->assertSame(0, $zeroCount);
    }

    public function testGetProductCountsByVendor(): void
    {
        // Create products for multiple vendors
        $vendorProducts = [
            4444 => 3,
            3333 => 2,
            2222 => 1,
        ];

        foreach ($vendorProducts as $vendorId => $count) {
            for ($i = 1; $i <= $count; ++$i) {
                $product = new Product();
                $product->setVendorId($vendorId);
                $product->setProductId($i);
                $product->setProductName("Vendor $vendorId Product $i");
                $this->repository->save($product);
            }
        }
        $this->entityManager->flush();

        $counts = $this->repository->getProductCountsByVendor();

        // Check our test vendors are in the map
        $this->assertArrayHasKey(4444, $counts);
        $this->assertArrayHasKey(3333, $counts);
        $this->assertArrayHasKey(2222, $counts);

        $this->assertGreaterThanOrEqual(3, $counts[4444]);
        $this->assertGreaterThanOrEqual(2, $counts[3333]);
        $this->assertGreaterThanOrEqual(1, $counts[2222]);
    }

    public function testFindOrCreate(): void
    {
        // First call creates
        $product1 = $this->repository->findOrCreate(1111, 1, 'Vendor A', 'Product A');
        $this->entityManager->flush();

        $this->assertNotNull($product1->getId());
        $this->assertSame('Product A', $product1->getProductName());

        // Second call finds existing
        $product2 = $this->repository->findOrCreate(1111, 1, 'Vendor A Updated', 'Product A Updated');
        $this->entityManager->flush();

        $this->assertSame($product1->getId(), $product2->getId());
        // Names should be updated
        $this->assertSame('Vendor A Updated', $product2->getVendorName());
        $this->assertSame('Product A Updated', $product2->getProductName());
    }

    public function testFindOrCreateUpdatesVendorRelation(): void
    {
        // Create product without vendor first
        $first = $this->repository->findOrCreate(2222, 1, 'V', 'P');
        $this->entityManager->flush();
        $this->assertNotInstanceOf(\App\Entity\Vendor::class, $first->getVendor());

        // Pull a fixture vendor and pass it in
        $vendor = $this->entityManager->getRepository(\App\Entity\Vendor::class)->findOneBy([]);
        $this->assertInstanceOf(\App\Entity\Vendor::class, $vendor);

        $second = $this->repository->findOrCreate(2222, 1, 'V', 'P', $vendor);
        $this->entityManager->flush();

        $this->assertSame($first->getId(), $second->getId());
        $this->assertSame($vendor, $second->getVendor());
        $this->assertInstanceOf(\DateTimeInterface::class, $second->getLastSeen()); // updated path bumps lastSeen
    }

    public function testFindOrCreateIsIdempotentWhenNothingChanges(): void
    {
        $first = $this->repository->findOrCreate(3333, 1, 'V', 'P');
        $this->entityManager->flush();
        $firstSeen = $first->getLastSeen();

        $second = $this->repository->findOrCreate(3333, 1, 'V', 'P');
        $this->entityManager->flush();

        $this->assertSame($first->getId(), $second->getId());
        // No update path → lastSeen unchanged
        $this->assertSame($firstSeen, $second->getLastSeen());
    }

    public function testFindAllOrderedBySubmissionCount(): void
    {
        $hot = $this->repository->findOrCreate(4001, 1, 'V', 'Hot');
        $hot->setSubmissionCount(500);
        $cold = $this->repository->findOrCreate(4001, 2, 'V', 'Cold');
        $cold->setSubmissionCount(1);
        $this->entityManager->flush();

        $top = $this->repository->findAllOrderedBySubmissionCount(2);

        $this->assertGreaterThanOrEqual(1, count($top));
        $this->assertGreaterThanOrEqual(
            $top[count($top) - 1]->getSubmissionCount(),
            $top[0]->getSubmissionCount(),
        );
    }

    public function testFindByVendorAndCountByVendor(): void
    {
        $vendor = $this->entityManager->getRepository(\App\Entity\Vendor::class)->findOneBy([]);
        $this->assertInstanceOf(\App\Entity\Vendor::class, $vendor);

        // attach two products to this vendor
        for ($i = 1; $i <= 2; ++$i) {
            $p = $this->repository->findOrCreate(5500 + $i, 1, 'V', "P{$i}", $vendor);
        }
        $this->entityManager->flush();

        $found = $this->repository->findByVendor($vendor);
        $count = $this->repository->countByVendor($vendor);

        $this->assertGreaterThanOrEqual(2, count($found));
        $this->assertSame(count($found), $count);
    }

    public function testSearchMatchesNameOrVendorOrIds(): void
    {
        $this->repository->findOrCreate(7001, 1, 'AcmeCo', 'WidgetMaker');
        $this->entityManager->flush();

        // by product name
        $byName = $this->repository->search('WidgetMaker');
        $this->assertNotEmpty($byName);

        // by vendor name
        $byVendor = $this->repository->search('AcmeCo');
        $this->assertNotEmpty($byVendor);

        // by vendor id (digits)
        $byVendorId = $this->repository->search('7001');
        $this->assertNotEmpty($byVendorId);

        // no match
        $this->assertSame([], $this->repository->search('ZZZ_NO_MATCH_PLEASE'));
    }

    public function testSearchRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $this->repository->findOrCreate(7100, $i, 'V', "Multi{$i}");
        }
        $this->entityManager->flush();

        $results = $this->repository->search('Multi', 3);
        $this->assertLessThanOrEqual(3, count($results));
    }

    public function testCountAllReflectsAllProducts(): void
    {
        $before = $this->repository->countAll();
        $this->repository->findOrCreate(9001, 1, 'V', 'P');
        $this->entityManager->flush();

        $this->assertSame($before + 1, $this->repository->countAll());
    }

    public function testCommissioningHelpers(): void
    {
        $withInstructions = $this->repository->findOrCreate(8001, 1, 'V', 'WithInstructions');
        $withInstructions->setCommissioningInitialStepsInstruction('Long-press button');
        $withInstructions->setCommissioningInitialStepsHint(2);
        $withInstructions->setCommissioningCustomFlow(1);
        $withInstructions->setIcdUserActiveModeTriggerInstruction('Wake');
        $withInstructions->setFactoryResetStepsInstruction('Hold 10s');
        $withInstructions->setCommissioningCustomFlowUrl('https://example.com/flow');

        $this->repository->findOrCreate(8002, 1, 'V', 'Plain');
        $this->entityManager->flush();

        $withCommissioning = $this->repository->findWithCommissioningData();
        $this->assertNotEmpty($withCommissioning);
        $names = array_map(fn (Product $p): ?string => $p->getProductName(), $withCommissioning);
        $this->assertContains('WithInstructions', $names);
        $this->assertNotContains('Plain', $names);

        $this->assertGreaterThanOrEqual(1, $this->repository->countWithCommissioningData());
        $this->assertNotEmpty($this->repository->findWithIcdData());

        $grouped = $this->repository->findGroupedByComplexity();
        $this->assertArrayHasKey(2, $grouped);
        $this->assertNotEmpty($grouped[2]);

        $stats = $this->repository->getCommissioningStats();
        $this->assertArrayHasKey('total', $stats);
        $this->assertArrayHasKey('withInstructions', $stats);
        $this->assertArrayHasKey('withFactoryReset', $stats);
        $this->assertArrayHasKey('withCustomFlow', $stats);
        $this->assertArrayHasKey('withIcd', $stats);
        $this->assertArrayHasKey('byComplexity', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['withInstructions']);
        $this->assertGreaterThanOrEqual(1, $stats['byComplexity'][2] ?? 0);
    }
}
