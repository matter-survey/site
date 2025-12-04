<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WizardAnalytics;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WizardAnalytics>
 */
class WizardAnalyticsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WizardAnalytics::class);
    }

    public function save(WizardAnalytics $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Get category demand counts.
     *
     * @return array<string, int>
     */
    public function getCategoryDemand(?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->select('w.category, COUNT(DISTINCT w.sessionId) as demand')
            ->where('w.category IS NOT NULL')
            ->groupBy('w.category')
            ->orderBy('demand', 'DESC');

        if (null !== $since) {
            $qb->andWhere('w.createdAt >= :since')
                ->setParameter('since', $since);
        }

        $result = $qb->getQuery()->getResult();

        $demand = [];
        foreach ($result as $row) {
            $demand[$row['category']] = (int) $row['demand'];
        }

        return $demand;
    }

    /**
     * Get connectivity demand counts.
     *
     * @return array<string, int>
     */
    public function getConnectivityDemand(?\DateTimeInterface $since = null): array
    {
        // For JSON arrays, we need to count occurrences across all records
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT value as connectivity_type, COUNT(DISTINCT session_id) as demand
            FROM wizard_analytics, json_each(wizard_analytics.connectivity)
            WHERE connectivity IS NOT NULL
        ';
        $params = [];

        if (null !== $since) {
            $sql .= ' AND created_at >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }

        $sql .= ' GROUP BY value ORDER BY demand DESC';

        $result = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $demand = [];
        foreach ($result as $row) {
            $demand[$row['connectivity_type']] = (int) $row['demand'];
        }

        return $demand;
    }

    /**
     * Get completion rate statistics.
     *
     * @return array{total_sessions: int, completed_sessions: int, completion_rate: float}
     */
    public function getCompletionStats(?\DateTimeInterface $since = null): array
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(DISTINCT w.sessionId) as total_sessions')
            ->addSelect('COUNT(DISTINCT CASE WHEN w.completed = true THEN w.sessionId END) as completed_sessions');

        if (null !== $since) {
            $qb->where('w.createdAt >= :since')
                ->setParameter('since', $since);
        }

        $result = $qb->getQuery()->getSingleResult();

        $total = (int) $result['total_sessions'];
        $completed = (int) $result['completed_sessions'];

        return [
            'total_sessions' => $total,
            'completed_sessions' => $completed,
            'completion_rate' => $total > 0 ? round($completed / $total * 100, 1) : 0.0,
        ];
    }

    /**
     * Find the latest analytics record for a session.
     */
    public function findLatestBySessionId(string $sessionId): ?WizardAnalytics
    {
        return $this->createQueryBuilder('w')
            ->where('w.sessionId = :sessionId')
            ->setParameter('sessionId', $sessionId)
            ->orderBy('w.step', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
