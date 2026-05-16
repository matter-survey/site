<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

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
        try {
            $result = parent::query($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();

            throw $e;
        }
        $span->end();

        return $result;
    }

    #[\Override]
    public function exec(string $sql): int|string
    {
        $span = $this->startSpan($sql);
        try {
            $affected = parent::exec($sql);
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();

            throw $e;
        }
        $span->setAttribute('db.response.returned_rows', is_int($affected) ? $affected : 0);
        $span->end();

        return $affected;
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
