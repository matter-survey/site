<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Vendor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Load vendors from the DCL YAML file.
 *
 * Merge strategy: DCL is authoritative for metadata (name, slug, companyLegalName, vendorLandingPageURL).
 * User-derived stats (deviceCount) are preserved.
 */
class VendorFixtures extends Fixture implements FixtureGroupInterface
{
    private string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? __DIR__ . '/../../fixtures/vendors.yaml';
    }

    public static function getGroups(): array
    {
        return ['vendors', 'dcl', 'matter', 'test'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!file_exists($this->dataPath)) {
            throw new \RuntimeException(\sprintf('Vendors YAML file not found at: %s', $this->dataPath));
        }

        $vendors = Yaml::parseFile($this->dataPath);
        $repository = $manager->getRepository(Vendor::class);

        foreach ($vendors as $data) {
            $specId = (int) $data['specId'];

            // Find existing by specId (canonical key)
            $vendor = $repository->findOneBy(['specId' => $specId]);
            if ($vendor === null) {
                $vendor = new Vendor();
                $vendor->setSpecId($specId);
            }

            // DCL is authoritative for metadata - always overwrite
            $vendor->setName($data['name'] ?? '');
            $vendor->setSlug($data['slug']); // Slug now includes specId suffix, guaranteed unique
            $vendor->setCompanyLegalName($data['companyLegalName'] ?? null);
            $vendor->setVendorLandingPageURL($data['vendorLandingPageURL'] ?? null);
            $vendor->setUpdatedAt(new \DateTime());

            // Note: deviceCount is NOT reset - it's a user-derived stat that should be preserved

            $manager->persist($vendor);

            // Add reference for potential use in other fixtures
            $this->addReference('vendor-' . $specId, $vendor);
        }

        $manager->flush();
    }
}
