<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Monolog\OtelLogsHandler;
use Monolog\Level;
use Monolog\Logger;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\SDK\Logs\Exporter\InMemoryExporter as LogsInMemoryExporter;
use OpenTelemetry\SDK\Logs\LoggerProvider;
use OpenTelemetry\SDK\Logs\LoggerProviderInterface;
use OpenTelemetry\SDK\Logs\Processor\SimpleLogRecordProcessor;
use OpenTelemetry\SDK\Logs\ReadableLogRecord;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter as SpanInMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class OtelLogsHandlerTest extends TestCase
{
    /** @var \ArrayObject<int, ReadableLogRecord> */
    private \ArrayObject $logStorage;
    private LoggerProviderInterface $loggerProvider;
    private TracerProvider $tracerProvider;
    /** @var string|false */
    private string|bool $originalDisabled;

    protected function setUp(): void
    {
        $this->originalDisabled = getenv('OTEL_SDK_DISABLED');
        putenv('OTEL_SDK_DISABLED=false');
        $_ENV['OTEL_SDK_DISABLED'] = 'false';
        $_SERVER['OTEL_SDK_DISABLED'] = 'false';

        $this->logStorage = new \ArrayObject();
        $this->loggerProvider = LoggerProvider::builder()
            ->addLogRecordProcessor(new SimpleLogRecordProcessor(new LogsInMemoryExporter($this->logStorage)))
            ->build();

        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor(new SpanInMemoryExporter()));

        Globals::reset();
        Sdk::builder()
            ->setLoggerProvider($this->loggerProvider)
            ->setTracerProvider($this->tracerProvider)
            ->setPropagator(TraceContextPropagator::getInstance())
            ->buildAndRegisterGlobal();
    }

    protected function tearDown(): void
    {
        $this->loggerProvider->shutdown();
        $this->tracerProvider->shutdown();
        Globals::reset();

        if (false === $this->originalDisabled) {
            putenv('OTEL_SDK_DISABLED');
            unset($_ENV['OTEL_SDK_DISABLED'], $_SERVER['OTEL_SDK_DISABLED']);
        } else {
            putenv('OTEL_SDK_DISABLED='.$this->originalDisabled);
        }
    }

    public function testInfoRecordIsBridgedToOtel(): void
    {
        $logger = new Logger('app', [new OtelLogsHandler(Level::Info)]);
        $logger->info('hello world', ['vendor' => 'TestVendor']);

        $this->loggerProvider->forceFlush();

        $records = iterator_to_array($this->logStorage->getIterator());
        $this->assertCount(1, $records);

        /** @var ReadableLogRecord $record */
        $record = $records[0];
        $this->assertSame('hello world', $record->getBody());
        $this->assertSame('info', $record->getSeverityText());
        $this->assertSame('TestVendor', $record->getAttributes()->toArray()['vendor'] ?? null);
    }

    public function testDebugRecordsAreFiltered(): void
    {
        $logger = new Logger('app', [new OtelLogsHandler(Level::Info)]);
        $logger->debug('not bridged');

        $this->loggerProvider->forceFlush();
        $records = iterator_to_array($this->logStorage->getIterator());

        $this->assertCount(0, $records, 'DEBUG should be below INFO threshold');
    }
}
