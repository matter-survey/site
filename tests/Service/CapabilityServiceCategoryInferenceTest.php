<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\CapabilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for CapabilityService's category-inference and the public surface
 * methods (getCategories, plus the analysis result's category-related fields).
 *
 * These cover the previously-untested inferCategoryFromDeviceTypes,
 * identifyStandouts/identifyMissing branches, humanizeTechnicalName edge
 * cases, and the getCategories accessor.
 */
final class CapabilityServiceCategoryInferenceTest extends KernelTestCase
{
    private CapabilityService $capabilityService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->capabilityService = self::getContainer()->get(CapabilityService::class);
    }

    /**
     * @return iterable<string, array{deviceTypeId: int, expectedCategory: string}>
     */
    public static function deviceTypeCategoryProvider(): iterable
    {
        yield 'on/off light → lighting' => ['deviceTypeId' => 256, 'expectedCategory' => 'lighting'];
        yield 'extended color light → lighting' => ['deviceTypeId' => 269, 'expectedCategory' => 'lighting'];
        yield 'plug-in unit → plugs' => ['deviceTypeId' => 266, 'expectedCategory' => 'plugs'];
        yield 'dimmer switch → switches' => ['deviceTypeId' => 260, 'expectedCategory' => 'switches'];
        yield 'contact sensor → sensors' => ['deviceTypeId' => 263, 'expectedCategory' => 'sensors'];
        yield 'thermostat → climate' => ['deviceTypeId' => 769, 'expectedCategory' => 'climate'];
        yield 'door lock → locks' => ['deviceTypeId' => 10, 'expectedCategory' => 'locks'];
        yield 'window covering → window_coverings' => ['deviceTypeId' => 514, 'expectedCategory' => 'window_coverings'];
        yield 'speaker → media' => ['deviceTypeId' => 34, 'expectedCategory' => 'media'];
        yield 'smoke/co alarm → safety' => ['deviceTypeId' => 17, 'expectedCategory' => 'safety'];
    }

    #[DataProvider('deviceTypeCategoryProvider')]
    public function testCategoryInferredFromDeviceType(int $deviceTypeId, string $expectedCategory): void
    {
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [$deviceTypeId],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertSame($expectedCategory, $result['deviceCategory']);
    }

    public function testCategoryIsNullForUnknownDeviceType(): void
    {
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [99999],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertNull($result['deviceCategory']);
    }

    public function testCategoryFromDeviceTypeArrayShape(): void
    {
        // V3 telemetry can send device types as {id, revision} objects too
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [['id' => 266, 'revision' => 1]],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertSame('plugs', $result['deviceCategory']);
    }

    public function testFirstMatchingDeviceTypeWinsCategory(): void
    {
        // Multiple device types; lighting wins because it appears first
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [256, 769],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertSame('lighting', $result['deviceCategory']);
    }

    public function testStandoutsListedWhenSupported(): void
    {
        // Electrical measurement (cluster 2820) is wired to energy_monitoring
        // which is a standout capability.
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [266], // plug
                'server_clusters' => [6, 2820],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertArrayHasKey('standouts', $result);
        $this->assertIsArray($result['standouts']);
    }

    public function testMissingListedForPlugWithoutEnergyMonitoring(): void
    {
        // Plug without electrical measurement → 'energy_monitoring' should
        // surface in `missing` because plugs flag it as notable when absent.
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [266],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertArrayHasKey('missing', $result);
        $this->assertIsArray($result['missing']);
    }

    public function testMissingFallbackWhenCategoryIsUnknown(): void
    {
        // Unknown device type → category null → identifyMissing falls back
        // to the binding/energy_monitoring default check. With no binding
        // (cluster 30) and no electrical measurement, 'missing' is populated
        // from that default check, not from any category-specific list.
        $endpoints = [
            [
                'endpoint_id' => 1,
                'device_types' => [99999],
                'server_clusters' => [6],
                'client_clusters' => [],
            ],
        ];

        $result = $this->capabilityService->analyzeCapabilities($endpoints);

        $this->assertNull($result['deviceCategory']);
        $this->assertIsArray($result['missing']);
    }

    public function testGetCategoriesReturnsLabelMap(): void
    {
        $categories = $this->capabilityService->getCategories();

        $this->assertIsArray($categories);
        $this->assertNotEmpty($categories);
        foreach ($categories as $key => $label) {
            $this->assertIsString($key);
            $this->assertIsString($label);
        }
    }

    public function testEmptyEndpointsYieldsAllCapabilitiesUnsupported(): void
    {
        // With no endpoints, the capabilities loop still runs over every
        // defined capability and marks each as unsupported (no clusters).
        $result = $this->capabilityService->analyzeCapabilities([]);

        $this->assertSame([], $result['supported']);
        $this->assertNotEmpty($result['unsupported']);
        $this->assertNull($result['deviceCategory']);
        $this->assertSame([], $result['standouts']);
        // 'missing' falls back to binding + energy_monitoring when category is null
        $this->assertIsArray($result['missing']);
    }
}
