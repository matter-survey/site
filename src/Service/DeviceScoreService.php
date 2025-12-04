<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\DeviceScore;
use App\Dto\DeviceTypeScore;
use App\Repository\DeviceRepository;
use Doctrine\DBAL\Connection;

/**
 * Service for calculating and caching device scores based on cluster implementation.
 */
class DeviceScoreService
{
    public function __construct(
        private readonly MatterRegistry $matterRegistry,
        private readonly DeviceRepository $deviceRepository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Calculate the overall score for a device based on its endpoints.
     *
     * @param array<array<string, mixed>> $endpoints Endpoint data with server_clusters, client_clusters, device_types
     */
    public function calculateDeviceScore(array $endpoints): DeviceScore
    {
        $scoresByType = [];
        $overallCompliant = true;
        $bestScore = 0.0;

        foreach ($endpoints as $endpoint) {
            /** @var int[] $serverClusters */
            $serverClusters = $endpoint['server_clusters'] ?? [];
            /** @var int[] $clientClusters */
            $clientClusters = $endpoint['client_clusters'] ?? [];
            /** @var array<int|array{id?: int}> $deviceTypes */
            $deviceTypes = $endpoint['device_types'] ?? [];

            foreach ($deviceTypes as $dt) {
                $deviceTypeId = \is_array($dt) ? ($dt['id'] ?? null) : $dt;
                if (null === $deviceTypeId || $deviceTypeId < 256) {
                    // Skip system device types
                    continue;
                }

                if (!isset($scoresByType[$deviceTypeId])) {
                    $typeScore = $this->calculateDeviceTypeScore(
                        (int) $deviceTypeId,
                        $serverClusters,
                        $clientClusters
                    );
                    $scoresByType[$deviceTypeId] = $typeScore;

                    if (!$typeScore->isCompliant) {
                        $overallCompliant = false;
                    }
                    if ($typeScore->score > $bestScore) {
                        $bestScore = $typeScore->score;
                    }
                }
            }
        }

        // If no device types found, return a default score
        if (empty($scoresByType)) {
            return new DeviceScore(
                overallScore: 0.0,
                starRating: 1,
                isCompliant: true,
                scoresByType: [],
            );
        }

        $starRating = $this->scoreToStars($bestScore, $overallCompliant);

        return new DeviceScore(
            overallScore: round($bestScore, 1),
            starRating: $starRating,
            isCompliant: $overallCompliant,
            scoresByType: $scoresByType,
        );
    }

    /**
     * Calculate score for a specific device type.
     *
     * @param int   $deviceTypeId   The device type ID
     * @param int[] $serverClusters Server clusters the device implements
     * @param int[] $clientClusters Client clusters the device implements
     */
    public function calculateDeviceTypeScore(
        int $deviceTypeId,
        array $serverClusters,
        array $clientClusters,
    ): DeviceTypeScore {
        $weights = $this->matterRegistry->getDeviceTypeScoringWeights($deviceTypeId);
        $analysis = $this->matterRegistry->analyzeClusterGaps(
            $deviceTypeId,
            $serverClusters,
            $clientClusters
        );

        $deviceTypeName = $analysis['deviceType']['name'] ?? "Device Type $deviceTypeId";
        $compliance = $analysis['compliance'];

        // Get spec requirements
        $mandatoryServer = $analysis['deviceType']['mandatoryServerClusters'] ?? [];
        $mandatoryClient = $analysis['deviceType']['mandatoryClientClusters'] ?? [];
        $optionalServer = $analysis['deviceType']['optionalServerClusters'] ?? [];
        $optionalClient = $analysis['deviceType']['optionalClientClusters'] ?? [];

        // Calculate component scores (0-100 each)
        $totalMandatoryServer = \count($mandatoryServer);
        $missingMandatoryServer = \count($analysis['missingMandatoryServer']);
        $mandatoryServerScore = $totalMandatoryServer > 0
            ? (($totalMandatoryServer - $missingMandatoryServer) / $totalMandatoryServer) * 100
            : 100;

        $totalMandatoryClient = \count($mandatoryClient);
        $missingMandatoryClient = \count($analysis['missingMandatoryClient']);
        $mandatoryClientScore = $totalMandatoryClient > 0
            ? (($totalMandatoryClient - $missingMandatoryClient) / $totalMandatoryClient) * 100
            : 100;

        $totalOptionalServer = \count($optionalServer);
        $implementedOptionalServer = \count($analysis['implementedOptionalServer']);
        $optionalServerScore = $totalOptionalServer > 0
            ? ($implementedOptionalServer / $totalOptionalServer) * 100
            : 0;

        $totalOptionalClient = \count($optionalClient);
        $implementedOptionalClient = \count($analysis['implementedOptionalClient']);
        $optionalClientScore = $totalOptionalClient > 0
            ? ($implementedOptionalClient / $totalOptionalClient) * 100
            : 0;

        // Calculate key client cluster bonus
        $clientBonus = 0.0;
        $keyClientClusters = $weights['keyClientClusters'];
        if (!empty($keyClientClusters)) {
            $implementedKeyClients = array_intersect($keyClientClusters, $clientClusters);
            $keyClientRatio = \count($implementedKeyClients) / \count($keyClientClusters);
            $clientBonus = $keyClientRatio * $weights['keyClientBonus'] * 100;
        }

        // Calculate weighted score
        $totalWeight = $weights['mandatoryServerWeight']
            + $weights['mandatoryClientWeight']
            + $weights['optionalServerWeight']
            + $weights['optionalClientWeight'];

        $weightedScore = (
            ($mandatoryServerScore * $weights['mandatoryServerWeight']) +
            ($mandatoryClientScore * $weights['mandatoryClientWeight']) +
            ($optionalServerScore * $weights['optionalServerWeight']) +
            ($optionalClientScore * $weights['optionalClientWeight'])
        ) / $totalWeight;

        $finalScore = min(100, $weightedScore + $clientBonus);
        $isCompliant = $compliance['mandatory'];
        $starRating = $this->scoreToStars($finalScore, $isCompliant);

        return new DeviceTypeScore(
            deviceTypeId: $deviceTypeId,
            deviceTypeName: $deviceTypeName,
            score: round($finalScore, 1),
            starRating: $starRating,
            isCompliant: $isCompliant,
            mandatoryScore: round($compliance['mandatoryScore'], 1),
            optionalScore: round($compliance['optionalScore'], 1),
            clientBonus: round($clientBonus, 1),
            breakdown: [
                'mandatoryServerScore' => round($mandatoryServerScore, 1),
                'mandatoryClientScore' => round($mandatoryClientScore, 1),
                'optionalServerScore' => round($optionalServerScore, 1),
                'optionalClientScore' => round($optionalClientScore, 1),
            ],
        );
    }

    /**
     * Convert a percentage score to a 1-5 star rating.
     */
    public function scoreToStars(float $score, bool $isCompliant): int
    {
        // Non-compliant devices are capped at 2 stars
        if (!$isCompliant) {
            return min(2, $this->percentageToStars($score));
        }

        return $this->percentageToStars($score);
    }

    /**
     * Convert percentage to star rating.
     */
    private function percentageToStars(float $score): int
    {
        return match (true) {
            $score >= 90 => 5,
            $score >= 75 => 4,
            $score >= 60 => 3,
            $score >= 40 => 2,
            default => 1,
        };
    }

    /**
     * Get cached scores for multiple devices.
     *
     * @param int[] $deviceIds
     *
     * @return array<int, DeviceScore> Scores keyed by device ID
     */
    public function getCachedScoresForDevices(array $deviceIds): array
    {
        if (empty($deviceIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, \count($deviceIds), '?'));

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT device_id, overall_score, star_rating, is_compliant, scores_by_type, best_version
                 FROM device_scores WHERE device_id IN ($placeholders)",
                $deviceIds
            );
        } catch (\Throwable) {
            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $scoresByType = json_decode($row['scores_by_type'], true) ?: [];
            $parsedScoresByType = [];
            foreach ($scoresByType as $typeId => $typeData) {
                $parsedScoresByType[$typeId] = DeviceTypeScore::fromArray($typeData);
            }

            $scores[(int) $row['device_id']] = new DeviceScore(
                overallScore: (float) $row['overall_score'],
                starRating: (int) $row['star_rating'],
                isCompliant: (bool) $row['is_compliant'],
                scoresByType: $parsedScoresByType,
                bestVersion: $row['best_version'],
            );
        }

