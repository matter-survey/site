<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use App\Entity\Vendor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function save(Product $product, bool $flush = false): void
    {
        $this->getEntityManager()->persist($product);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findByVendorAndProductId(int $vendorId, int $productId): ?Product
    {
        return $this->findOneBy([
            'vendorId' => $vendorId,
            'productId' => $productId,
        ]);
    }

    /**
     * Find or create a product by vendor_id and product_id.
     */
    public function findOrCreate(
        int $vendorId,
        int $productId,
        ?string $vendorName = null,
        ?string $productName = null,
        ?Vendor $vendor = null
    ): Product {
        $product = $this->findByVendorAndProductId($vendorId, $productId);

        if ($product !== null) {
            // Update names if provided and different
            $updated = false;

            if ($vendorName !== null && $vendorName !== $product->getVendorName()) {
                $product->setVendorName($vendorName);
                $updated = true;
            }

            if ($productName !== null && $productName !== $product->getProductName()) {
                $product->setProductName($productName);
                $updated = true;
            }

            if ($vendor !== null && $vendor !== $product->getVendor()) {
                $product->setVendor($vendor);
                $updated = true;
            }

            if ($updated) {
                $product->setLastSeen(new \DateTime());
            }

            return $product;
        }

        // Create new product
        $product = new Product();
        $product->setVendorId($vendorId);
        $product->setProductId($productId);
        $product->setVendorName($vendorName);
        $product->setProductName($productName);
        $product->setVendor($vendor);

        $this->save($product);

        return $product;
    }

    /**
     * Get all products ordered by submission count.
     *
     * @return Product[]
     */
    public function findAllOrderedBySubmissionCount(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.submissionCount', 'DESC')
            ->addOrderBy('p.lastSeen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get products by vendor entity.
     *
     * @return Product[]
     */
    public function findByVendor(Vendor $vendor, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->orderBy('p.submissionCount', 'DESC')
            ->addOrderBy('p.lastSeen', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count products by vendor.
     */
    public function countByVendor(Vendor $vendor): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search products by name or ID.
     *
     * @return Product[]
     */
    public function search(string $query, int $limit = 50): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.vendorName LIKE :query')
            ->orWhere('p.productName LIKE :query')
            ->orWhere('CAST(p.vendorId AS string) LIKE :query')
            ->orWhere('CAST(p.productId AS string) LIKE :query')
            ->setParameter('query', "%$query%")
            ->orderBy('p.submissionCount', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total product count.
     */
    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
