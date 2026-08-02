<?php

declare(strict_types=1);

namespace App\Observability;

use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\SemConv\Metrics\DbMetrics;
use OpenTelemetry\SemConv\Metrics\HttpMetrics;

/**
 * Emits OpenTelemetry semantic-convention metrics with their canonical names,
 * units (seconds), and advisory histogram buckets defined in one place. Backed
 * by the same global MeterProvider as {@see Metrics}; a no-op when the SDK is
 * disabled.
 */
final class SemConvMetrics
{
    /**
     * Advisory buckets from the HTTP metrics semantic conventions.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/http/http-metrics/
     */
    private const array HTTP_SERVER_REQUEST_DURATION_BUCKETS = [0.005, 0.01, 0.025, 0.05, 0.075, 0.1, 0.25, 0.5, 0.75, 1, 2.5, 5, 7.5, 10];

    /**
     * Advisory buckets from the database metrics semantic conventions.
     *
     * @see https://opentelemetry.io/docs/specs/semconv/database/database-metrics/
     */
    private const array DB_CLIENT_OPERATION_DURATION_BUCKETS = [0.001, 0.005, 0.01, 0.05, 0.1, 0.5, 1, 5, 10];

    public static function httpServerRequestDuration(): HistogramInterface
    {
        return Metrics::histogram(
            HttpMetrics::HTTP_SERVER_REQUEST_DURATION,
            's',
            'Duration of HTTP server requests.',
            ['ExplicitBucketBoundaries' => self::HTTP_SERVER_REQUEST_DURATION_BUCKETS],
        );
    }

    public static function dbClientOperationDuration(): HistogramInterface
    {
        return Metrics::histogram(
            DbMetrics::DB_CLIENT_OPERATION_DURATION,
            's',
            'Duration of database client operations.',
            ['ExplicitBucketBoundaries' => self::DB_CLIENT_OPERATION_DURATION_BUCKETS],
        );
    }
}
