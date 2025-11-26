<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Vendor;
use App\Repository\DeviceRepository;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;

class DeviceFixtures extends Fixture implements DependentFixtureInterface
{
    public const DEVICE_APPLE_HOMEPOD = 'device-apple-homepod';
    public const DEVICE_APPLE_TV = 'device-apple-tv';
    public const DEVICE_EVE_MOTION = 'device-eve-motion';
    public const DEVICE_EVE_ENERGY = 'device-eve-energy';
    public const DEVICE_EVE_DOOR = 'device-eve-door';
    public const DEVICE_PHILIPS_BULB = 'device-philips-bulb';
    public const DEVICE_PHILIPS_BRIDGE = 'device-philips-bridge';
    public const DEVICE_NANOLEAF_SHAPES = 'device-nanoleaf-shapes';

    public function __construct(
        private DeviceRepository $deviceRepository,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $devices = $this->getDeviceData();

        foreach ($devices as $data) {
            /** @var Vendor $vendor */
            $vendor = $this->getReference($data['vendorRef'], Vendor::class);

            $isNew = false;
            $deviceId = $this->deviceRepository->upsertDevice([
                'vendor_id' => $vendor->getSpecId(),
                'vendor_name' => $vendor->getName(),
                'vendor_fk' => $vendor->getId(),
                'product_id' => $data['productId'],
                'product_name' => $data['productName'],
            ], $isNew);

            $hardwareVersion = $data['hardwareVersion'] ?? null;
            $softwareVersion = $data['softwareVersion'] ?? null;

            // Add version info
            $this->deviceRepository->upsertVersion($deviceId, $hardwareVersion, $softwareVersion);

            // Add endpoints with version tracking
            foreach ($data['endpoints'] ?? [] as $endpoint) {
                $this->deviceRepository->upsertEndpoint($deviceId, $endpoint, $hardwareVersion, $softwareVersion);
            }

        }
    }

    public function getDependencies(): array
    {
        return [
            VendorFixtures::class,
        ];
    }

    private function getDeviceData(): array
    {
        return [
            // Apple devices (specId: 4937)
            [
                'vendorRef' => 'vendor-4937',
                'productId' => 1,
                'productName' => 'HomePod mini',
                'hardwareVersion' => '1.0',
                'softwareVersion' => '17.1',
                'reference' => self::DEVICE_APPLE_HOMEPOD,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 34, 'revision' => 1]], // Speaker
                        'server_clusters' => [6, 8, 29], // OnOff, LevelControl, Descriptor
                        'client_clusters' => [],
                    ],
                ],
            ],
            [
                'vendorRef' => 'vendor-4937',
                'productId' => 2,
                'productName' => 'Apple TV 4K',
                'hardwareVersion' => '2.0',
                'softwareVersion' => '17.2',
                'reference' => self::DEVICE_APPLE_TV,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 35, 'revision' => 1]], // Casting Video Player
                        'server_clusters' => [29, 1283], // Descriptor, MediaPlayback
                        'client_clusters' => [],
                    ],
                ],
            ],
            // Eve devices (specId: 4874)
            [
                'vendorRef' => 'vendor-4874',
                'productId' => 100,
                'productName' => 'Eve Motion',
                'hardwareVersion' => '1.0',
                'softwareVersion' => '2.1.0',
                'reference' => self::DEVICE_EVE_MOTION,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 263, 'revision' => 1]], // Occupancy Sensor
                        'server_clusters' => [29, 30, 1030], // Descriptor, Binding, OccupancySensing
                        'client_clusters' => [6], // OnOff client - can control lights via binding
                    ],
                ],
            ],
            [
                'vendorRef' => 'vendor-4874',
                'productId' => 101,
                'productName' => 'Eve Energy',
                'hardwareVersion' => '2.0',
                'softwareVersion' => '3.0.1',
                'reference' => self::DEVICE_EVE_ENERGY,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 266, 'revision' => 1]], // Outlet
                        'server_clusters' => [6, 29, 30, 1794], // OnOff, Descriptor, Binding, ElectricalMeasurement
                        'client_clusters' => [],
                    ],
                ],
            ],
            [
                'vendorRef' => 'vendor-4874',
                'productId' => 102,
                'productName' => 'Eve Door & Window',
                'hardwareVersion' => '1.5',
                'softwareVersion' => '2.2.0',
                'reference' => self::DEVICE_EVE_DOOR,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 21, 'revision' => 1]], // Contact Sensor
                        'server_clusters' => [29, 69], // Descriptor, BooleanState
                        'client_clusters' => [],
                    ],
                ],
            ],
            // Philips devices (specId: 4107)
            [
                'vendorRef' => 'vendor-4107',
                'productId' => 200,
                'productName' => 'Hue White and Color Ambiance',
                'hardwareVersion' => '1.0',
                'softwareVersion' => '1.93.11',
                'reference' => self::DEVICE_PHILIPS_BULB,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 269, 'revision' => 1]], // Extended Color Light
                        'server_clusters' => [6, 8, 29, 30, 768], // OnOff, LevelControl, Descriptor, Binding, ColorControl
                        'client_clusters' => [],
                    ],
                ],
            ],
            [
                'vendorRef' => 'vendor-4107',
                'productId' => 201,
                'productName' => 'Hue Bridge',
                'hardwareVersion' => '3.0',
                'softwareVersion' => '1.56.0',
                'reference' => self::DEVICE_PHILIPS_BRIDGE,
                'endpoints' => [
                    [
                        'endpoint_id' => 0,
                        'device_types' => [['id' => 22, 'revision' => 1]], // Root Node
                        'server_clusters' => [29, 31, 40], // Descriptor, AccessControl, BasicInformation
                        'client_clusters' => [],
                    ],
                ],
            ],
            // Nanoleaf devices (specId: 4442)
            [
                'vendorRef' => 'vendor-4442',
                'productId' => 300,
                'productName' => 'Shapes Hexagons',
                'hardwareVersion' => '1.0',
                'softwareVersion' => '8.5.2',
                'reference' => self::DEVICE_NANOLEAF_SHAPES,
                'endpoints' => [
                    [
                        'endpoint_id' => 1,
                        'device_types' => [['id' => 269, 'revision' => 1]], // Extended Color Light
                        'server_clusters' => [6, 8, 29, 30, 768], // OnOff, LevelControl, Descriptor, Binding, ColorControl
                        'client_clusters' => [],
                    ],
                ],
            ],
        ];
    }
}
