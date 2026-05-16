<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\DeviceScore;
use App\Dto\DeviceTypeScore;
use PHPUnit\Framework\TestCase;

class DeviceScoreTest extends TestCase
{
    private function makeTypeScore(int $id, string $name, float $score): DeviceTypeScore
    {
        return new DeviceTypeScore(
            deviceTypeId: $id,
            deviceTypeName: $name,
            score: $score,
            starRating: round($score / 20, 1),
            isCompliant: $score >= 70.0,
            mandatoryScore: $score * 0.6,
            optionalScore: $score * 0.3,
            clientBonus: $score * 0.1,
            breakdown: ['detail' => 'ok'],
        );
    }

    public function testConstructorAssignsFields(): void
    {
        $byType = [
            266 => $this->makeTypeScore(266, 'Plug', 85.0),
        ];

        $score = new DeviceScore(
            overallScore: 85.0,
            starRating: 4.25,
            isCompliant: true,
            scoresByType: $byType,
            bestVersion: '1.4',
        );

        $this->assertSame(85.0, $score->overallScore);
        $this->assertSame(4.25, $score->starRating);
        $this->assertTrue($score->isCompliant);
        $this->assertSame($byType, $score->scoresByType);
        $this->assertSame('1.4', $score->bestVersion);
    }

    public function testBestVersionDefaultsToNull(): void
    {
        $score = new DeviceScore(
            overallScore: 50.0,
            starRating: 2.5,
            isCompliant: false,
            scoresByType: [],
        );

        $this->assertNull($score->bestVersion);
    }

    public function testFromArrayRebuildsTypeScores(): void
    {
        $data = [
            'overallScore' => 80.0,
            'starRating' => 4.0,
            'isCompliant' => true,
            'bestVersion' => '1.3',
            'scoresByType' => [
                266 => [
                    'deviceTypeId' => 266,
                    'deviceTypeName' => 'Plug',
                    'score' => 80.0,
                    'starRating' => 4.0,
                    'isCompliant' => true,
                    'mandatoryScore' => 48.0,
                    'optionalScore' => 24.0,
                    'clientBonus' => 8.0,
                    'breakdown' => ['k' => 'v'],
                ],
            ],
        ];

        $score = DeviceScore::fromArray($data);

        $this->assertSame(80.0, $score->overallScore);
        $this->assertSame(4.0, $score->starRating);
        $this->assertTrue($score->isCompliant);
        $this->assertSame('1.3', $score->bestVersion);
        $this->assertArrayHasKey(266, $score->scoresByType);
        $this->assertSame(266, $score->scoresByType[266]->deviceTypeId);
    }

    public function testFromArrayHandlesMissingOptionalFields(): void
    {
        $score = DeviceScore::fromArray([
            'overallScore' => 0,
            'starRating' => 0,
            'isCompliant' => false,
        ]);

        $this->assertSame(0.0, $score->overallScore);
        $this->assertNull($score->bestVersion);
        $this->assertSame([], $score->scoresByType);
    }

    public function testToArrayRoundTripsWithFromArray(): void
    {
        $original = new DeviceScore(
            overallScore: 72.5,
            starRating: 3.5,
            isCompliant: true,
            scoresByType: [
                266 => $this->makeTypeScore(266, 'Plug', 72.5),
                269 => $this->makeTypeScore(269, 'Light', 80.0),
            ],
            bestVersion: '1.4',
        );

        $rebuilt = DeviceScore::fromArray($original->toArray());

        $this->assertEquals($original->overallScore, $rebuilt->overallScore);
        $this->assertEquals($original->starRating, $rebuilt->starRating);
        $this->assertEquals($original->isCompliant, $rebuilt->isCompliant);
        $this->assertEquals($original->bestVersion, $rebuilt->bestVersion);
        $this->assertCount(2, $rebuilt->scoresByType);
    }

    public function testGetBestTypeScoreReturnsHighestScoringType(): void
    {
        $score = new DeviceScore(
            overallScore: 0,
            starRating: 0,
            isCompliant: false,
            scoresByType: [
                266 => $this->makeTypeScore(266, 'Plug', 60.0),
                269 => $this->makeTypeScore(269, 'Light', 90.0),
                21 => $this->makeTypeScore(21, 'Sensor', 70.0),
            ],
        );

        $best = $score->getBestTypeScore();

        $this->assertNotNull($best);
        $this->assertSame(269, $best->deviceTypeId);
        $this->assertSame(90.0, $best->score);
    }

    public function testGetBestTypeScoreReturnsNullWhenEmpty(): void
    {
        $score = new DeviceScore(
            overallScore: 0,
            starRating: 0,
            isCompliant: false,
            scoresByType: [],
        );

        $this->assertNull($score->getBestTypeScore());
    }
}
