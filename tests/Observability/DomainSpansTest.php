<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\SDK\Metrics\Data\Metric;
use OpenTelemetry\SDK\Metrics\Data\Sum;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verifies that domain instrumentation produces the expected spans and
 * metrics when an end-to-end submission flows through TelemetryService.
 */
final class DomainSpansTest extends KernelTestCase
{
    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $spanStorage;
    private TracerProvider $tracerProvider;
    private MetricInMemoryExporter $metricExporter;
    private ExportingReader $metricReader;
    private MeterProviderInterface $meterProvider;

    /** @var string|false */
    private $originalDisabled;

    protected function setUp(): void
    {
        // The MeterProvider/TracerProvider short-circuit via Sdk::isDisabled()
        // when OTEL_SDK_DISABLED=true (the committed default). For test, force
        // it off so real instruments are emitted.
        $this->originalDisabled = getenv('OTEL_SDK_DISABLED');
        putenv('OTEL_SDK_DISABLED=false');
        $_ENV['OTEL_SDK_DISABLED'] = 'false';
        $_SERVER['OTEL_SDK_DISABLED'] = 'false';

        $this->spanStorage = new \ArrayObject();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor(new SpanInMemoryExporter($this->spanStorage)));

        $this->metricExporter = new MetricInMemoryExporter();
        $this->metricReader = new ExportingReader($this->metricExporter);
        $this->meterProvider = MeterProvider::builder()->addReader($this->metricReader)->build();

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->setMeterProvider($this->meterProvider)
            ->buildAndRegisterGlobal();

        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        $this->meterProvider->shutdown();
        Globals::reset();
        static::ensureKernelShutdown();

        if (false === $this->originalDisabled) {
            putenv('OTEL_SDK_DISABLED');
            unset($_ENV['OTEL_SDK_DISABLED'], $_SERVER['OTEL_SDK_DISABLED']);
        } else {
            putenv('OTEL_SDK_DISABLED='.$this->originalDisabled);
            $_ENV['OTEL_SDK_DISABLED'] = $this->originalDisabled;
            $_SERVER['OTEL_SDK_DISABLED'] = $this->originalDisabled;
        }

        parent::tearDown();
    }

    public function testTelemetrySubmissionEmitsSpanAndMetrics(): void
    {
        $service = self::getContainer()->get(\App\Service\TelemetryService::class);
        $this->assertNotNull($service);

        $payload = [
            'installation_id' => '550e8400-e29b-41d4-a716-446655440000',
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

        $result = $service->processSubmission($payload, 'test-ip');
        $this->assertTrue($result['success'] ?? false, 'Submission should succeed: '.json_encode($result));

        // Ensure Globals routes to our MeterProvider; if a kernel-boot scope
        // changed Globals, re-assert before flushing.
        $this->assertSame($this->meterProvider, Globals::meterProvider(), 'Global meter provider should be the test instance');

        $this->tracerProvider->forceFlush();
        $this->metricReader->collect();

        $spans = iterator_to_array($this->spanStorage->getIterator());

        $submitSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s) => 'telemetry.submit' === $s->getName()));
        $this->assertCount(1, $submitSpans);
        $attrs = $submitSpans[0]->getAttributes()->toArray();
        $this->assertSame(3, $attrs['submission.schema_version'] ?? null);
        $this->assertSame(1, $attrs['submission.endpoint_count'] ?? null);
        $this->assertSame(1, $attrs['submission.device_count'] ?? null);

        $scoreSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s) => 'score.calculate' === $s->getName()));
        $this->assertNotEmpty($scoreSpans, 'score.calculate span expected as part of a successful submission');

        $metrics = $this->getMetrics();

        $submissionsCounter = $metrics['submissions.total'] ?? null;
        $this->assertNotNull($submissionsCounter, 'submissions.total counter expected; got: '.implode(',', array_keys($metrics)));
        $this->assertInstanceOf(Sum::class, $submissionsCounter->data);

        $duration = $metrics['submissions.duration_ms'] ?? null;
        $this->assertNotNull($duration, 'submissions.duration_ms histogram expected');
    }

    /**
     * @return array<string, Metric>
     */
    private function getMetrics(): array
    {
        $by = [];
        foreach ($this->metricExporter->collect() as $metric) {
            $by[$metric->name] = $metric;
        }

        return $by;
    }
}
