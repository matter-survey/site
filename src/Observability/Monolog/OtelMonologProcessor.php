<?php

declare(strict_types=1);

namespace App\Observability\Monolog;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use OpenTelemetry\API\Trace\Span;

/**
 * Injects the active span's trace_id and span_id into Monolog's `extra` array
 * so file logs and exported telemetry can be joined in any backend.
 *
 * No-op when no span is active (e.g. during kernel boot before the request).
 */
final class OtelMonologProcessor implements ProcessorInterface
{
    public function __invoke(LogRecord $record): LogRecord
    {
        $span = Span::getCurrent();
        $context = $span->getContext();

        if (!$context->isValid()) {
            return $record;
        }

        return $record->with(extra: $record->extra + [
            'trace_id' => $context->getTraceId(),
            'span_id' => $context->getSpanId(),
        ]);
    }
}
