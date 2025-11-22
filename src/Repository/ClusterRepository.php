<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Cluster;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cluster>
 */
class ClusterRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cluster::class);
    }

    public function save(Cluster $cluster, bool $flush = false): void
    {
        $this->getEntityManager()->persist($cluster);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find a cluster by its hex ID (e.g., "0x0006").
     */
    public function findByHexId(string $hexId): ?Cluster
    {
        return $this->createQueryBuilder('c')
            ->where('c.hexId = :hexId')
            ->setParameter('hexId', $hexId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all clusters grouped by category.
     *
     * @return array<string, Cluster[]>
     */
    public function findAllGroupedByCategory(): array
    {
        $clusters = $this->createQueryBuilder('c')
            ->orderBy('c.category', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($clusters as $cluster) {
            $category = $cluster->getCategory() ?? 'Other';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $cluster;
        }

        return $grouped;
    }

    /**
     * Find clusters by category.
     *
     * @return Cluster[]
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.category = :category')
            ->setParameter('category', $category)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all unique categories.
     *
     * @return string[]
     */
    public function findAllCategories(): array
    {
        $result = $this->createQueryBuilder('c')
            ->select('DISTINCT c.category')
            ->where('c.category IS NOT NULL')
            ->orderBy('c.category', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_filter($result);
    }

    /**
     * Find all global clusters.
     *
     * @return Cluster[]
     */
    public function findGlobalClusters(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isGlobal = :isGlobal')
            ->setParameter('isGlobal', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all application clusters.
     *
     * @return Cluster[]
     */
    public function findApplicationClusters(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.isGlobal = :isGlobal')
            ->setParameter('isGlobal', false)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search clusters by name.
     *
     * @return Cluster[]
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('c.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
