<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Product;
use App\Entity\Vendor;
use App\Repository\ProductRepository;
use App\Repository\VendorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class VendorRepositoryTest extends KernelTestCase
{
    private VendorRepository $repository;
    private ProductRepository $productRepository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(VendorRepository::class);
        $this->productRepository = self::getContainer()->get(ProductRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFindBySlug(): void
    {
        $vendor = new Vendor()
            ->setName('Findable')
            ->setSlug('findable-1')
            ->setSpecId(1000);
        $this->repository->save($vendor, true);

        $found = $this->repository->findBySlug('findable-1');
        $this->assertInstanceOf(Vendor::class, $found);
        $this->assertSame('Findable', $found->getName());
        $this->assertNotInstanceOf(Vendor::class, $this->repository->findBySlug('nonexistent'));
    }

    public function testFindBySpecId(): void
    {
        $vendor = new Vendor()
            ->setName('SpecIdVendor')
            ->setSlug('specid-vendor-2000')
            ->setSpecId(2000);
        $this->repository->save($vendor, true);

        $found = $this->repository->findBySpecId(2000);
        $this->assertInstanceOf(Vendor::class, $found);
        $this->assertNotInstanceOf(Vendor::class, $this->repository->findBySpecId(99999));
    }

    public function testFindOrCreateBySpecIdCreatesNew(): void
    {
        $before = $this->repository->findBySpecId(3000);
        $this->assertNotInstanceOf(Vendor::class, $before);

        $vendor = $this->repository->findOrCreateBySpecId(3000, 'NewCo');
        $this->entityManager->flush();

        $this->assertNotNull($vendor->getId());
        $this->assertSame(3000, $vendor->getSpecId());
        $this->assertSame('NewCo', $vendor->getName());
        $this->assertSame(0, $vendor->getDeviceCount());
    }

    public function testFindOrCreateBySpecIdUpdatesNameWhenDifferent(): void
    {
        $vendor = $this->repository->findOrCreateBySpecId(3100, 'OriginalName');
        $this->entityManager->flush();
        $created = $vendor->getUpdatedAt();
        sleep(1); // ensure timestamp moves

        $updated = $this->repository->findOrCreateBySpecId(3100, 'RenamedCo');
        $this->entityManager->flush();

        $this->assertSame($vendor->getId(), $updated->getId());
        $this->assertSame('RenamedCo', $updated->getName());
        $this->assertSame('renamedco-3100', $updated->getSlug());
        $this->assertGreaterThan($created->getTimestamp(), $updated->getUpdatedAt()->getTimestamp());
    }

    public function testFindOrCreateBySpecIdUsesCanonicalSlugOnCreate(): void
    {
        $vendor = $this->repository->findOrCreateBySpecId(3150, 'Tasmota');
        $this->entityManager->flush();

        $this->assertSame('tasmota-3150', $vendor->getSlug());
    }

    public function testFindOrCreateBySpecIdFallsBackToDefaultName(): void
    {
        $vendor = $this->repository->findOrCreateBySpecId(3200);

        $this->assertSame('Vendor 3200', $vendor->getName());
    }

    public function testFindAllOrderedByDeviceCountDescThenNameAsc(): void
    {
        $a = new Vendor()->setName('AZeros')->setSlug('azeros-1')->setSpecId(94001)->setDeviceCount(0);
        $b = new Vendor()->setName('BTens')->setSlug('btens-1')->setSpecId(94002)->setDeviceCount(10);
        $c = new Vendor()->setName('CFives')->setSlug('cfives-1')->setSpecId(94003)->setDeviceCount(5);

        $this->repository->save($a);
        $this->repository->save($b);
        $this->repository->save($c);
        $this->entityManager->flush();

        $sorted = $this->repository->findAllOrderedByDeviceCount();

        $firstBtensIdx = $this->findIndex($sorted, 'BTens');
        $cFivesIdx = $this->findIndex($sorted, 'CFives');
        $aZerosIdx = $this->findIndex($sorted, 'AZeros');

        // BTens (10) before CFives (5) before AZeros (0)
        $this->assertLessThan($cFivesIdx, $firstBtensIdx);
        $this->assertLessThan($aZerosIdx, $cFivesIdx);
    }

    public function testFindPopularReturnsOnlyVendorsWithDevices(): void
    {
        $zero = new Vendor()->setName('ZeroOnes')->setSlug('zeroones-1')->setSpecId(95000)->setDeviceCount(0);
        $popular = new Vendor()->setName('Popular')->setSlug('popular-1')->setSpecId(95001)->setDeviceCount(50);
        $this->repository->save($zero);
        $this->repository->save($popular);
        $this->entityManager->flush();

        $top = $this->repository->findPopular(10);

        $names = array_map(fn (Vendor $v): string => $v->getName(), $top);
        $this->assertContains('Popular', $names);
        $this->assertNotContains('ZeroOnes', $names);
    }

    public function testFindPopularRespectsLimit(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $v = new Vendor()->setName("V{$i}")->setSlug("v{$i}-96000")->setSpecId(96000 + $i)->setDeviceCount($i);
            $this->repository->save($v);
        }
        $this->entityManager->flush();

        $top = $this->repository->findPopular(3);
        $this->assertLessThanOrEqual(3, count($top));
    }

    public function testUpdateDeviceCountRecountsProducts(): void
    {
        $vendor = $this->repository->findOrCreateBySpecId(97000, 'CountCo');
        $this->entityManager->flush();

        // Attach 3 products
        for ($i = 1; $i <= 3; ++$i) {
            $p = new Product()
                ->setVendorId(97000)
                ->setProductId($i)
                ->setProductName("P{$i}")
                ->setVendor($vendor);
            $this->productRepository->save($p);
        }
        $this->entityManager->flush();

        $vendor->setDeviceCount(0); // stale
        $this->repository->updateDeviceCount($vendor);

        $this->assertSame(3, $vendor->getDeviceCount());
    }

    /**
     * @param Vendor[] $vendors
     */
    private function findIndex(array $vendors, string $name): int
    {
        foreach ($vendors as $idx => $v) {
            if ($v->getName() === $name) {
                return $idx;
            }
        }
        $this->fail("Vendor {$name} not in results");
    }
}
