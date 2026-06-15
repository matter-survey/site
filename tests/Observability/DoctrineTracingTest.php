<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Doctrine\TracingMiddleware;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\SDK\Sdk;
use OpenTelemetry\SDK\Trace\ImmutableSpan;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;

final class DoctrineTracingTest extends TestCase
{
    /** @var \ArrayObject<int, ImmutableSpan> */
    private \ArrayObject $storage;
    private TracerProvider $tracerProvider;

    protected function setUp(): void
    {
        $this->storage = new \ArrayObject();
        $this->tracerProvider = new TracerProvider(new SimpleSpanProcessor(new InMemoryExporter($this->storage)));

        Globals::reset();
        Sdk::builder()
            ->setTracerProvider($this->tracerProvider)
            ->buildAndRegisterGlobal();

        putenv('OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE');
        unset($_ENV['OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE'], $_SERVER['OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE']);
    }

    protected function tearDown(): void
    {
        $this->tracerProvider->shutdown();
        Globals::reset();
    }

    public function testPreparedQueryProducesClientSpan(): void
    {
        $driver = new TracingMiddleware()->wrap(new SqliteDriver());
        $config = new Configuration();
        $config->setMiddlewares([new TracingMiddleware()]);

        $conn = $driver->connect(['memory' => true]);
        $conn->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $stmt = $conn->prepare('INSERT INTO t (name) VALUES (?)');
        $stmt->bindValue(1, 'alice', \Doctrine\DBAL\ParameterType::STRING);
        $stmt->execute();

        $this->tracerProvider->forceFlush();

        $spans = $this->getSpans();
        $insertSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s): bool => 'INSERT t' === $s->getName()));
        $this->assertCount(1, $insertSpans);

        $attrs = $insertSpans[0]->getAttributes()->toArray();
        $this->assertSame('sqlite', $attrs['db.system.name']);
        $this->assertSame('INSERT INTO t (name) VALUES (?)', $attrs['db.query.text']);
        $this->assertSame(SpanKind::KIND_CLIENT, $insertSpans[0]->getKind());
        $this->assertArrayNotHasKey('db.query.parameter.1', $attrs, 'Parameter capture is off by default');
    }

    public function testParameterCaptureWhenEnabled(): void
    {
        putenv('OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE=true');

        try {
            $driver = new TracingMiddleware()->wrap(new SqliteDriver());
            $conn = $driver->connect(['memory' => true]);
            $conn->exec('CREATE TABLE u (id INTEGER PRIMARY KEY, name TEXT)');
            $stmt = $conn->prepare('INSERT INTO u (name) VALUES (?)');
            $stmt->bindValue(1, 'bob', \Doctrine\DBAL\ParameterType::STRING);
            $stmt->execute();

            $this->tracerProvider->forceFlush();
            $spans = $this->getSpans();
            $inserts = array_values(array_filter($spans, static fn (ImmutableSpan $s): bool => 'INSERT u' === $s->getName()));
            $this->assertCount(1, $inserts);
            $this->assertSame('bob', $inserts[0]->getAttributes()->toArray()['db.query.parameter.1'] ?? null);
        } finally {
            putenv('OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE');
        }
    }

    public function testFailedQueryRecordsException(): void
    {
        $driver = new TracingMiddleware()->wrap(new SqliteDriver());
        $conn = $driver->connect(['memory' => true]);

        $threw = false;
        try {
            $conn->exec('this is not valid SQL');
        } catch (\Throwable) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Expected the underlying driver exception to propagate');

        $this->tracerProvider->forceFlush();
        $spans = $this->getSpans();
        $this->assertCount(1, $spans);
        $this->assertSame(\OpenTelemetry\API\Trace\StatusCode::STATUS_ERROR, $spans[0]->getStatus()->getCode());
    }

    public function testQueryDirectProducesSpan(): void
    {
        $driver = new TracingMiddleware()->wrap(new SqliteDriver());
        $conn = $driver->connect(['memory' => true]);
        $conn->exec('CREATE TABLE q (id INTEGER PRIMARY KEY)');
        $conn->exec('INSERT INTO q VALUES (1)');
        $result = $conn->query('SELECT * FROM q');
        $rows = $result->fetchAllAssociative();

        $this->assertCount(1, $rows);

        $this->tracerProvider->forceFlush();
        $spans = $this->getSpans();
        $selectSpans = array_values(array_filter($spans, static fn (ImmutableSpan $s): bool => 'SELECT q' === $s->getName()));
        $this->assertCount(1, $selectSpans);
    }

    /**
     * @return list<ImmutableSpan>
     */
    private function getSpans(): array
    {
        return array_values(iterator_to_array($this->storage->getIterator()));
    }
}
