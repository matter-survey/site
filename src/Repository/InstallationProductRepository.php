<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InstallationProduct;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstallationProduct>
 */
class InstallationProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstallationProduct::class);
    }

    public function save(InstallationProduct $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(InstallationProduct $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all products in an installation.
     *
     * @return InstallationProduct[]
     */
    public function findByInstallation(string $installationId): array
    {
        return $this->createQueryBuilder('ip')
            ->andWhere('ip.installationId = :installationId')
            ->setParameter('installationId', $installationId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count installations containing a specific product.
     */
    public function countInstallationsForProduct(int $productId): int
    {
        return (int) $this->createQueryBuilder('ip')
            ->select('COUNT(DISTINCT ip.installationId)')
            ->andWhere('ip.product = :productId')
            ->setParameter('productId', $productId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
