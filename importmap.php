<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    'chart.js' => [
        'version' => '3.9.1',
    ],
    '@hotwired/turbo' => [
        'version' => '7.3.0',
    ],
    '@grafana/faro-web-sdk' => [
        'version' => '2.7.1',
    ],
    '@grafana/faro-core' => [
        'version' => '2.7.1',
    ],
    'ua-parser-js' => [
        'version' => '1.0.41',
    ],
    'web-vitals/attribution' => [
        'version' => '5.3.0',
    ],
    '@grafana/faro-web-tracing' => [
        'version' => '2.7.1',
    ],
    '@opentelemetry/core' => [
        'version' => '2.7.1',
    ],
    '@opentelemetry/otlp-transformer/build/src/common/utils' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/otlp-transformer/build/src/trace/internal' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/otlp-transformer/build/src/trace/internal-types' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/instrumentation-fetch' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/instrumentation-xml-http-request' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/api' => [
        'version' => '1.9.1',
    ],
    '@opentelemetry/instrumentation' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/resources' => [
        'version' => '2.7.1',
    ],
    '@opentelemetry/sdk-trace-web' => [
        'version' => '2.7.1',
    ],
    '@opentelemetry/semantic-conventions' => [
        'version' => '1.41.1',
    ],
    '@opentelemetry/api-logs' => [
        'version' => '0.218.0',
    ],
    '@opentelemetry/sdk-trace-base' => [
        'version' => '2.7.1',
    ],
];
