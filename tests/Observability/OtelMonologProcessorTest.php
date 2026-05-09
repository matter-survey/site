<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Monolog\OtelMonologProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OtelMonologProcessorTest extends TestCase
{
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter()));

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Globals::reset();
    }

    public function testLogOutsideSpanHasNoTraceContext(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello');
        $processed = (new OtelMonologProcessor())($record);

        $this->assertArrayNotHasKey('trace_id', $processed->extra);
        $this->assertArrayNotHasKey('span_id', $processed->extra);
    }

    public function testLogInsideSpanGetsTraceContext(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-op')->startSpan();
        $scope = $span->activate();

        try {
            $record = new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'hello');
            $processed = (new OtelMonologProcessor())($record);

            $this->assertSame($span->getContext()->getTraceId(), $processed->extra['trace_id'] ?? null);
            $this->assertSame($span->getContext()->getSpanId(), $processed->extra['span_id'] ?? null);
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    public function testProcessorPreservesExistingExtra(): void
    {
        $tracer = Globals::tracerProvider()->getTracer('test');
        $span = $tracer->spanBuilder('test-op')->startSpan();
        $scope = $span->activate();

        try {
            $record = new LogRecord(
                new \DateTimeImmutable(),
                'app',
                Level::Info,
                'hello',
                extra: ['existing' => 'value'],
            );
            $processed = (new OtelMonologProcessor())($record);

            $this->assertSame('value', $processed->extra['existing']);
            $this->assertArrayHasKey('trace_id', $processed->extra);
        } finally {
            $scope->detach();
            $span->end();
        }
    }
}
