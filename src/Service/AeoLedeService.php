<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Entity\Product;
use App\Entity\Vendor;

/**
 * Generates the agent-citable "lede" sentence rendered at the top of public
 * entity pages and emitted as JSON-LD `description`. Pure: no DB access, no
 * side effects. Callers fetch any counts the lede needs and pass them in.
 *
 * The visible lede and the JSON-LD `description` for the same entity MUST
 * agree byte-for-byte (spec contract). Both code paths go through this
 * service to guarantee that.
 */
final class AeoLedeService
{
    public function ledeForDevice(Product $product, int $endpointCount): string
    {
        $productName = $this->trimOrNull($product->getProductName());
        $vendorName = $this->trimOrNull($product->getVendorName());
        $vendorHex = sprintf('0x%04X', $product->getVendorId());
        $productHex = sprintf('0x%04X', $product->getProductId());

        $subject = match (true) {
            null !== $productName && null !== $vendorName => sprintf('The %s %s', $vendorName, $productName),
            null !== $productName => sprintf('The %s', $productName),
            null !== $vendorName => sprintf('The %s device', $vendorName),
            default => 'This Matter device',
        };

        $identifier = sprintf('(Vendor %s, Product %s)', $vendorHex, $productHex);

        if ($endpointCount > 0) {
            return sprintf(
                '%s is a Matter device %s with %d %s.',
                $subject,
                $identifier,
                $endpointCount,
                1 === $endpointCount ? 'endpoint' : 'endpoints',
            );
        }

        return sprintf('%s is a Matter device %s.', $subject, $identifier);
    }

    public function ledeForVendor(Vendor $vendor, int $productCount): string
    {
        $name = $vendor->getName();

        if ($productCount > 0) {
            return sprintf(
                '%s is a Matter device vendor with %d Matter-certified %s in the Matter Survey registry.',
                $name,
                $productCount,
                1 === $productCount ? 'product' : 'products',
            );
        }

        return sprintf('%s is a Matter device vendor in the Matter Survey registry.', $name);
    }

    public function ledeForCluster(Cluster $cluster, int $mandatoryForCount): string
    {
        $name = $cluster->getName();
        $hex = $cluster->getHexId();
        $description = $this->trimOrNull($cluster->getDescription());
        $commandCount = \count($cluster->getCommands() ?? []);
        $attributeCount = \count($cluster->getAttributes() ?? []);

        $primary = null !== $description
            ? sprintf('The %s cluster (%s) is a Matter cluster that %s.', $name, $hex, $description)
            : sprintf('The %s cluster (%s) is a Matter cluster.', $name, $hex);

        $clauses = [];
        if ($commandCount > 0 || $attributeCount > 0) {
            $parts = [];
            if ($commandCount > 0) {
                $parts[] = sprintf('%d %s', $commandCount, 1 === $commandCount ? 'command' : 'commands');
            }
            if ($attributeCount > 0) {
                $parts[] = sprintf('%d %s', $attributeCount, 1 === $attributeCount ? 'attribute' : 'attributes');
            }
            $clauses[] = 'defines '.$this->joinNaturally($parts);
        }
        if ($mandatoryForCount > 0) {
            $clauses[] = sprintf(
                'is mandatory for %d device %s',
                $mandatoryForCount,
                1 === $mandatoryForCount ? 'type' : 'types',
            );
        }

        if ([] === $clauses) {
            return $primary;
        }

        return sprintf('%s It %s.', $primary, $this->joinNaturally($clauses));
    }

    public function ledeForDeviceType(DeviceType $deviceType, int $totalDevices): string
    {
        $name = $deviceType->getName();
        $hex = $deviceType->getHexId();
        $description = $this->trimOrNull($deviceType->getDescription());
        $mandatoryCount = \count($deviceType->getMandatoryServerClusters());
        $optionalCount = \count($deviceType->getOptionalServerClusters());

        $primary = null !== $description
            ? sprintf('The %s (%s) is a Matter device type that %s.', $name, $hex, $description)
            : sprintf('The %s (%s) is a Matter device type.', $name, $hex);

        $clauses = [];
        if ($mandatoryCount > 0) {
            $clauses[] = sprintf(
                'requires %d mandatory server %s',
                $mandatoryCount,
                1 === $mandatoryCount ? 'cluster' : 'clusters',
            );
        }
        if ($optionalCount > 0) {
            $clauses[] = sprintf(
                'supports %d optional server %s',
                $optionalCount,
                1 === $optionalCount ? 'cluster' : 'clusters',
            );
        }
        if ($totalDevices > 0) {
            $clauses[] = sprintf(
                'is implemented by %d known %s',
                $totalDevices,
                1 === $totalDevices ? 'device' : 'devices',
            );
        }

        if ([] === $clauses) {
            return $primary;
        }

        return sprintf('%s It %s.', $primary, $this->joinNaturally($clauses));
    }

    private function trimOrNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    /**
     * Joins clauses with commas and a final "and".
     *
     * @param list<string> $parts
     */
    private function joinNaturally(array $parts): string
    {
        $count = \count($parts);
        if (0 === $count) {
            return '';
        }
        if (1 === $count) {
            return $parts[0];
        }
        if (2 === $count) {
            return $parts[0].' and '.$parts[1];
        }

        $last = array_pop($parts);

        return implode(', ', $parts).', and '.$last;
    }
}
