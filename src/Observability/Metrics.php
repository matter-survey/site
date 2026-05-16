<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;

/**
 * Lightweight, static facade for emitting metrics. Backed by the global
 * MeterProvider; no-op when OTEL_METRICS_EXPORTER=none or the SDK is disabled.
 */
final class Metrics
{
    public const string METER_NAME = 'app.matter-survey';

    public static function counter(string $name, ?string $unit = null, ?string $description = null): CounterInterface
    {
        return Globals::meterProvider()
            ->getMeter(self::METER_NAME)
            ->createCounter($name, $unit, $description);
    }

    public static function histogram(string $name, ?string $unit = null, ?string $description = null): HistogramInterface
    {
        return Globals::meterProvider()
            ->getMeter(self::METER_NAME)
            ->createHistogram($name, $unit, $description);
    }
}
