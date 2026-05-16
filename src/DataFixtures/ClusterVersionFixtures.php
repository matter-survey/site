<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\ClusterVersion;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads per-Matter-version cluster snapshots from fixtures/clusters/*.yaml.
 *
 * Files are produced by `app:zap:backfill` and represent a frozen snapshot of
 * each Matter release. Upserts by (cluster_id, matter_version) so the loader
 * is safe to re-run with --append.
 */
final class ClusterVersionFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/clusters')]
        private readonly string $dataDir,
    ) {
    }

    public static function getGroups(): array
    {
        return ['cluster_versions', 'matter', 'test'];
    }

    public function load(ObjectManager $manager): void
    {
        if (!is_dir($this->dataDir)) {
            return;
        }

        $files = glob($this->dataDir.'/*.yaml');
        if (false === $files) {
            return;
        }

        $repository = $manager->getRepository(ClusterVersion::class);

        foreach ($files as $path) {
            $matterVersion = basename($path, '.yaml');
            $clusters = Yaml::parseFile($path);
            if (!\is_array($clusters)) {
                continue;
            }

            foreach ($clusters as $data) {
                $clusterId = (int) ($data['id'] ?? 0);
                if (0 === $clusterId) {
                    continue;
                }

                $version = $repository->find(['clusterId' => $clusterId, 'matterVersion' => $matterVersion])
                    ?? new ClusterVersion($clusterId, $matterVersion);

                $version->setName((string) ($data['name'] ?? ''));
                $version->setDescription($data['description'] ?? null);
                $version->setApiMaturity($data['apiMaturity'] ?? null);
                $version->setClusterRevision(isset($data['clusterRevision']) ? (int) $data['clusterRevision'] : null);
                $version->setAttributes($data['attributes'] ?? null);
                $version->setCommands($data['commands'] ?? null);
                $version->setFeatures($data['features'] ?? null);

                $manager->persist($version);
            }
        }

        $manager->flush();
    }
}
