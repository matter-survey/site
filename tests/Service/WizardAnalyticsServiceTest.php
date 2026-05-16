<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Repository\WizardAnalyticsRepository;
use App\Service\WizardAnalyticsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WizardAnalyticsServiceTest extends KernelTestCase
{
    private WizardAnalyticsService $service;
    private WizardAnalyticsRepository $repository;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(WizardAnalyticsService::class);
        $this->repository = self::getContainer()->get(WizardAnalyticsRepository::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testGenerateSessionIdReturnsHexString(): void
    {
        $id = $this->service->generateSessionId();
        $this->assertSame(32, strlen($id), 'session id should be 32 hex chars (16 random bytes)');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $id);

        $other = $this->service->generateSessionId();
        $this->assertNotSame($id, $other, 'two consecutive calls should not collide');
    }

    public function testRecordStepWithMinimalAnswers(): void
    {
        $sessionId = 'minimal-'.bin2hex(random_bytes(4));
        $this->service->recordStep($sessionId, 1, []);

        $latest = $this->repository->findLatestBySessionId($sessionId);
        $this->assertInstanceOf(\App\Entity\WizardAnalytics::class, $latest);
        $this->assertSame(1, $latest->getStep());
        $this->assertNull($latest->getCategory());
        $this->assertNull($latest->getConnectivity());
        $this->assertNull($latest->getMinRating());
        $this->assertNull($latest->getBinding());
        $this->assertNull($latest->getOwnedDeviceCount());
    }

    public function testRecordStepWithFullAnswers(): void
    {
        $sessionId = 'full-'.bin2hex(random_bytes(4));
        $this->service->recordStep($sessionId, 4, [
            'category' => 'lighting',
            'connectivity' => ['wifi', 'thread'],
            'min_rating' => '4',
            'binding' => 'yes',
            'owned' => ['device-a', 'device-b', 'device-c'],
        ]);

        $latest = $this->repository->findLatestBySessionId($sessionId);
        $this->assertInstanceOf(\App\Entity\WizardAnalytics::class, $latest);
        $this->assertSame('lighting', $latest->getCategory());
        $this->assertSame(['wifi', 'thread'], $latest->getConnectivity());
        $this->assertSame(4, $latest->getMinRating());
        $this->assertSame('yes', $latest->getBinding());
        $this->assertSame(3, $latest->getOwnedDeviceCount());
        $this->assertFalse($latest->isCompleted());
    }

    public function testRecordStepWithEmptyBindingIgnored(): void
    {
        $sessionId = 'empty-binding-'.bin2hex(random_bytes(4));
        // empty string for binding should be treated as "not set"
        $this->service->recordStep($sessionId, 2, ['binding' => '']);

        $latest = $this->repository->findLatestBySessionId($sessionId);
        $this->assertInstanceOf(\App\Entity\WizardAnalytics::class, $latest);
        $this->assertNull($latest->getBinding());
    }

    public function testRecordCompletionMarksLatestStepCompleted(): void
    {
        $sessionId = 'complete-'.bin2hex(random_bytes(4));
        $this->service->recordStep($sessionId, 1, ['category' => 'plugs']);
        $this->service->recordStep($sessionId, 2, ['category' => 'plugs']);

        $this->service->recordCompletion($sessionId);
        $this->entityManager->clear();

        $latest = $this->repository->findLatestBySessionId($sessionId);
        $this->assertInstanceOf(\App\Entity\WizardAnalytics::class, $latest);
        $this->assertSame(2, $latest->getStep());
        $this->assertTrue($latest->isCompleted());
    }

    public function testRecordCompletionOnUnknownSessionIsNoOp(): void
    {
        // Should not throw even when no analytics rows exist for the session.
        $this->service->recordCompletion('does-not-exist-'.bin2hex(random_bytes(4)));
        $this->assertTrue(true);
    }

    public function testGetCategoryDemandDelegates(): void
    {
        $this->service->recordStep('demand-a', 1, ['category' => 'media']);
        $this->service->recordStep('demand-b', 1, ['category' => 'media']);

        $demand = $this->service->getCategoryDemand();
        $this->assertGreaterThanOrEqual(2, $demand['media'] ?? 0);
    }

    public function testGetConnectivityDemandDelegates(): void
    {
        $this->service->recordStep('conn-a', 1, ['category' => 'plugs', 'connectivity' => ['ethernet']]);

        $demand = $this->service->getConnectivityDemand();
        $this->assertArrayHasKey('ethernet', $demand);
    }

    public function testGetCompletionStatsDelegates(): void
    {
        $sessionId = 'stats-'.bin2hex(random_bytes(4));
        $this->service->recordStep($sessionId, 1, []);
        $this->service->recordCompletion($sessionId);

        $stats = $this->service->getCompletionStats();
        $this->assertArrayHasKey('total_sessions', $stats);
        $this->assertArrayHasKey('completed_sessions', $stats);
        $this->assertArrayHasKey('completion_rate', $stats);
        $this->assertGreaterThanOrEqual(1, $stats['total_sessions']);
    }
}
