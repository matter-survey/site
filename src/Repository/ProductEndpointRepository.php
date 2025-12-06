<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductEndpoint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductEndpoint>
 */
class ProductEndpointRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductEndpoint::class);
    }

    public function save(ProductEndpoint $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(ProductEndpoint $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all endpoints for a product.
     *
     * @return ProductEndpoint[]
     */
    public function findByProduct(int $productId): array
    {
        return $this->createQueryBuilder('pe')
            ->andWhere('pe.product = :productId')
            ->setParameter('productId', $productId)
            ->orderBy('pe.endpointId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find endpoints by product and version.
     *
     * @return ProductEndpoint[]
     */
    public function findByProductAndVersion(int $productId, ?string $hardwareVersion, ?string $softwareVersion): array
    {
        $qb = $this->createQueryBuilder('pe')
            ->andWhere('pe.product = :productId')
            ->setParameter('productId', $productId);

        if (null !== $hardwareVersion) {
            $qb->andWhere('pe.hardwareVersion = :hwVersion')
                ->setParameter('hwVersion', $hardwareVersion);
        } else {
            $qb->andWhere('pe.hardwareVersion IS NULL');
        }

        if (null !== $softwareVersion) {
            $qb->andWhere('pe.softwareVersion = :swVersion')
                ->setParameter('swVersion', $softwareVersion);
        } else {
            $qb->andWhere('pe.softwareVersion IS NULL');
        }

        return $qb->orderBy('pe.endpointId', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
