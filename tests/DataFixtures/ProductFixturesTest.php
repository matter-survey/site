<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures;

use App\DataFixtures\ProductFixtures;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * DCL-only products never pass through the telemetry upsert, so the fixture
 * loader is the only place that can give them a slug for device_show links.
 */
final class ProductFixturesTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $yamlPath;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->yamlPath = tempnam(sys_get_temp_dir(), 'products').'.yaml';
        file_put_contents($this->yamlPath, <<<'YAML'
            - vendorId: 65521
              productId: 32001
              productName: 'Fixture Plug Pro'
              productLabel: '-'
            - vendorId: 65521
              productId: 32002
              productName: 'Stable Slug Bulb'
            YAML);
    }

    protected function tearDown(): void
    {
        @unlink($this->yamlPath);
        parent::tearDown();
    }

    public function testAssignsSlugToNewProducts(): void
    {
        new ProductFixtures($this->yamlPath)->load($this->em);

        $product = $this->findProduct(32001);
        $this->assertSame('fixture-plug-pro-65521-32001', $product->getSlug());
    }

    public function testKeepsExistingSlug(): void
    {
        $existing = new Product();
        $existing->setVendorId(65521);
        $existing->setProductId(32002);
        $existing->setSlug('published-url-65521-32002');
        $this->em->persist($existing);
        $this->em->flush();

        new ProductFixtures($this->yamlPath)->load($this->em);

        $this->assertSame('published-url-65521-32002', $this->findProduct(32002)->getSlug());
    }

    private function findProduct(int $productId): Product
    {
        $product = $this->em->getRepository(Product::class)->findOneBy(['vendorId' => 65521, 'productId' => $productId]);
        $this->assertInstanceOf(Product::class, $product);

        return $product;
    }
}
