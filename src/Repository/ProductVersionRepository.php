<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVersion>
 */
class ProductVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVersion::class);
    }

    public function save(ProductVersion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProductVersion $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all versions for a product.
     *
     * @return ProductVersion[]
     */
    public function findByProduct(int $productId): array
    {
        return $this->createQueryBuilder('pv')
            ->andWhere('pv.product = :productId')
            ->setParameter('productId', $productId)
            ->orderBy('pv.lastSeen', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
