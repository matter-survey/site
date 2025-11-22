<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Vendor;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class VendorFixtures extends Fixture
{
    public const VENDOR_APPLE = 'vendor-apple';
    public const VENDOR_EVE = 'vendor-eve';
    public const VENDOR_PHILIPS = 'vendor-philips';
    public const VENDOR_NANOLEAF = 'vendor-nanoleaf';

    public function load(ObjectManager $manager): void
    {
        $vendors = [
            [
                'name' => 'Apple',
                'slug' => 'apple',
                'specId' => 4937,
                'deviceCount' => 2,
                'reference' => self::VENDOR_APPLE,
            ],
            [
                'name' => 'Eve Systems',
                'slug' => 'eve-systems',
                'specId' => 4874,
                'deviceCount' => 3,
                'reference' => self::VENDOR_EVE,
            ],
            [
                'name' => 'Philips Hue',
                'slug' => 'philips-hue',
                'specId' => 4107,
                'deviceCount' => 2,
                'reference' => self::VENDOR_PHILIPS,
            ],
            [
                'name' => 'Nanoleaf',
                'slug' => 'nanoleaf',
                'specId' => 4123,
                'deviceCount' => 1,
                'reference' => self::VENDOR_NANOLEAF,
            ],
        ];

        foreach ($vendors as $data) {
            $vendor = new Vendor();
            $vendor->setName($data['name']);
            $vendor->setSlug($data['slug']);
            $vendor->setSpecId($data['specId']);
            $vendor->setDeviceCount($data['deviceCount']);

            $manager->persist($vendor);
            $this->addReference($data['reference'], $vendor);
        }

        $manager->flush();
    }
}