        return $scores;
    }

    /**
     * Rebuild the score cache for all devices.
     *
     * @return int Number of devices processed
     */
    public function rebuildScoreCache(): int
    {
        // Get all devices
        $devices = $this->connection->fetchAllAssociative(
            'SELECT id FROM products ORDER BY id'
        );

        $count = 0;
        $batchSize = 100;

        foreach (array_chunk($devices, $batchSize) as $batch) {
            foreach ($batch as $device) {
                $deviceId = (int) $device['id'];
                $this->updateDeviceScoreCache($deviceId);
                ++$count;
            }
        }

        return $count;
    }

    /**
     * Update the score cache for a single device.
     * Uses the LATEST software version's endpoints for scoring.
     */
    public function updateDeviceScoreCache(int $deviceId): void
    {
        // Get the latest version's endpoints specifically
        $endpoints = $this->getLatestVersionEndpoints($deviceId);

        if (empty($endpoints)) {
            // Remove from cache if no endpoints
            $this->connection->executeStatement(
                'DELETE FROM device_scores WHERE device_id = ?',
                [$deviceId]
            );

            return;
        }

        $score = $this->calculateDeviceScore($endpoints);

        // Find best version (if a different version has better scores)
        $bestVersion = $this->findBestVersion($deviceId);

        $scoresByTypeJson = json_encode(
            array_map(fn (DeviceTypeScore $ts) => $ts->toArray(), $score->scoresByType)
        );

        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO device_scores
             (device_id, overall_score, star_rating, is_compliant, scores_by_type, best_version, computed_at)
             VALUES (?, ?, ?, ?, ?, ?, datetime("now"))',
            [
                $deviceId,
                $score->overallScore,
                $score->starRating,
                $score->isCompliant ? 1 : 0,
                $scoresByTypeJson,
                $bestVersion,
            ]
        );
    }

    /**
     * Get endpoints for the latest software version of a device.
     *
     * @return array<array<string, mixed>>
     */
    public function getLatestVersionEndpoints(int $deviceId): array
    {
        $versions = $this->deviceRepository->getDeviceEndpointVersions($deviceId);

        if (empty($versions)) {
            return [];
        }

        // First version is the latest (sorted by last_seen DESC in repository)
        $latestVersion = $versions[0];

        return $this->deviceRepository->getDeviceEndpointsByVersion(
            $deviceId,
            $latestVersion['hardware_version'],
            $latestVersion['software_version']
        );
    }

    /**
     * Calculate score for a device using the latest version's endpoints.
     *
     * @param array<array<string, mixed>>|null $endpoints If null, will fetch latest version endpoints
     */
    public function calculateDeviceScoreForLatestVersion(int $deviceId, ?array $endpoints = null): DeviceScore
    {
        if (null === $endpoints) {
            $endpoints = $this->getLatestVersionEndpoints($deviceId);
        }

        return $this->calculateDeviceScore($endpoints);
    }

    /**
     * Find the best software version for a device (if different versions have different scores).
     */
    public function findBestVersion(int $deviceId): ?string
    {
        $versions = $this->deviceRepository->getDeviceEndpointVersions($deviceId);

        if (\count($versions) <= 1) {
            return null;
        }

        $bestScore = 0.0;
        $bestVersion = null;
        $latestScore = 0.0;

        foreach ($versions as $i => $version) {
            $endpoints = $this->deviceRepository->getDeviceEndpointsByVersion(
                $deviceId,
                $version['hardware_version'],
                $version['software_version']
            );

            $score = $this->calculateDeviceScore($endpoints);

            // Track latest version's score (first in list is latest)
            if (0 === $i) {
                $latestScore = $score->overallScore;
            }

            if ($score->overallScore > $bestScore) {
                $bestScore = $score->overallScore;
                $bestVersion = $version['software_version'];
            }
        }

        // Only return version recommendation if there's meaningful improvement
        if ($bestVersion && $bestScore > $latestScore + 5) {
            return $bestVersion;
        }

        return null;
    }

    /**
     * Get devices ranked by score for a specific device type.
     *
     * @return array<array<string, mixed>>
     */
    public function getDevicesRankedByScore(int $deviceTypeId, int $limit = 50, int $offset = 0): array
    {
        // Query devices that have the specified device type and join with scores
        return $this->connection->fetchAllAssociative(
            'SELECT p.*, ds.overall_score, ds.star_rating, ds.is_compliant,
                    RANK() OVER (ORDER BY ds.overall_score DESC) as rank
             FROM products p
             INNER JOIN device_scores ds ON ds.device_id = p.id
             WHERE json_extract(ds.scores_by_type, :jsonPath) IS NOT NULL
             ORDER BY ds.overall_score DESC
             LIMIT :limit OFFSET :offset',
            [
                'jsonPath' => '$.'.$deviceTypeId,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }
}
