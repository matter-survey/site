<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WizardAnalytics;
use App\Repository\WizardAnalyticsRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service for tracking and querying wizard analytics data.
 */
class WizardAnalyticsService
{
    public function __construct(
        private readonly WizardAnalyticsRepository $repository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Record a wizard step interaction.
     *
     * @param array<string, mixed> $answers The wizard state at this step
     */
    public function recordStep(string $sessionId, int $step, array $answers): void
    {
        $analytics = new WizardAnalytics();
        $analytics->setSessionId($sessionId);
        $analytics->setStep($step);

        if (!empty($answers['category'])) {
            $analytics->setCategory($answers['category']);
        }

        if (!empty($answers['connectivity'])) {
            $analytics->setConnectivity($answers['connectivity']);
        }

        if (!empty($answers['min_rating'])) {
            $analytics->setMinRating((int) $answers['min_rating']);
        }

        if (isset($answers['binding']) && '' !== $answers['binding']) {
            $analytics->setBinding($answers['binding']);
        }

        if (!empty($answers['owned'])) {
            $analytics->setOwnedDeviceCount(\count($answers['owned']));
        }

        $this->repository->save($analytics, true);
    }

    /**
     * Mark a session as completed (user reached results).
     */
    public function recordCompletion(string $sessionId): void
    {
        $latest = $this->repository->findLatestBySessionId($sessionId);

        if (null !== $latest) {
            $latest->setCompleted(true);
            $this->entityManager->flush();
        }
    }

    /**
     * Get category demand statistics.
     *
     * @return array<string, int>
     */
    public function getCategoryDemand(?\DateTimeInterface $since = null): array
    {
        return $this->repository->getCategoryDemand($since);
    }

    /**
     * Get connectivity demand statistics.
     *
     * @return array<string, int>
     */
    public function getConnectivityDemand(?\DateTimeInterface $since = null): array
    {
        return $this->repository->getConnectivityDemand($since);
    }

    /**
     * Get completion rate statistics.
     *
     * @return array{total_sessions: int, completed_sessions: int, completion_rate: float}
     */
    public function getCompletionStats(?\DateTimeInterface $since = null): array
    {
        return $this->repository->getCompletionStats($since);
    }

    /**
     * Generate a new session ID.
     */
    public function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
