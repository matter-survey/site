<?php

declare(strict_types=1);

namespace App\Tests\Observability;

use App\Observability\Doctrine\TracingMiddleware;
use Doctrine\DBAL\Driver\PDO\SQLite\Driver as SqliteDriver;
use OpenTelemetry\SDK\Metrics\Data\Histogram;
use PHPUnit\Framework\TestCase;

/**
 * Runs statements through the traced Doctrine connection (in-memory SQLite) and
 * asserts the semantic-convention db.client.operation.duration metric, keyed by
 * operation.
 */
final class DbClientMetricTest extends TestCase
{
    use InMemoryOtelTrait;

    protected function setUp(): void
    {
        $this->setUpOtel();
    }

    protected function tearDown(): void
    {
        $this->tearDownOtel();
    }

    public function testExecutionsEmitDbClientOperationDuration(): void
    {
        $driver = new TracingMiddleware()->wrap(new SqliteDriver());
        $conn = $driver->connect(['memory' => true]);
        $conn->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
        $stmt = $conn->prepare('SELECT * FROM t');
        $stmt->execute();

        $metric = $this->recordedMetrics()['db.client.operation.duration'] ?? null;
        $this->assertInstanceOf(\OpenTelemetry\SDK\Metrics\Data\Metric::class, $metric, 'db.client.operation.duration metric expected');
        $this->assertSame('s', $metric->unit);
        $this->assertInstanceOf(Histogram::class, $metric->data);

        // One data point per distinct {db.system.name, db.operation.name} set.
        $systemByOperation = [];
        foreach ($metric->data->dataPoints as $point) {
            $attrs = $point->attributes->toArray();
            $systemByOperation[$attrs['db.operation.name'] ?? '?'] = $attrs['db.system.name'] ?? null;
        }

        $this->assertArrayHasKey('SELECT', $systemByOperation, 'prepared SELECT should be recorded');
        $this->assertSame('sqlite', $systemByOperation['SELECT']);
        $this->assertArrayHasKey('CREATE TABLE', $systemByOperation, 'exec() CREATE TABLE should be recorded');
    }
}
