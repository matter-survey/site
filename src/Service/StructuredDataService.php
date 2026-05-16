<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Cluster;
use App\Entity\DeviceType;
use App\Entity\Product;
use App\Entity\Vendor;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Single source of truth for the site's schema.org JSON-LD payloads.
 *
 * Each entity-page template and aggregate stats template invokes one method
 * here via the matching Twig function (see StructuredDataExtension). Methods
 * return plain associative arrays; the template `json_encode`s them and
 * wraps them in a `<script type="application/ld+json">` block.
 *
 * The `description` field for entity payloads is always the AeoLedeService
 * lede so the visible lede and JSON-LD description agree byte-for-byte.
 */
final readonly class StructuredDataService
{
    public const string LICENSE_CC0 = 'https://creativecommons.org/publicdomain/zero/1.0/';

    public function __construct(
        private AeoLedeService $lede,
        private UrlGeneratorInterface $urlGenerator,
        private string $canonicalBaseUrl,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceJsonLd(Product $product, int $endpointCount, ?\DateTimeInterface $dateModified): array
    {
        $name = trim(($product->getVendorName() ?? '').' '.($product->getProductName() ?? ''));
        $name = '' !== $name ? $name : 'Unknown Matter Device';

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $name,
            'description' => $this->lede->ledeForDevice($product, $endpointCount),
            'productID' => sprintf('%d:%d', $product->getVendorId(), $product->getProductId()),
            'sku' => sprintf('%d-%d', $product->getVendorId(), $product->getProductId()),
            'category' => 'Smart Home Device',
            'manufacturer' => [
                '@type' => 'Organization',
                'name' => $product->getVendorName() ?? 'Unknown Vendor',
            ],
            'additionalProperty' => [
                ['@type' => 'PropertyValue', 'name' => 'Vendor ID', 'value' => (string) $product->getVendorId()],
                ['@type' => 'PropertyValue', 'name' => 'Product ID', 'value' => (string) $product->getProductId()],
                ['@type' => 'PropertyValue', 'name' => 'Endpoint Count', 'value' => (string) $endpointCount],
            ],
        ];

        if ($dateModified instanceof \DateTimeInterface) {
            $json['dateModified'] = $dateModified->format('Y-m-d');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function vendorJsonLd(Vendor $vendor, int $productCount, ?\DateTimeInterface $dateModified): array
    {
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $vendor->getName(),
            'description' => $this->lede->ledeForVendor($vendor, $productCount),
            'url' => $this->urlGenerator->generate(
                'vendor_show',
                ['slug' => $vendor->getSlug()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'additionalProperty' => [
                ['@type' => 'PropertyValue', 'name' => 'Matter Vendor ID', 'value' => (string) ($vendor->getSpecId() ?? 'N/A')],
                ['@type' => 'PropertyValue', 'name' => 'Certified Products', 'value' => (string) $productCount],
            ],
        ];

        $landingPage = $vendor->getVendorLandingPageURL();
        if (null !== $landingPage && '' !== $landingPage && false !== filter_var($landingPage, FILTER_VALIDATE_URL)) {
            $json['sameAs'] = [$landingPage];
        }

        if ($dateModified instanceof \DateTimeInterface) {
            $json['dateModified'] = $dateModified->format('Y-m-d');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function clusterJsonLd(
        Cluster $cluster,
        int $totalDevices,
        int $mandatoryForCount,
        ?\DateTimeInterface $dateModified,
        int $commandCount = 0,
        int $attributeCount = 0,
    ): array {
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            'name' => $cluster->getName(),
            'description' => $this->lede->ledeForCluster($cluster, $mandatoryForCount, $commandCount, $attributeCount),
            'termCode' => $cluster->getHexId(),
            'url' => $this->urlGenerator->generate(
                'stats_cluster_show',
                ['hexId' => $cluster->getHexId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name' => 'Matter Clusters',
                'description' => 'Clusters defined in the Matter smart home specification',
            ],
            'additionalProperty' => [
                ['@type' => 'PropertyValue', 'name' => 'Cluster ID', 'value' => (string) $cluster->getId()],
                ['@type' => 'PropertyValue', 'name' => 'Hex ID', 'value' => $cluster->getHexId()],
                ['@type' => 'PropertyValue', 'name' => 'Category', 'value' => $cluster->getCategory() ?? 'Unknown'],
                ['@type' => 'PropertyValue', 'name' => 'Type', 'value' => $cluster->isGlobal() ? 'Global' : 'Application'],
                ['@type' => 'PropertyValue', 'name' => 'Devices Implementing', 'value' => (string) $totalDevices],
            ],
        ];

        if ($dateModified instanceof \DateTimeInterface) {
            $json['dateModified'] = $dateModified->format('Y-m-d');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function deviceTypeJsonLd(
        DeviceType $deviceType,
        int $totalDevices,
        ?\DateTimeInterface $dateModified,
    ): array {
        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'DefinedTerm',
            'name' => $deviceType->getName(),
            'description' => $this->lede->ledeForDeviceType($deviceType, $totalDevices),
            'termCode' => $deviceType->getHexId(),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name' => 'Matter Device Types',
                'description' => 'Device types defined in the Matter smart home specification',
            ],
            'additionalProperty' => [
                ['@type' => 'PropertyValue', 'name' => 'Device Type ID', 'value' => (string) $deviceType->getId()],
                ['@type' => 'PropertyValue', 'name' => 'Hex ID', 'value' => $deviceType->getHexId()],
                ['@type' => 'PropertyValue', 'name' => 'Category', 'value' => $deviceType->getDisplayCategory() ?? 'Unknown'],
                ['@type' => 'PropertyValue', 'name' => 'Devices Implementing', 'value' => (string) $totalDevices],
            ],
        ];

        if ($dateModified instanceof \DateTimeInterface) {
            $json['dateModified'] = $dateModified->format('Y-m-d');
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    public function datasetJsonLd(
        string $name,
        string $description,
        \DateTimeInterface $dateModified,
        \DateTimeInterface $coverageStart,
        ?\DateTimeInterface $coverageEnd = null,
    ): array {
        $end = $coverageEnd instanceof \DateTimeInterface ? $coverageEnd->format('Y-m-d') : '..';

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Dataset',
            'name' => $name,
            'description' => $description,
            'creator' => [
                '@type' => 'Organization',
                'name' => 'Matter Survey',
                'url' => $this->canonicalBaseUrl,
            ],
            'license' => self::LICENSE_CC0,
            'temporalCoverage' => sprintf('%s/%s', $coverageStart->format('Y-m-d'), $end),
            'dateModified' => $dateModified->format('Y-m-d'),
        ];
    }

    /**
     * @param list<array{name: string, url: string}> $crumbs
     *
     * @return array<string, mixed>
     */
    public function breadcrumbListJsonLd(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['name'],
                'item' => $crumb['url'],
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}
