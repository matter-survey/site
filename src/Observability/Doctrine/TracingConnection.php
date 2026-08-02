<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use App\Observability\SemConvMetrics;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\SemConv\TraceAttributes;
use OpenTelemetry\SemConv\TraceAttributeValues;

final class TracingConnection extends AbstractConnectionMiddleware
{
    #[\Override]
    public function prepare(string $sql): Statement
    {
        return new TracingStatement(parent::prepare($sql), $sql);
    }

    #[\Override]
    public function query(string $sql): Result
    {
        $span = $this->startSpan($sql);
        $startNs = hrtime(true);
        try {
            return parent::query($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $this->recordDbDuration($sql, $startNs);
        }
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        $span = $this->startSpan($sql);
        $startNs = hrtime(true);
        try {
            $affected = parent::exec($sql);
            $span->setAttribute('db.response.returned_rows', is_int($affected) ? $affected : 0);

            return $affected;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());

            throw $e;
        } finally {
            $span->end();
            $this->recordDbDuration($sql, $startNs);
        }
    }

    /**
     * Emit the db.client.operation.duration histogram (seconds).
     */
    private function recordDbDuration(string $sql, int $startNs): void
    {
        SemConvMetrics::dbClientOperationDuration()->record(
            (hrtime(true) - $startNs) / 1_000_000_000,
            [
                TraceAttributes::DB_SYSTEM_NAME => TraceAttributeValues::DB_SYSTEM_NAME_SQLITE,
                TraceAttributes::DB_OPERATION_NAME => SqlSpanNamer::operationFor($sql),
            ],
        );
    }

    private function startSpan(string $sql): SpanInterface
    {
        return Globals::tracerProvider()
            ->getTracer('app.matter-survey')
            ->spanBuilder(SqlSpanNamer::nameFor($sql))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', 'sqlite')
            ->setAttribute('db.query.text', $sql)
            ->startSpan();
    }
}
