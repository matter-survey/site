<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ProductRepositoryTest extends KernelTestCase
{
    private ProductRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = static::getContainer()->get(ProductRepository::class);
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
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
        $this->assertNotNull($found);
        $this->assertEquals('Test Product', $found->getProductName());

        // Not found
        $notFound = $this->repository->findByVendorAndProductId(9999, 999);
        $this->assertNull($notFound);
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
        $this->assertEquals(5, $count);

        // Non-existent vendor
        $zeroCount = $this->repository->countByVendorSpecId(5555);
        $this->assertEquals(0, $zeroCount);
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
        $this->assertEquals('Product A', $product1->getProductName());

        // Second call finds existing
        $product2 = $this->repository->findOrCreate(1111, 1, 'Vendor A Updated', 'Product A Updated');
        $this->entityManager->flush();

        $this->assertEquals($product1->getId(), $product2->getId());
        // Names should be updated
        $this->assertEquals('Vendor A Updated', $product2->getVendorName());
        $this->assertEquals('Product A Updated', $product2->getProductName());
    }
}
