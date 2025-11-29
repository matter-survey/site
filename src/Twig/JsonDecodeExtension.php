<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class JsonDecodeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('json_decode', [$this, 'jsonDecode']),
        ];
    }

    public function jsonDecode(?string $json): ?array
    {
        if (null === $json || '' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
