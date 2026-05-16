<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\RegistryLookupTracing;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogsInMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Metrics\MeterProvider;
use OpenTelemetry\SDK\Metrics\MeterProviderInterface;
use OpenTelemetry\SDK\Metrics\MetricExporter\InMemoryExporter as MetricInMemoryExporter;
use OpenTelemetry\SDK\Metrics\MetricReader\ExportingReader;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;

/**
 * Test helper that wires the global OpenTelemetry SDK to in-memory exporters
 * for traces, metrics, and logs. Call setUpOtel() in setUp(), tearDownOtel()
 * in tearDown(), and use the helper methods to read recorded data.
 */
trait InMemoryOtelTrait
{
    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $otelSpanStorage;
    private TracerProvider $otelTracerProvider;

    private MetricInMemoryExporter $otelMetricExporter;
    private ExportingReader $otelMetricReader;
    private MeterProviderInterface $otelMeterProvider;

    /** @var \ArrayObject<int, ReadableLogRecord> */
    private \ArrayObject $otelLogStorage;
    private LoggerProviderInterface $otelLoggerProvider;

    /** @var string|false */
    private $otelOriginalDisabled;

    protected function setUpOtel(): void
    {
        $this->otelOriginalDisabled = getenv('OTEL_SDK_DISABLED');
        putenv('OTEL_SDK_DISABLED=false');
        $_ENV['OTEL_SDK_DISABLED'] = 'false';
        $_SERVER['OTEL_SDK_DISABLED'] = 'false';

        // Drop any cached OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED read so tests
        // can toggle the flag without leaking static state across cases.
        RegistryLookupTracing::reset();

        $this->otelSpanStorage = new \ArrayObject();
        $this->otelTracerProvider = new TracerProvider(new SimpleSpanProcessor(new SpanInMemoryExporter($this->otelSpanStorage)));

        $this->otelMetricExporter = new MetricInMemoryExporter();
        $this->otelMetricReader = new ExportingReader($this->otelMetricExporter);
        $this->otelMeterProvider = MeterProvider::builder()->addReader($this->otelMetricReader)->build();

        $this->otelLogStorage = new \ArrayObject();
        $this->otelLoggerProvider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsInMemoryExporter($this->otelLogStorage)))
            ->build();

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->otelTracerProvider)
            ->setMeterProvider($this->otelMeterProvider)
            ->setLoggerProvider($this->otelLoggerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDownOtel(): void
    {
        $this->otelTracerProvider->shutdown();
        $this->otelMeterProvider->shutdown();
        $this->otelLoggerProvider->shutdown();
        Globals::reset();

        if (false === $this->otelOriginalDisabled) {
            putenv('OTEL_SDK_DISABLED');
            unset($_ENV['OTEL_SDK_DISABLED'], $_SERVER['OTEL_SDK_DISABLED']);
        } else {
            putenv('OTEL_SDK_DISABLED='.$this->otelOriginalDisabled);
            $_ENV['OTEL_SDK_DISABLED'] = $this->otelOriginalDisabled;
            $_SERVER['OTEL_SDK_DISABLED'] = $this->otelOriginalDisabled;
        }
    }

    /**
     * @return list<ImmutableSpan>
     */
    protected function recordedSpans(): array
    {
        $this->otelTracerProvider->forceFlush();

        return iterator_to_array($this->otelSpanStorage->getIterator());
    }

    /**
     * @return array<string, \OpenTelemetry\SDK\Metrics\Data\Metric>
     */
    protected function recordedMetrics(): array
    {
        $this->otelMetricReader->collect();
        $by = [];
        foreach ($this->otelMetricExporter->collect() as $metric) {
            $by[$metric->name] = $metric;
        }

        return $by;
    }

    /**
     * @return list<ReadableLogRecord>
     */
    protected function recordedLogs(): array
    {
        $this->otelLoggerProvider->forceFlush();

        return iterator_to_array($this->otelLogStorage->getIterator());
    }
}
