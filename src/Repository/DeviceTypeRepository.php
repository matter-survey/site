<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DeviceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeviceType>
 */
class DeviceTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeviceType::class);
    }

    public function save(DeviceType $deviceType, bool $flush = false): void
    {
        $this->getEntityManager()->persist($deviceType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find all device types grouped by display category.
     *
     * @return array<string, DeviceType[]>
     */
    public function findAllGroupedByCategory(): array
    {
        $deviceTypes = $this->createQueryBuilder('dt')
            ->orderBy('dt.displayCategory', 'ASC')
            ->addOrderBy('dt.name', 'ASC')
            ->getQuery()
            ->getResult();

        $grouped = [];
        foreach ($deviceTypes as $dt) {
            $category = $dt->getDisplayCategory() ?? 'System';
            if (!isset($grouped[$category])) {
                $grouped[$category] = [];
            }
            $grouped[$category][] = $dt;
        }

        return $grouped;
    }

    /**
     * Find device types by display category.
     *
     * @return DeviceType[]
     */
    public function findByCategory(string $category): array
    {
        return $this->createQueryBuilder('dt')
            ->where('dt.displayCategory = :category')
            ->setParameter('category', $category)
            ->orderBy('dt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find device types by spec version.
     *
     * @return DeviceType[]
     */
    public function findBySpecVersion(string $specVersion): array
    {
        return $this->createQueryBuilder('dt')
            ->where('dt.specVersion = :specVersion')
            ->setParameter('specVersion', $specVersion)
            ->orderBy('dt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all unique display categories.
     *
     * @return string[]
     */
    public function findAllCategories(): array
    {
        $result = $this->createQueryBuilder('dt')
            ->select('DISTINCT dt.displayCategory')
            ->where('dt.displayCategory IS NOT NULL')
            ->orderBy('dt.displayCategory', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_filter($result);
    }

    /**
     * Get all unique spec versions.
     *
     * @return string[]
     */
    public function findAllSpecVersions(): array
    {
        $result = $this->createQueryBuilder('dt')
            ->select('DISTINCT dt.specVersion')
            ->where('dt.specVersion IS NOT NULL')
            ->orderBy('dt.specVersion', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_filter($result);
    }

    /**
     * Count device types by spec version.
     *
     * @return array<string, int>
     */
    public function countBySpecVersion(): array
    {
        $result = $this->createQueryBuilder('dt')
            ->select('dt.specVersion, COUNT(dt.id) as count')
            ->where('dt.specVersion IS NOT NULL')
            ->groupBy('dt.specVersion')
            ->orderBy('dt.specVersion', 'ASC')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($result as $row) {
            $counts[$row['specVersion']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Find device types that require a specific cluster.
     *
     * @return DeviceType[]
     */
    public function findByMandatoryServerCluster(int $clusterId): array
    {
        // For SQLite with JSON, we need a raw query
        $conn = $this->getEntityManager()->getConnection();

        $sql = "
            SELECT dt.id
            FROM device_types dt
            WHERE EXISTS (
                SELECT 1 FROM json_each(dt.mandatory_server_clusters)
                WHERE json_extract(value, '$.id') = :clusterId
            )
        ";

        $ids = $conn->executeQuery($sql, ['clusterId' => $clusterId])->fetchFirstColumn();

        if (empty($ids)) {
            return [];
        }

        return $this->createQueryBuilder('dt')
            ->where('dt.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('dt.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Search device types by name.
     *
     * @return DeviceType[]
     */
    public function search(string $query, int $limit = 20): array
    {
        return $this->createQueryBuilder('dt')
            ->where('dt.name LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('dt.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
