<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Represents the score breakdown for a single device type.
 */
final class DeviceTypeScore
{
    public function __construct(
        public readonly int $deviceTypeId,
        public readonly string $deviceTypeName,
        public readonly float $score,
        public readonly int $starRating,
        public readonly bool $isCompliant,
        public readonly float $mandatoryScore,
        public readonly float $optionalScore,
        public readonly float $clientBonus,
        public readonly array $breakdown,
    ) {
    }

    /**
     * Create from array (e.g., from cache).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            deviceTypeId: (int) $data['deviceTypeId'],
            deviceTypeName: (string) $data['deviceTypeName'],
            score: (float) $data['score'],
            starRating: (int) $data['starRating'],
            isCompliant: (bool) $data['isCompliant'],
            mandatoryScore: (float) $data['mandatoryScore'],
            optionalScore: (float) $data['optionalScore'],
            clientBonus: (float) ($data['clientBonus'] ?? 0),
            breakdown: $data['breakdown'] ?? [],
        );
    }

    /**
     * Convert to array for caching/serialization.
     */
    public function toArray(): array
    {
        return [
            'deviceTypeId' => $this->deviceTypeId,
            'deviceTypeName' => $this->deviceTypeName,
            'score' => $this->score,
            'starRating' => $this->starRating,
            'isCompliant' => $this->isCompliant,
            'mandatoryScore' => $this->mandatoryScore,
            'optionalScore' => $this->optionalScore,
            'clientBonus' => $this->clientBonus,
            'breakdown' => $this->breakdown,
        ];
    }
}
