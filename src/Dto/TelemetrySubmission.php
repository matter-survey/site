<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TelemetrySubmission
{
    #[Assert\NotBlank(message: 'Missing installation_id')]
    #[Assert\Type('string', message: 'Invalid installation_id format')]
    #[Assert\Uuid(message: 'Invalid installation_id format')]
    public mixed $installation_id = null;

    /**
     * TelemetryDevice for each well-formed entry, the raw decoded value otherwise.
     */
    #[Assert\NotNull(message: 'Missing devices array')]
    #[Assert\Type('array', message: 'devices must be an array')]
    #[Assert\All([new Assert\Type(TelemetryDevice::class, message: 'Each device must be an object')])]
    public mixed $devices = null;

    /**
     * Cascades validation into the well-formed devices only. Valid can't sit on
     * $devices itself: on a scalar it throws instead of reporting a violation.
     *
     * @return list<TelemetryDevice>
     */
    #[Assert\Valid]
    public function getDeviceObjects(): array
    {
        if (!is_array($this->devices)) {
            return [];
        }

        return array_values(array_filter($this->devices, static fn (mixed $d): bool => $d instanceof TelemetryDevice));
    }
}
