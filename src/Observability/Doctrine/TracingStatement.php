<?php

declare(strict_types=1);

namespace App\Observability\Doctrine;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\ParameterType;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;

final class TracingStatement extends AbstractStatementMiddleware
{
    /** @var array<int|string, scalar|null> */
    private array $boundValues = [];

    public function __construct(Statement $wrapped, private readonly string $sql)
    {
        parent::__construct($wrapped);
    }

    #[\Override]
    public function bindValue(int|string $param, mixed $value, ParameterType $type): void
    {
        if (is_scalar($value) || null === $value) {
            $this->boundValues[$param] = $value;
        }
        parent::bindValue($param, $value, $type);
    }

    #[\Override]
    public function execute(): Result
    {
        $span = Globals::tracerProvider()
            ->getTracer('app.matter-survey')
            ->spanBuilder(SqlSpanNamer::nameFor($this->sql))
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system.name', 'sqlite')
            ->setAttribute('db.query.text', $this->sql)
            ->startSpan();

        if ($this->parameterCaptureEnabled()) {
            foreach ($this->boundValues as $key => $value) {
                $span->setAttribute('db.query.parameter.'.$key, (string) $value);
            }
        }

        try {
            $result = parent::execute();
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();

            throw $e;
        }

        $span->end();

        return $result;
    }

    private function parameterCaptureEnabled(): bool
    {
        $value = $_SERVER['OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE']
            ?? $_ENV['OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE']
            ?? getenv('OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE');

        if (false === $value || '' === $value) {
            return false;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }
}
