<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

class TelemetryDevice
{
    #[Assert\Type('integer')]
    public ?int $vendor_id = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $vendor_name = null;

    #[Assert\Type('integer')]
    public ?int $product_id = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $product_name = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $hardware_version = null;

    #[Assert\Type('string')]
    #[Assert\Length(max: 255)]
    public ?string $software_version = null;

    #[Assert\Type('array')]
    public ?array $endpoints = null;
}
