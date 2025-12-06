<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceScore;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceScore>
 */
class DeviceScoreRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceScore::class);
    }

    public function save(DeviceScore $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(DeviceScore $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find score for a product.
     */
    public function findByProduct(int $productId): ?DeviceScore
    {
        return $this->createQueryBuilder('ds')
            ->andWhere('ds.product = :productId')
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find scores for multiple products.
     *
     * @param array<int> $productIds
     *
     * @return array<int, DeviceScore>
     */
    public function findByProducts(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $results = $this->createQueryBuilder('ds')
            ->andWhere('ds.product IN (:productIds)')
            ->setParameter('productIds', $productIds)
            ->getQuery()
            ->getResult();

        // Index by product ID for easy lookup
        $indexed = [];
        foreach ($results as $score) {
            $indexed[$score->getProduct()->getId()] = $score;
        }

        return $indexed;
    }
}
