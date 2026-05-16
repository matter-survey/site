<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClusterVersion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ClusterVersion>
 */
class ClusterVersionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClusterVersion::class);
    }

    /**
     * @return list<ClusterVersion> ordered by Matter version ascending
     */
    public function findByClusterId(int $clusterId): array
    {
        return $this->createQueryBuilder('cv')
            ->where('cv.clusterId = :id')
            ->setParameter('id', $clusterId)
            ->orderBy('cv.matterVersion', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ClusterVersion>
     */
    public function findByMatterVersion(string $matterVersion): array
    {
        return $this->createQueryBuilder('cv')
            ->where('cv.matterVersion = :v')
            ->setParameter('v', $matterVersion)
            ->orderBy('cv.clusterId', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
