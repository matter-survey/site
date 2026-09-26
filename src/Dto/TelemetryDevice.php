<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Properties are untyped (mixed) on purpose: raw decoded JSON is assigned as-is
 * so that type mismatches surface as validation errors (400) instead of
 * TypeErrors (500).
 */
class TelemetryDevice
{
    #[Assert\Type('integer', message: 'vendor_id must be an integer')]
    public mixed $vendor_id = null;

    #[Assert\Type('string', message: 'vendor_name must be a string')]
    #[Assert\Length(max: 255)]
    public mixed $vendor_name = null;

    #[Assert\Type('integer', message: 'product_id must be an integer')]
    public mixed $product_id = null;

    #[Assert\Type('string', message: 'product_name must be a string')]
    #[Assert\Length(max: 255)]
    public mixed $product_name = null;

    #[Assert\Type('string', message: 'hardware_version must be a string')]
    #[Assert\Length(max: 255)]
    public mixed $hardware_version = null;

    #[Assert\Type('string', message: 'software_version must be a string')]
    #[Assert\Length(max: 255)]
    public mixed $software_version = null;

    #[Assert\Type('array', message: 'endpoints must be an array')]
    #[Assert\All([new Assert\Type('array', message: 'Each endpoint must be an object')])]
    public mixed $endpoints = null;

    /**
     * Endpoint structure check. Malformed endpoint data that got stored used to
     * break the public device page on render, so reject it at the door.
     *
     * IDs may be ints or digit strings (the service casts them); cluster and
     * device-type entries may be bare IDs (v2) or objects with an "id" (v3).
     */
    #[Assert\Callback]
    public function validateEndpoints(ExecutionContextInterface $context): void
    {
        if (!\is_array($this->endpoints)) {
            return;
        }

        foreach ($this->endpoints as $i => $endpoint) {
            if (!\is_array($endpoint)) {
                continue; // Reported by the All/Type constraint on $endpoints.
            }

            $path = \sprintf('endpoints[%s]', $i);
            $fail = static function (string $message) use ($context, $path): void {
                $context->buildViolation($message)->atPath($path)->addViolation();
            };

            if (isset($endpoint['endpoint_id']) && !$this->isId($endpoint['endpoint_id'])) {
                $fail('endpoint_id must be an integer');
            }

            foreach (['device_types', 'server_clusters', 'client_clusters'] as $key) {
                if (!isset($endpoint[$key])) {
                    continue;
                }
                if (!\is_array($endpoint[$key]) || !array_is_list($endpoint[$key])) {
                    $fail(\sprintf('%s must be an array', $key));
                    continue;
                }
                foreach ($endpoint[$key] as $entry) {
                    if (!$this->isId(\is_array($entry) ? ($entry['id'] ?? null) : $entry)) {
                        $fail(\sprintf('%s entries must be integer IDs or objects with an integer id', $key));
                        break;
                    }
                    if (\is_array($entry) && null !== $error = $this->clusterDetailError($entry)) {
                        $fail(\sprintf('%s: %s', $key, $error));
                        break;
                    }
                }
            }
        }
    }

    private function isId(mixed $value): bool
    {
        return (\is_int($value) && $value >= 0) || (\is_string($value) && ctype_digit($value));
    }

    /**
     * @param array<mixed> $entry
     */
    private function clusterDetailError(array $entry): ?string
    {
        if (isset($entry['feature_map']) && !\is_int($entry['feature_map'])) {
            return 'feature_map must be an integer';
        }

        foreach (['accepted_command_list', 'generated_command_list', 'attribute_list'] as $list) {
            if (!isset($entry[$list])) {
                continue;
            }
            if (!\is_array($entry[$list]) || [] !== array_filter($entry[$list], static fn (mixed $v): bool => !\is_int($v))) {
                return \sprintf('%s must be an array of integers', $list);
            }
        }

        return null;
    }
}
