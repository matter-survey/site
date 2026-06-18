<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\WizardAnalytics;
use App\Repository\WizardAnalyticsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WizardAnalyticsRepositoryTest extends KernelTestCase
{
    private WizardAnalyticsRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(WizardAnalyticsRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    private function makeRecord(
        string $sessionId,
        int $step,
        ?string $category = null,
        ?array $connectivity = null,
        bool $completed = false,
        ?\DateTimeInterface $createdAt = null,
    ): WizardAnalytics {
        $analytics = new WizardAnalytics()
            ->setSessionId($sessionId)
            ->setStep($step)
            ->setCategory($category)
            ->setConnectivity($connectivity)
            ->setCompleted($completed);

        if ($createdAt instanceof \DateTimeInterface) {
            $analytics->setCreatedAt($createdAt);
        }

        return $analytics;
    }

    public function testSaveWithFlush(): void
    {
        $analytics = $this->makeRecord('sess-1', 1, 'lighting');

        $this->repository->save($analytics, true);

        $found = $this->repository->find($analytics->getId());
        $this->assertInstanceOf(WizardAnalytics::class, $found);
        $this->assertSame('lighting', $found->getCategory());
    }

    public function testSaveWithoutFlushDefersUntilManualFlush(): void
    {
        $analytics = $this->makeRecord('sess-2', 1);

        $this->repository->save($analytics, false);
        $this->entityManager->flush();

        $this->assertInstanceOf(WizardAnalytics::class, $this->repository->find($analytics->getId()));
    }

    public function testGetCategoryDemandCountsDistinctSessions(): void
    {
        $this->repository->save($this->makeRecord('a', 1, 'lighting'));
        $this->repository->save($this->makeRecord('a', 2, 'lighting')); // same session, same category — should count once
        $this->repository->save($this->makeRecord('b', 1, 'lighting'));
        $this->repository->save($this->makeRecord('c', 1, 'climate'));
        $this->repository->save($this->makeRecord('d', 1)); // null category — excluded
        $this->entityManager->flush();

        $demand = $this->repository->getCategoryDemand();

        $this->assertSame(2, $demand['lighting']);
        $this->assertSame(1, $demand['climate']);
        $this->assertArrayNotHasKey('', $demand);
    }

    public function testGetCategoryDemandRespectsSinceFilter(): void
    {
        $old = new \DateTime('-30 days');
        $recent = new \DateTime('-1 hour');

        $this->repository->save($this->makeRecord('old', 1, 'plugs', createdAt: $old));
        $this->repository->save($this->makeRecord('new', 1, 'plugs', createdAt: $recent));
        $this->entityManager->flush();

        $since = new \DateTime('-1 day');
        $demand = $this->repository->getCategoryDemand($since);

        $this->assertSame(1, $demand['plugs'] ?? 0);
    }

    public function testGetConnectivityDemandFlattensJsonArray(): void
    {
        $this->repository->save($this->makeRecord('s1', 1, connectivity: ['wifi']));
        $this->repository->save($this->makeRecord('s2', 1, connectivity: ['wifi', 'thread']));
        $this->repository->save($this->makeRecord('s3', 1, connectivity: ['thread']));
        $this->repository->save($this->makeRecord('s4', 1));
        $this->entityManager->flush();

        $demand = $this->repository->getConnectivityDemand();

        // wifi: s1+s2 = 2 distinct sessions
        // thread: s2+s3 = 2 distinct sessions
        $this->assertGreaterThanOrEqual(2, $demand['wifi'] ?? 0);
        $this->assertGreaterThanOrEqual(2, $demand['thread'] ?? 0);
    }

    public function testGetConnectivityDemandRespectsSinceFilter(): void
    {
        $old = new \DateTime('-30 days');
        $recent = new \DateTime('-1 hour');

        $this->repository->save($this->makeRecord('old', 1, connectivity: ['wifi'], createdAt: $old));
        $this->repository->save($this->makeRecord('new', 1, connectivity: ['ethernet'], createdAt: $recent));
        $this->entityManager->flush();

        $since = new \DateTime('-1 day');
        $demand = $this->repository->getConnectivityDemand($since);

        $this->assertSame(1, $demand['ethernet'] ?? 0);
        $this->assertArrayNotHasKey('wifi', array_intersect_key($demand, ['wifi' => 1]));
    }

    public function testGetCompletionStats(): void
    {
        $this->repository->save($this->makeRecord('a', 1, completed: true));
        $this->repository->save($this->makeRecord('b', 1, completed: false));
        $this->repository->save($this->makeRecord('c', 1, completed: true));
        $this->entityManager->flush();

        $stats = $this->repository->getCompletionStats();

        $this->assertGreaterThanOrEqual(3, $stats['total_sessions']);
        $this->assertGreaterThanOrEqual(2, $stats['completed_sessions']);
        $this->assertGreaterThan(0.0, $stats['completion_rate']);
    }

    public function testGetCompletionStatsReturnsZeroRateWhenEmpty(): void
    {
        $since = new \DateTime('+10 years'); // future window — no records

        $stats = $this->repository->getCompletionStats($since);

        $this->assertSame(0, $stats['total_sessions']);
        $this->assertSame(0, $stats['completed_sessions']);
        $this->assertEqualsWithDelta(0.0, $stats['completion_rate'], PHP_FLOAT_EPSILON);
    }

    public function testGetCompletionStatsRespectsSinceFilter(): void
    {
        $old = new \DateTime('-30 days');
        $recent = new \DateTime('-1 hour');

        $this->repository->save($this->makeRecord('old', 1, completed: true, createdAt: $old));
        $this->repository->save($this->makeRecord('new', 1, completed: false, createdAt: $recent));
        $this->entityManager->flush();

        $stats = $this->repository->getCompletionStats(new \DateTime('-1 day'));

        // Only "new" should be counted
        $this->assertSame(1, $stats['total_sessions']);
        $this->assertSame(0, $stats['completed_sessions']);
        $this->assertEqualsWithDelta(0.0, $stats['completion_rate'], PHP_FLOAT_EPSILON);
    }

    public function testFindLatestBySessionIdReturnsHighestStep(): void
    {
        $this->repository->save($this->makeRecord('multi-step', 1, 'plugs'));
        $this->repository->save($this->makeRecord('multi-step', 2, 'plugs'));
        $this->repository->save($this->makeRecord('multi-step', 3, 'plugs'));
        $this->entityManager->flush();

        $latest = $this->repository->findLatestBySessionId('multi-step');

        $this->assertInstanceOf(WizardAnalytics::class, $latest);
        $this->assertSame(3, $latest->getStep());
    }

    public function testFindLatestBySessionIdReturnsNullForUnknown(): void
    {
        $this->assertNotInstanceOf(WizardAnalytics::class, $this->repository->findLatestBySessionId('does-not-exist'));
    }
}
