<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\RegistryLookupTracing;
use App\Service\MatterRegistry;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies opt-in `matter_registry.lookup` span emission.
 *
 * The flag is read from env once per process and cached, so each test resets
 * via the InMemoryOtelTrait::setUpOtel hook, then sets the env to the value
 * needed for that scenario before booting the kernel and resolving the service.
 */
final class MatterRegistryTracingTest extends KernelTestCase
{
    use InMemoryOtelTrait;

    /** @var string|false */
    private string|bool $originalFlag;

    protected function setUp(): void
    {
        $this->originalFlag = getenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED');
        $this->setUpOtel();
    }

    protected function tearDown(): void
    {
        self::ensureKernelShutdown();
        $this->tearDownOtel();

        if (false === $this->originalFlag) {
            putenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED');
            unset($_ENV['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED'], $_SERVER['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED']);
        } else {
            putenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED='.$this->originalFlag);
            $_ENV['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED'] = $this->originalFlag;
            $_SERVER['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED'] = $this->originalFlag;
        }
        RegistryLookupTracing::reset();

        parent::tearDown();
    }

    public function testFlagOffEmitsNoLookupSpans(): void
    {
        $this->setFlag(false);
        self::bootKernel();
        /** @var MatterRegistry $registry */
        $registry = self::getContainer()->get(MatterRegistry::class);

        // Hit the registry many times across the wrapped getter surface.
        $registry->getClusterName(6);
        $registry->getClusterMetadata(6);
        $registry->getClusterDescription(6);
        $registry->getClusterCategory(6);
        $registry->getClusterHexId(6);
        $registry->getClusterCommandName(6, 0);
        $registry->getClusterAttributeName(6, 0);
        $registry->getDeviceTypeName(256);
        $registry->getDeviceTypeMetadata(256);
        $registry->getDeviceTypeDescription(256);
        $registry->getDeviceTypeCategory(256);
        $registry->getDeviceTypeHexId(256);

        $lookupSpans = $this->lookupSpans();
        $this->assertSame([], $lookupSpans, 'No matter_registry.lookup spans should be emitted when the flag is off.');
    }

    public function testFlagOnEmitsSpanWithExpectedAttributes(): void
    {
        $this->setFlag(true);
        self::bootKernel();
        /** @var MatterRegistry $registry */
        $registry = self::getContainer()->get(MatterRegistry::class);

        $registry->getClusterName(6);

        $spans = $this->lookupSpans();
        $this->assertCount(1, $spans, 'Exactly one matter_registry.lookup span per public call.');

        $attrs = $spans[0]->getAttributes()->toArray();
        $this->assertSame('cluster', $attrs['lookup.kind']);
        $this->assertSame('getClusterName', $attrs['lookup.method']);
        $this->assertSame('0x0006', $attrs['cluster.hex_id']);
        $this->assertTrue($attrs['lookup.cache_hit']);
    }

    public function testDeviceTypeLookupCarriesDeviceTypeHexId(): void
    {
        $this->setFlag(true);
        self::bootKernel();
        /** @var MatterRegistry $registry */
        $registry = self::getContainer()->get(MatterRegistry::class);

        $registry->getDeviceTypeName(256);

        $spans = $this->lookupSpans();
        $this->assertCount(1, $spans);

        $attrs = $spans[0]->getAttributes()->toArray();
        $this->assertSame('device_type', $attrs['lookup.kind']);
        $this->assertSame('getDeviceTypeName', $attrs['lookup.method']);
        $this->assertSame('0x0100', $attrs['device_type.hex_id']);
        $this->assertTrue($attrs['lookup.cache_hit']);
    }

    public function testUnknownIdMarksCacheHitFalse(): void
    {
        $this->setFlag(true);
        self::bootKernel();
        /** @var MatterRegistry $registry */
        $registry = self::getContainer()->get(MatterRegistry::class);

        $result = $registry->getClusterName(0xFFFE);
        $this->assertSame('Cluster 0xFFFE', $result, 'Unknown IDs return the fallback, not an exception.');

        $spans = $this->lookupSpans();
        $this->assertCount(1, $spans);
        $attrs = $spans[0]->getAttributes()->toArray();
        $this->assertFalse($attrs['lookup.cache_hit']);
    }

    public function testWrappedGetterCallingAnotherWrappedGetterDoesNotDoubleSpan(): void
    {
        $this->setFlag(true);
        self::bootKernel();
        /** @var MatterRegistry $registry */
        $registry = self::getContainer()->get(MatterRegistry::class);

        // getClusterDescription internally consults metadata. Only one span per public call.
        $registry->getClusterDescription(6);

        $spans = $this->lookupSpans();
        $this->assertCount(1, $spans, 'Internal helpers must not double-span.');
        $this->assertSame('getClusterDescription', $spans[0]->getAttributes()->toArray()['lookup.method']);
    }

    private function setFlag(bool $on): void
    {
        $value = $on ? 'true' : 'false';
        putenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED='.$value);
        $_ENV['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED'] = $value;
        $_SERVER['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED'] = $value;
        RegistryLookupTracing::reset();
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function lookupSpans(): array
    {
        return array_values(array_filter(
            $this->recordedSpans(),
            static fn (ImmutableSpan $span): bool => 'matter_registry.lookup' === $span->getName(),
        ));
    }
}
