<?php

declare(strict_types=1);

namespace App\Observability\Monolog;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord as MonologLogRecord;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;

/**
 * Bridges Monolog records to the global OpenTelemetry LoggerProvider so the
 * same log line shows up alongside traces in the OTLP backend. The existing
 * file output is unaffected.
 *
 * The minimum level is enforced here, in the handler, NOT via the monolog.yaml
 * `level:` key: MonologBundle ignores `level`/`bubble` for `type: service`
 * handlers (it merely aliases the service), so without this the handler would
 * inherit Monolog's default of DEBUG and ship debug records to the backend.
 */
final class OtelLogsHandler extends AbstractProcessingHandler
{
    public function __construct(int|string|Level $level = Level::Info, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(MonologLogRecord $record): void
    {
        $logger = Globals::loggerProvider()->getLogger('app.matter-survey');

        $psrLevel = $record->level->toPsrLogLevel();

        $otelRecord = new LogRecord($record->message)
            ->setSeverityText($psrLevel)
            ->setSeverityNumber(Severity::fromPsr3($psrLevel))
            ->setTimestamp((int) ($record->datetime->format('U.u') * LogRecord::NANOS_PER_SECOND))
            ->setAttributes($record->context + $record->extra);

        $logger->emit($otelRecord);
    }
}
