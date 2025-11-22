<?php

declare(strict_types=1);

namespace App\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

/**
 * Main fixture orchestrator - depends on all other fixtures.
 */
class AppFixtures extends Fixture implements DependentFixtureInterface
{
    public function load(ObjectManager $manager): void
    {
        // All fixtures are loaded via dependencies
        // This class just ensures proper ordering
    }

    public function getDependencies(): array
    {
        return [
            VendorFixtures::class,
            DeviceFixtures::class,
        ];
    }
}
