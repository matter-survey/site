<?php

declare(strict_types=1);

namespace App\Twig;

class JsonDecodeExtension
{
    #[\Twig\Attribute\AsTwigFilter(name: 'json_decode')]
    public function jsonDecode(?string $json): ?array
    {
        if (null === $json || '' === $json) {
            return null;
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
