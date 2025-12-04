<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents the overall score for a device, with breakdown by device type.
 */
final class DeviceScore
{
    /**
     * @param array<int, DeviceTypeScore> $scoresByType Scores keyed by device type ID
     */
    public function __construct(
        public readonly float $overallScore,
        public readonly float $starRating,
        public readonly bool $isCompliant,
        public readonly array $scoresByType,
        public readonly ?string $bestVersion = null,
    ) {
    }

    /**
     * Create from array (e.g., from database cache).
     */
    public static function fromArray(array $data): self
    {
        $scoresByType = [];
        foreach ($data['scoresByType'] ?? [] as $typeId => $typeData) {
            $scoresByType[$typeId] = DeviceTypeScore::fromArray($typeData);
        }

        return new self(
            overallScore: (float) $data['overallScore'],
            starRating: (float) $data['starRating'],
            isCompliant: (bool) $data['isCompliant'],
            scoresByType: $scoresByType,
            bestVersion: $data['bestVersion'] ?? null,
        );
    }

    /**
     * Convert to array for caching/serialization.
     */
    public function toArray(): array
    {
        $scoresByType = [];
        foreach ($this->scoresByType as $typeId => $typeScore) {
            $scoresByType[$typeId] = $typeScore->toArray();
        }

        return [
            'overallScore' => $this->overallScore,
            'starRating' => $this->starRating,
            'isCompliant' => $this->isCompliant,
            'scoresByType' => $scoresByType,
            'bestVersion' => $this->bestVersion,
        ];
    }

    /**
     * Get the best device type score (highest scoring).
     */
    public function getBestTypeScore(): ?DeviceTypeScore
    {
        if (empty($this->scoresByType)) {
            return null;
        }

        $best = null;
        foreach ($this->scoresByType as $typeScore) {
            if (null === $best || $typeScore->score > $best->score) {
                $best = $typeScore;
            }
        }

        return $best;
    }
}
