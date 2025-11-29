<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\DeviceType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Load device types from the Matter spec YAML file.
 */
class DeviceTypeFixtures extends Fixture implements FixtureGroupInterface
{
    private string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? __DIR__.'/../../fixtures/device_types.yaml';
    }

    public static function getGroups(): array
    {
        return ['device_types', 'matter', 'test'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!file_exists($this->dataPath)) {
            throw new \RuntimeException(\sprintf('Device types YAML file not found at: %s', $this->dataPath));
        }

        $deviceTypes = Yaml::parseFile($this->dataPath);
        $repository = $manager->getRepository(DeviceType::class);

        foreach ($deviceTypes as $data) {
            $id = (int) $data['id'];

            // Find existing or create new
            $deviceType = $repository->find($id);
            if (null === $deviceType) {
                $deviceType = new DeviceType($id);
            }

            $deviceType->setHexId($data['hexId'] ?? \sprintf('0x%04X', $id));
            $deviceType->setName($data['name']);
            $deviceType->setDescription($data['description'] ?? null);
            $deviceType->setSpecVersion($data['specVersion'] ?? null);
            $deviceType->setCategory($data['category'] ?? null);
            $deviceType->setDisplayCategory($data['displayCategory'] ?? null);
            $deviceType->setDeviceClass($data['class'] ?? null);
            $deviceType->setScope($data['scope'] ?? null);
            $deviceType->setSuperset($data['superset'] ?? null);
            $deviceType->setIcon($data['icon'] ?? 'device');

            // Set cluster data
            $deviceType->setMandatoryServerClusters($data['mandatoryServerClusters'] ?? []);
            $deviceType->setOptionalServerClusters($data['optionalServerClusters'] ?? []);
            $deviceType->setMandatoryClientClusters($data['mandatoryClientClusters'] ?? []);
            $deviceType->setOptionalClientClusters($data['optionalClientClusters'] ?? []);

            $deviceType->setUpdatedAt(new \DateTime());

            $manager->persist($deviceType);

            // Add reference for potential use in other fixtures
            $this->addReference('device-type-'.$id, $deviceType);
        }

        $manager->flush();
    }
}
