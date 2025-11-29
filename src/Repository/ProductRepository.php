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
     * Get products by vendor entity (uses FK relationship).
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
     * Get products by vendor specId (uses vendorId field from DCL).
     *
     * @return Product[]
     */
    public function findByVendorSpecId(int $specId, int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.vendorId = :vendorId')
            ->setParameter('vendorId', $specId)
            ->orderBy('p.productName', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count products by vendor entity (uses FK relationship).
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
     * Count products by vendor specId.
     */
    public function countByVendorSpecId(int $specId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.vendorId = :vendorId')
            ->setParameter('vendorId', $specId)
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

    /**
     * Get product counts grouped by vendor ID (specId).
     *
     * @return array<int, int> Map of vendorId => productCount
     */
    public function getProductCountsByVendor(): array
    {
        $results = $this->createQueryBuilder('p')
            ->select('p.vendorId, COUNT(p.id) as productCount')
            ->groupBy('p.vendorId')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($results as $row) {
            $map[$row['vendorId']] = (int) $row['productCount'];
        }

        return $map;
    }

    /**
     * Get products with commissioning instructions.
     *
     * @return Product[]
     */
    public function findWithCommissioningData(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.commissioningInitialStepsInstruction IS NOT NULL')
            ->orWhere('p.factoryResetStepsInstruction IS NOT NULL')
            ->orWhere('p.commissioningCustomFlowUrl IS NOT NULL')
            ->orderBy('p.productName', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count products with commissioning instructions.
     */
    public function countWithCommissioningData(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.commissioningInitialStepsInstruction IS NOT NULL')
            ->orWhere('p.factoryResetStepsInstruction IS NOT NULL')
            ->orWhere('p.commissioningCustomFlowUrl IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get commissioning statistics.
     *
     * @return array{total: int, withInstructions: int, withFactoryReset: int, withCustomFlow: int, withIcd: int, byComplexity: array<int, int>}
     */
    public function getCommissioningStats(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $total = (int) $conn->fetchOne('SELECT COUNT(*) FROM products');

        $withInstructions = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM products WHERE commissioning_initial_steps_instruction IS NOT NULL'
        );

        $withFactoryReset = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM products WHERE factory_reset_steps_instruction IS NOT NULL'
        );

        $withCustomFlow = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM products WHERE commissioning_custom_flow IS NOT NULL AND commissioning_custom_flow > 0'
        );

        $withIcd = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM products WHERE icd_user_active_mode_trigger_instruction IS NOT NULL'
        );

        // Group by complexity hint (0-3 typical range)
        $complexityResults = $conn->fetchAllAssociative(
            'SELECT commissioning_initial_steps_hint as hint, COUNT(*) as count
             FROM products
             WHERE commissioning_initial_steps_hint IS NOT NULL
             GROUP BY commissioning_initial_steps_hint
             ORDER BY hint'
        );

        $byComplexity = [];
        foreach ($complexityResults as $row) {
            $byComplexity[(int) $row['hint']] = (int) $row['count'];
        }

        return [
            'total' => $total,
            'withInstructions' => $withInstructions,
            'withFactoryReset' => $withFactoryReset,
            'withCustomFlow' => $withCustomFlow,
            'withIcd' => $withIcd,
            'byComplexity' => $byComplexity,
        ];
    }

    /**
     * Get products grouped by commissioning complexity.
     *
     * @return array<int, Product[]>
     */
    public function findGroupedByComplexity(): array
    {
        $products = $this->createQueryBuilder('p')
            ->where('p.commissioningInitialStepsHint IS NOT NULL')
            ->orWhere('p.commissioningInitialStepsInstruction IS NOT NULL')
            ->orderBy('p.commissioningInitialStepsHint', 'ASC')
            ->addOrderBy('p.productName', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($products as $product) {
            $hint = $product->getCommissioningInitialStepsHint() ?? 0;
            $grouped[$hint][] = $product;
        }

        return $grouped;
    }

    /**
     * Get products with ICD (Intermittently Connected Device) data.
     *
     * @return Product[]
     */
    public function findWithIcdData(int $limit = 100): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.icdUserActiveModeTriggerInstruction IS NOT NULL')
            ->orderBy('p.productName', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
