<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Product;
use App\Entity\Vendor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Load products from the DCL YAML file.
 *
 * Uses batch processing to handle large datasets (~3500 products).
 */
class ProductFixtures extends Fixture implements FixtureGroupInterface, DependentFixtureInterface
{
    private const BATCH_SIZE = 100;

    private string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? __DIR__.'/../../fixtures/products.yaml';
    }

    public static function getGroups(): array
    {
        return ['products', 'dcl', 'matter'];
    }

    public function getDependencies(): array
    {
        return [VendorFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        if (!file_exists($this->dataPath)) {
            throw new \RuntimeException(\sprintf('Products YAML file not found at: %s', $this->dataPath));
        }

        $products = Yaml::parseFile($this->dataPath);
        $productRepository = $manager->getRepository(Product::class);

        // Pre-load vendor data into maps for efficient lookup
        $vendorNameMap = $this->buildVendorNameMap($manager);
        $vendorRepository = $manager->getRepository(Vendor::class);

        $count = 0;
        foreach ($products as $data) {
            $vendorId = (int) $data['vendorId'];
            $productId = (int) $data['productId'];

            // Find existing by vendorId + productId or create new
            $product = $productRepository->findOneBy([
                'vendorId' => $vendorId,
                'productId' => $productId,
            ]);

            if (null === $product) {
                $product = new Product();
                $product->setVendorId($vendorId);
                $product->setProductId($productId);
            }

            // Use productLabel if available and meaningful, fallback to productName
            $productLabel = $data['productLabel'] ?? null;
            $productName = (!empty($productLabel) && '-' !== $productLabel)
                ? $productLabel
                : ($data['productName'] ?? null);
            $product->setProductName($productName);

            // Set vendor name from our map
            if (isset($vendorNameMap[$vendorId])) {
                $product->setVendorName($vendorNameMap[$vendorId]);
            }

            // Set vendor relationship - find vendor by specId
            $vendor = $vendorRepository->findOneBy(['specId' => $vendorId]);
            if (null !== $vendor) {
                $product->setVendor($vendor);
            }

            // Set additional DCL fields
            if (isset($data['deviceTypeId'])) {
                $product->setDeviceTypeId((int) $data['deviceTypeId']);
            }

            // Normalize '-' placeholder to null for part number
            $partNumber = $data['partNumber'] ?? null;
            $product->setPartNumber('-' === $partNumber ? null : $partNumber);

            // Set URL fields (trim whitespace, convert empty strings to null)
            $product->setProductUrl(trim($data['productUrl'] ?? '') ?: null);
            $product->setSupportUrl(trim($data['supportUrl'] ?? '') ?: null);
            $product->setUserManualUrl(trim($data['userManualUrl'] ?? '') ?: null);
            $product->setCommissioningCustomFlowUrl(trim($data['commissioningCustomFlowUrl'] ?? '') ?: null);
            $product->setMaintenanceUrl(trim($data['maintenanceUrl'] ?? '') ?: null);

            // Set discovery and commissioning fields
            if (isset($data['discoveryCapabilitiesBitmask'])) {
                $product->setDiscoveryCapabilitiesBitmask((int) $data['discoveryCapabilitiesBitmask']);
            }
            if (isset($data['commissioningCustomFlow'])) {
                $product->setCommissioningCustomFlow((int) $data['commissioningCustomFlow']);
            }
            if (isset($data['commissioningModeInitialStepsHint'])) {
                $product->setCommissioningInitialStepsHint((int) $data['commissioningModeInitialStepsHint']);
            }
            $product->setCommissioningInitialStepsInstruction(
                trim($data['commissioningModeInitialStepsInstruction'] ?? '') ?: null
            );
            if (isset($data['commissioningModeSecondaryStepsHint'])) {
                $product->setCommissioningSecondaryStepsHint((int) $data['commissioningModeSecondaryStepsHint']);
            }
            $product->setCommissioningSecondaryStepsInstruction(
                trim($data['commissioningModeSecondaryStepsInstruction'] ?? '') ?: null
            );
            $product->setCommissioningFallbackUrl(trim($data['commissioningFallbackUrl'] ?? '') ?: null);

            // Set factory reset fields
            if (isset($data['factoryResetStepsHint'])) {
                $product->setFactoryResetStepsHint((int) $data['factoryResetStepsHint']);
            }
            $product->setFactoryResetStepsInstruction(
                trim($data['factoryResetStepsInstruction'] ?? '') ?: null
            );

            // Set ICD (Intermittently Connected Device) fields
            if (isset($data['icdUserActiveModeTriggerHint'])) {
                $product->setIcdUserActiveModeTriggerHint((int) $data['icdUserActiveModeTriggerHint']);
            }
            $product->setIcdUserActiveModeTriggerInstruction(
                trim($data['icdUserActiveModeTriggerInstruction'] ?? '') ?: null
            );

            // Set LSF (Label/Setup File) fields
            $product->setLsfUrl(trim($data['lsfUrl'] ?? '') ?: null);
            if (isset($data['lsfRevision'])) {
                $product->setLsfRevision((int) $data['lsfRevision']);
            }

            // Set certified software versions from DCL
            if (isset($data['certifiedSoftwareVersions']) && \is_array($data['certifiedSoftwareVersions'])) {
                $product->setCertifiedSoftwareVersions($data['certifiedSoftwareVersions']);
            }

            // Set certification info from compliance-info endpoint
            if (!empty($data['certificationDate'])) {
                try {
                    $certDate = new \DateTime($data['certificationDate']);
                    $product->setCertificationDate($certDate);
                } catch (\Exception) {
                    // Invalid date format, skip
                }
            }
            if (!empty($data['certificateId'])) {
                $product->setCertificateId($data['certificateId']);
            }
            if (!empty($data['softwareVersionString'])) {
                $product->setSoftwareVersionString($data['softwareVersionString']);
            }

            $manager->persist($product);
            ++$count;

            // Batch flush to manage memory
            if (0 === $count % self::BATCH_SIZE) {
                $manager->flush();
                $manager->clear(Product::class);
            }
        }

        // Final flush for remaining items
        $manager->flush();
    }

    /**
     * Build a map of specId => vendorName for efficient lookup.
     *
     * @return array<int, string>
     */
    private function buildVendorNameMap(ObjectManager $manager): array
    {
        $vendors = $manager->getRepository(Vendor::class)->findAll();
        $map = [];

        foreach ($vendors as $vendor) {
            if (null !== $vendor->getSpecId()) {
                $map[$vendor->getSpecId()] = $vendor->getName();
            }
        }

        return $map;
    }
}
