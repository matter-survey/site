<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\AttributeAllowlist;
use App\Service\TelemetryService;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guardrail against PII leakage: every span listed in the allowlist must only
 * carry attributes from its allowlisted set. Adding a new attribute to a
 * domain span requires updating AttributeAllowlist, which prompts review.
 */
final class AttributeAllowlistTest extends KernelTestCase
{
    use InMemoryOtelTrait;

    protected function setUp(): void
    {
        $this->setUpOtel();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        $this->tearDownOtel();
        parent::tearDown();
    }

    public function testTelemetrySubmitSpanCarriesOnlyAllowlistedAttributes(): void
    {
        /** @var TelemetryService $service */
        $service = self::getContainer()->get(TelemetryService::class);

        $payload = [
            'installation_id' => '550e8400-e29b-41d4-a716-446655440099',
            'schema_version' => 3,
            'devices' => [
                [
                    'vendor_id' => 4660,
                    'product_id' => 22136,
                    'vendor_name' => 'TestVendor',
                    'product_name' => 'TestProduct',
                    'hardware_version' => '1.0',
                    'software_version' => '1.0.0',
                    'endpoints' => [
                        ['endpoint_id' => 1, 'device_types' => [256], 'server_clusters' => [6, 29], 'client_clusters' => []],
                    ],
                ],
            ],
        ];

        $service->processSubmission($payload, 'test-ip');

        $spans = $this->recordedSpans();
        $allowlist = AttributeAllowlist::map();

        foreach ($spans as $span) {
            $name = $span->getName();
            if (!isset($allowlist[$name])) {
                continue;
            }

            $attrs = array_keys($span->getAttributes()->toArray());
            foreach ($attrs as $attr) {
                $this->assertContains(
                    $attr,
                    $allowlist[$name],
                    sprintf(
                        'Span "%s" carries disallowed attribute "%s". Either remove it or add it to AttributeAllowlist after PII review.',
                        $name,
                        $attr,
                    ),
                );
            }
        }

        // Sanity: we must have observed at least one allowlisted span; otherwise
        // the test is vacuously true.
        $observedNames = array_unique(array_map(static fn (ImmutableSpan $s) => $s->getName(), $spans));
        $observedAllowlisted = array_intersect($observedNames, array_keys($allowlist));
        $this->assertNotEmpty($observedAllowlisted, 'No allowlisted span was observed');
    }
}
