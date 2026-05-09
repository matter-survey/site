<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;

/**
 * Lightweight, static facade for creating domain spans without injecting
 * a tracer into every service. Backed by the global SDK registered by
 * OtelBootstrap; no-op when OTEL_SDK_DISABLED=true.
 */
final class Tracer
{
    public const TRACER_NAME = 'app.matter-survey';

    /**
     * @param array<string, scalar|array<scalar>|null> $attributes
     * @param SpanKind::KIND_*                         $kind
     */
    public static function start(
        string $name,
        array $attributes = [],
        int $kind = SpanKind::KIND_INTERNAL,
    ): SpanInterface {
        $builder = Globals::tracerProvider()
            ->getTracer(self::TRACER_NAME)
            ->spanBuilder($name)
            ->setSpanKind($kind);

        foreach ($attributes as $key => $value) {
            $builder->setAttribute($key, $value);
        }

        return $builder->startSpan();
    }
}
