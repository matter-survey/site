<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Cluster;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Load clusters from the Matter spec YAML file.
 */
class ClusterFixtures extends Fixture implements FixtureGroupInterface
{
    private string $dataPath;

    public function __construct(?string $dataPath = null)
    {
        $this->dataPath = $dataPath ?? __DIR__ . '/../../fixtures/clusters.yaml';
    }

    public static function getGroups(): array
    {
        return ['clusters', 'matter', 'test'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!file_exists($this->dataPath)) {
            throw new \RuntimeException(\sprintf('Clusters YAML file not found at: %s', $this->dataPath));
        }

        $clusters = Yaml::parseFile($this->dataPath);
        $repository = $manager->getRepository(Cluster::class);

        foreach ($clusters as $data) {
            $id = (int) $data['id'];

            // Find existing or create new
            $cluster = $repository->find($id);
            if ($cluster === null) {
                $cluster = new Cluster($id);
            }

            $cluster->setHexId($data['hexId'] ?? \sprintf('0x%04X', $id));
            $cluster->setName($data['name']);
            $cluster->setDescription($data['description'] ?? null);
            $cluster->setSpecVersion($data['specVersion'] ?? null);
            $cluster->setCategory($data['category'] ?? null);
            $cluster->setIsGlobal($data['isGlobal'] ?? false);

            // Load ZAP spec data (attributes, commands, features)
            $cluster->setAttributes($data['attributes'] ?? null);
            $cluster->setCommands($data['commands'] ?? null);
            $cluster->setFeatures($data['features'] ?? null);

            $cluster->setUpdatedAt(new \DateTime());

            $manager->persist($cluster);

            // Add reference for potential use in other fixtures
            $this->addReference('cluster-' . $id, $cluster);
        }

        $manager->flush();
    }
}
