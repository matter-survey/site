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

    /**
     * Returns the highest Matter version string present in the table, or null
     * if no snapshots exist yet. Compares lexicographically (works for "1.0"
     * through "1.9"; if/when we hit double-digit minors a natural-sort here
     * will be needed). Includes "master" — typically the highest entry.
     */
    public function findLatestMatterVersion(): ?string
    {
        $result = $this->createQueryBuilder('cv')
            ->select('DISTINCT cv.matterVersion')
            ->orderBy('cv.matterVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['matterVersion'] ?? null;
    }

    /**
     * Like findLatestMatterVersion but excludes the "master" pseudo-version,
     * returning the most recent N.N released Matter spec we have a snapshot
     * for.
     */
    public function findLatestReleasedMatterVersion(): ?string
    {
        $result = $this->createQueryBuilder('cv')
            ->select('DISTINCT cv.matterVersion')
            ->where('cv.matterVersion != :master')
            ->setParameter('master', 'master')
            ->orderBy('cv.matterVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result['matterVersion'] ?? null;
    }
}
