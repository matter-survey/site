<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\DeviceScore;
use App\Dto\DeviceTypeScore;
use App\Service\DeviceScoreService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for DeviceScoreService.
 *
 * These tests verify the scoring algorithm and related functionality.
 */
class DeviceScoreServiceTest extends KernelTestCase
{
    private DeviceScoreService $scoreService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->scoreService = self::getContainer()->get(DeviceScoreService::class);
    }

    // ========================================================================
    // Score Calculation Tests
    // ========================================================================

    public function testCalculateDeviceScoreReturnsDeviceScore(): void
    {
        $endpoints = [
            [
                'device_types' => [256], // On/Off Light
                'server_clusters' => [3, 4, 6, 98, 29], // All mandatory for On/Off Light
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);

        $this->assertInstanceOf(DeviceScore::class, $score);
        $this->assertGreaterThan(0, $score->overallScore);
        $this->assertGreaterThanOrEqual(1, $score->starRating);
        $this->assertLessThanOrEqual(5, $score->starRating);
    }

    public function testCalculateDeviceScoreWithEmptyEndpoints(): void
    {
        $score = $this->scoreService->calculateDeviceScore([]);

        $this->assertInstanceOf(DeviceScore::class, $score);
        $this->assertEquals(0.0, $score->overallScore);
        $this->assertEquals(1, $score->starRating);
        $this->assertTrue($score->isCompliant);
        $this->assertEmpty($score->scoresByType);
    }

    public function testCalculateDeviceScoreSkipsSystemDeviceTypes(): void
    {
        $endpoints = [
            [
                'device_types' => [22, 17], // System device types (Root Node, etc.)
                'server_clusters' => [29, 40, 31],
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);

        // Should return default score since system types are skipped
        $this->assertEquals(0.0, $score->overallScore);
        $this->assertEmpty($score->scoresByType);
    }

    public function testCalculateDeviceScoreHandlesObjectDeviceTypes(): void
    {
        $endpoints = [
            [
                'device_types' => [['id' => 256]], // Object format from JSON
                'server_clusters' => [3, 4, 6, 98, 29],
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);

        $this->assertArrayHasKey(256, $score->scoresByType);
    }

    public function testCalculateDeviceScoreWithMultipleEndpoints(): void
    {
        $endpoints = [
            [
                'device_types' => [256], // On/Off Light
                'server_clusters' => [3, 4, 6, 98],
                'client_clusters' => [],
            ],
            [
                'device_types' => [769], // Thermostat
                'server_clusters' => [3, 29, 513],
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);

        // Should have scores for both device types
        $this->assertArrayHasKey(256, $score->scoresByType);
        $this->assertArrayHasKey(769, $score->scoresByType);
    }

    public function testCalculateDeviceScoreUsesHighestTypeScore(): void
    {
        // Create endpoints where one device type scores higher
        $endpoints = [
            [
                'device_types' => [256], // On/Off Light - fully compliant
                'server_clusters' => [3, 4, 6, 98, 29],
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);

        // Overall score should be from the best device type
        $bestTypeScore = $score->getBestTypeScore();
        $this->assertNotNull($bestTypeScore);
        $this->assertEquals($bestTypeScore->score, $score->overallScore);
    }

    // ========================================================================
    // Device Type Score Tests
    // ========================================================================

    public function testCalculateDeviceTypeScoreReturnsCorrectDto(): void
    {
        $score = $this->scoreService->calculateDeviceTypeScore(
            256, // On/Off Light
            [3, 4, 6, 98], // All mandatory server clusters
            []
        );

        $this->assertInstanceOf(DeviceTypeScore::class, $score);
        $this->assertEquals(256, $score->deviceTypeId);
        $this->assertEquals('On/Off Light', $score->deviceTypeName);
        $this->assertTrue($score->isCompliant);
        $this->assertIsArray($score->breakdown);
    }

    public function testCalculateDeviceTypeScoreWithMissingMandatory(): void
    {
        $score = $this->scoreService->calculateDeviceTypeScore(
            256, // On/Off Light
            [3, 4, 98], // Missing On/Off (6)
            []
        );

        $this->assertFalse($score->isCompliant);
        // Non-compliant devices should have lower star rating
        $this->assertLessThanOrEqual(2, $score->starRating);
    }

    public function testCalculateDeviceTypeScoreWithOptionalClusters(): void
    {
        // On/Off Light with Level Control (optional)
        $scoreWithOptional = $this->scoreService->calculateDeviceTypeScore(
            256,
            [3, 4, 6, 98, 8], // Mandatory + Level Control
            []
        );

        $scoreWithoutOptional = $this->scoreService->calculateDeviceTypeScore(
            256,
            [3, 4, 6, 98], // Just mandatory
            []
        );

        // Score with optional clusters should be higher
        $this->assertGreaterThan($scoreWithoutOptional->score, $scoreWithOptional->score);
    }

    public function testCalculateDeviceTypeScoreBreakdownIsCorrect(): void
    {
        $score = $this->scoreService->calculateDeviceTypeScore(
            256,
            [3, 4, 6, 98], // All mandatory server clusters
            []
        );

        $this->assertArrayHasKey('mandatoryServerScore', $score->breakdown);
        $this->assertArrayHasKey('mandatoryClientScore', $score->breakdown);
        $this->assertArrayHasKey('optionalServerScore', $score->breakdown);
        $this->assertArrayHasKey('optionalClientScore', $score->breakdown);

        // Mandatory server score should be 100 since all are implemented
        $this->assertEquals(100.0, $score->breakdown['mandatoryServerScore']);
    }

    // ========================================================================
    // Thermostat Client Cluster Bonus Tests
    // ========================================================================

    public function testThermostatClientClusterBonusApplied(): void
    {
        // Thermostat without client clusters
        $scoreWithout = $this->scoreService->calculateDeviceTypeScore(
            769, // Thermostat
            [3, 29, 513], // Basic thermostat clusters
            []
        );

        // Thermostat with key client clusters (Temperature Measurement, Fan Control)
        $scoreWith = $this->scoreService->calculateDeviceTypeScore(
            769,
            [3, 29, 513],
            [514, 1026] // Fan Control, Temperature Measurement
        );

        // Score with client clusters should be higher due to client bonus
        $this->assertGreaterThan($scoreWithout->score, $scoreWith->score);
        $this->assertGreaterThan(0, $scoreWith->clientBonus);
    }

    // ========================================================================
    // Star Rating Conversion Tests
    // ========================================================================

    public function testScoreToStarsConversion(): void
    {
        // Test all thresholds
        $this->assertEquals(5, $this->scoreService->scoreToStars(95.0, true));
        $this->assertEquals(5, $this->scoreService->scoreToStars(90.0, true));
        $this->assertEquals(4, $this->scoreService->scoreToStars(89.0, true));
        $this->assertEquals(4, $this->scoreService->scoreToStars(75.0, true));
        $this->assertEquals(3, $this->scoreService->scoreToStars(74.0, true));
        $this->assertEquals(3, $this->scoreService->scoreToStars(60.0, true));
        $this->assertEquals(2, $this->scoreService->scoreToStars(59.0, true));
        $this->assertEquals(2, $this->scoreService->scoreToStars(40.0, true));
        $this->assertEquals(1, $this->scoreService->scoreToStars(39.0, true));
        $this->assertEquals(1, $this->scoreService->scoreToStars(0.0, true));
    }

    public function testNonCompliantDevicesCappedAt2Stars(): void
    {
        // Even with a high score, non-compliant should be max 2 stars
        $this->assertEquals(2, $this->scoreService->scoreToStars(95.0, false));
        $this->assertEquals(2, $this->scoreService->scoreToStars(80.0, false));
        $this->assertEquals(2, $this->scoreService->scoreToStars(50.0, false));
        $this->assertEquals(1, $this->scoreService->scoreToStars(30.0, false));
    }

    // ========================================================================
    // Cache Operations Tests
    // ========================================================================

    public function testGetCachedScoresForDevicesReturnsEmptyForNoDevices(): void
    {
        $scores = $this->scoreService->getCachedScoresForDevices([]);

        $this->assertIsArray($scores);
        $this->assertEmpty($scores);
    }

    public function testGetCachedScoresForDevicesReturnsArrayKeyedByDeviceId(): void
    {
        // Note: This test depends on the database having cached scores
        // In a fresh test database, this might return empty
        $scores = $this->scoreService->getCachedScoresForDevices([1, 2, 3]);

        $this->assertIsArray($scores);
        // All returned scores should be keyed by device ID
        foreach ($scores as $deviceId => $score) {
            $this->assertIsInt($deviceId);
            $this->assertInstanceOf(DeviceScore::class, $score);
        }
    }

    public function testGetCachedScoresHandlesNonExistentDevices(): void
    {
        // Request scores for device IDs that don't exist
        $scores = $this->scoreService->getCachedScoresForDevices([999999, 999998]);

        $this->assertIsArray($scores);
        // Should not throw, just return empty or partial results
    }

    // ========================================================================
    // Device Type Score DTO Tests
    // ========================================================================

    public function testDeviceTypeScoreToArrayAndFromArray(): void
    {
        $score = $this->scoreService->calculateDeviceTypeScore(
            256,
            [3, 4, 6, 98],
            []
        );

        $array = $score->toArray();
        $restored = DeviceTypeScore::fromArray($array);

        $this->assertEquals($score->deviceTypeId, $restored->deviceTypeId);
        $this->assertEquals($score->deviceTypeName, $restored->deviceTypeName);
        $this->assertEquals($score->score, $restored->score);
        $this->assertEquals($score->starRating, $restored->starRating);
        $this->assertEquals($score->isCompliant, $restored->isCompliant);
    }

    // ========================================================================
    // Device Score DTO Tests
    // ========================================================================

    public function testDeviceScoreGetBestTypeScore(): void
    {
        $endpoints = [
            [
                'device_types' => [256],
                'server_clusters' => [3, 4, 6, 98],
                'client_clusters' => [],
            ],
        ];

        $score = $this->scoreService->calculateDeviceScore($endpoints);
        $best = $score->getBestTypeScore();

        $this->assertNotNull($best);
        $this->assertEquals(256, $best->deviceTypeId);
    }

    public function testDeviceScoreGetBestTypeScoreReturnsNullWhenEmpty(): void
    {
        $score = $this->scoreService->calculateDeviceScore([]);
        $best = $score->getBestTypeScore();

        $this->assertNull($best);
    }

    // ========================================================================
    // Ranked Device Query Tests
    // ========================================================================

    public function testGetDevicesRankedByScoreReturnsArray(): void
    {
        $devices = $this->scoreService->getDevicesRankedByScore(256, 10, 0);

        $this->assertIsArray($devices);
        // Each device should have score-related fields
        foreach ($devices as $device) {
            $this->assertArrayHasKey('overall_score', $device);
            $this->assertArrayHasKey('star_rating', $device);
            $this->assertArrayHasKey('is_compliant', $device);
        }
    }

    public function testGetDevicesRankedByScoreRespectsLimit(): void
    {
        $devices = $this->scoreService->getDevicesRankedByScore(256, 5, 0);

        $this->assertLessThanOrEqual(5, \count($devices));
    }

    public function testGetDevicesRankedByScoreIsSortedByScore(): void
    {
        $devices = $this->scoreService->getDevicesRankedByScore(256, 20, 0);

        // Should always return an array (empty or not)
        $this->assertIsArray($devices);

        // If we have multiple devices, verify they're sorted by score descending
        if (\count($devices) > 1) {
            $previousScore = PHP_INT_MAX;
            foreach ($devices as $device) {
                $this->assertLessThanOrEqual($previousScore, (float) $device['overall_score']);
                $previousScore = (float) $device['overall_score'];
            }
        }
    }
}
