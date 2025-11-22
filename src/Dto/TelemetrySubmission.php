<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TelemetrySubmission
{
    #[Assert\NotBlank(message: 'Missing installation_id')]
    #[Assert\Uuid(message: 'Invalid installation_id format')]
    public ?string $installation_id = null;

    /**
     * @var TelemetryDevice[]
     */
    #[Assert\NotNull(message: 'Missing devices array')]
    #[Assert\Type('array', message: 'devices must be an array')]
    #[Assert\Valid]
    public ?array $devices = null;
}
