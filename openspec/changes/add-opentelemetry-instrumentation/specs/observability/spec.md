## ADDED Requirements

### Requirement: SDK is initialized once per process

The application SHALL initialize a single global OpenTelemetry SDK (TracerProvider, MeterProvider, LoggerProvider, propagator) early in the boot of every process — both HTTP requests and console commands — and SHALL NOT initialize it more than once per process.

#### Scenario: HTTP request boot
- **WHEN** an HTTP request enters the Symfony kernel
- **THEN** the global SDK is registered before the first controller call
- **AND** subsequent calls to `OpenTelemetry\API\Globals::tracerProvider()` return the same provider instance

#### Scenario: Console command boot
- **WHEN** a console command starts (e.g. `php bin/console app:dcl:sync`)
- **THEN** the global SDK is registered before the command's `execute()` runs
- **AND** the same providers are used as for HTTP requests

#### Scenario: Disabled via environment
- **WHEN** the environment variable `OTEL_SDK_DISABLED=true`
- **THEN** the SDK is not registered
- **AND** all subsequent tracer/meter/logger calls return no-op implementations
- **AND** no outbound network calls are made

### Requirement: HTTP requests produce a root server span

The application SHALL produce exactly one root span per HTTP request, kind `SERVER`, named `<HTTP method> <route>`, with attributes following OpenTelemetry HTTP semantic conventions.

#### Scenario: Successful request
- **WHEN** a request `POST /api/submit` succeeds with status 200
- **THEN** a single root span is recorded with name `POST api_submit`
- **AND** attributes include `http.request.method=POST`, `http.route=/api/submit`, `http.response.status_code=200`, `url.path=/api/submit`
- **AND** the span status is `UNSET`

#### Scenario: Request raises an exception
- **WHEN** a controller throws an unhandled exception
- **THEN** the root span records the exception via `recordException`
- **AND** the span status is `ERROR`
- **AND** `http.response.status_code` reflects the final response code (e.g. `500`)

#### Scenario: Incoming W3C trace context is honored
- **WHEN** a request arrives with header `traceparent: 00-<trace-id>-<parent-id>-01`
- **THEN** the root span uses the supplied trace ID
- **AND** its parent span ID is the supplied parent ID
- **AND** propagation continues to outbound calls made during the request

#### Scenario: Span name uses the route, not the URL template params
- **WHEN** a request hits `/device/abc-123` matching route `device_show`
- **THEN** the span name is `GET device_show` (not `GET /device/abc-123`)

### Requirement: Doctrine SQL statements produce client spans

The application SHALL produce one span per executed SQL statement when the SDK is enabled, with kind `CLIENT`, attaching attributes per OpenTelemetry database semantic conventions.

#### Scenario: Successful query
- **WHEN** Doctrine executes `SELECT * FROM products WHERE vendor_id = ?`
- **THEN** a child span is created with name `SELECT products`
- **AND** attributes include `db.system.name=sqlite`, `db.query.text=SELECT * FROM products WHERE vendor_id = ?`
- **AND** the parent is the active span at execution time

#### Scenario: Query parameters are not captured by default
- **WHEN** a parameterised query executes
- **AND** `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE` is unset or `false`
- **THEN** no `db.query.parameter.*` attributes are added to the span

#### Scenario: Query parameters captured when explicitly enabled
- **WHEN** `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE=true`
- **AND** a parameterised query executes with two parameters
- **THEN** the span includes `db.query.parameter.0` and `db.query.parameter.1` attributes

#### Scenario: Failed query
- **WHEN** Doctrine raises a driver exception during execution
- **THEN** the span records the exception
- **AND** the span status is `ERROR`
- **AND** the exception propagates to the caller unchanged

### Requirement: Outbound HTTP requests carry trace context

The application SHALL inject W3C TraceContext headers (`traceparent`, and `tracestate` when present) into every outbound HTTP request made via Symfony's `HttpClientInterface`, and SHALL produce one client span per outbound request.

#### Scenario: DCL API call propagates trace
- **WHEN** `DclApiService` makes a request to the DCL API while a span is active
- **THEN** the request carries a `traceparent` header derived from the active span context
- **AND** a child span is recorded with kind `CLIENT`, name `<HTTP method>`, attributes `http.request.method`, `url.full`, `http.response.status_code`

#### Scenario: Network failure
- **WHEN** the outbound request fails (connection refused, DNS error, timeout)
- **THEN** the span records the exception
- **AND** the span status is `ERROR`
- **AND** the original transport exception propagates to the caller

#### Scenario: No active span
- **WHEN** an outbound request is made with no active span (e.g. from a CLI bootstrap path)
- **THEN** a span is still created and a `traceparent` is still injected (rooting a new trace)

### Requirement: Console commands produce a root span

The application SHALL produce one root span per console command invocation, kind `INTERNAL`, named after the command, capturing exit code and arguments.

#### Scenario: Successful command run
- **WHEN** `php bin/console app:dcl:sync` runs to completion with exit code 0
- **THEN** a root span named `command app:dcl:sync` is recorded
- **AND** attributes include `command.name=app:dcl:sync`, `command.exit_code=0`

#### Scenario: Command exits with non-zero status
- **WHEN** a command exits with code 2
- **THEN** the span attribute `command.exit_code=2`
- **AND** the span status is `ERROR`

#### Scenario: Command throws an exception
- **WHEN** a command throws an unhandled exception
- **THEN** the span records the exception
- **AND** the span status is `ERROR`

### Requirement: Domain operations have named spans

The application SHALL emit named spans for the following domain operations, with the listed attributes when the SDK is enabled.

| Span name | Where | Required attributes |
|---|---|---|
| `telemetry.submit` | `TelemetryService::process` | `vendor.id`, `submission.schema_version`, `submission.endpoint_count`, `submission.cluster_count` |
| `score.calculate` | `DeviceScoreService` (per product) | `product.id`, `device_type.id`, `score.value` |
| `dcl.sync` | `DclApiService::syncVendors` | `dcl.vendor_count`, `dcl.product_count`, `dcl.page_count` |
| `dcl.fetch_page` | `DclApiService` (per page) | `dcl.page`, `dcl.page_size` |
| `zap.sync` | ZAP sync command body | `zap.cluster_count` |
| `matter_registry.lookup` | `MatterRegistry` (cluster/device-type lookups) | `cluster.hex_id` or `device_type.hex_id`, `lookup.cache_hit` |

#### Scenario: Telemetry submission span
- **WHEN** `TelemetryService::process()` is called with a valid v3 submission containing 3 endpoints and 7 distinct clusters
- **THEN** a span named `telemetry.submit` is recorded
- **AND** attributes include `submission.endpoint_count=3` and `submission.cluster_count=7`
- **AND** the span is a child of the active HTTP request span

#### Scenario: Score calculation span
- **WHEN** `DeviceScoreService::calculate()` runs for a product
- **THEN** a span named `score.calculate` is recorded with `product.id` and `score.value` attributes

#### Scenario: DCL sync produces a tree
- **WHEN** `app:dcl:sync` runs and fetches 3 pages
- **THEN** one `dcl.sync` span exists with three child `dcl.fetch_page` spans
- **AND** each child outbound HTTP request span sits under its corresponding `dcl.fetch_page`

#### Scenario: MatterRegistry lookup attributes cache hits
- **WHEN** a cluster lookup is served from cache
- **THEN** the `matter_registry.lookup` span has `lookup.cache_hit=true`
- **AND** when the lookup misses cache and queries the database, `lookup.cache_hit=false`

### Requirement: Logs correlate with active traces

The application SHALL inject `trace_id` and `span_id` into every Monolog record produced while a span is active, and SHALL allow Monolog records to be exported via the OpenTelemetry LoggerProvider when configured.

#### Scenario: Log inside an active span
- **WHEN** Monolog logs a message while a span is active
- **THEN** the log record's `extra` array contains `trace_id` and `span_id` matching the active span's context
- **AND** the existing file output includes those IDs

#### Scenario: Log outside any span
- **WHEN** Monolog logs a message with no active span (e.g. during kernel boot before any request)
- **THEN** the log record has no `trace_id` or `span_id` keys

#### Scenario: OTLP log export enabled
- **WHEN** `OTEL_LOGS_EXPORTER=otlp`
- **AND** a Monolog record at level INFO or higher is emitted
- **THEN** an equivalent OpenTelemetry `LogRecord` is queued for export with the same severity, body, and trace context

#### Scenario: OTLP log export disabled
- **WHEN** `OTEL_LOGS_EXPORTER` is unset or `none`
- **THEN** no OTel log records are emitted regardless of Monolog activity
- **AND** Monolog file output continues unchanged

### Requirement: Telemetry export does not block the user-visible response

The application SHALL flush spans, metrics, and logs only after the user-visible HTTP response has been delivered.

#### Scenario: PHP-FPM environment
- **WHEN** the request is served by PHP-FPM
- **AND** the kernel reaches `kernel.terminate`
- **THEN** `fastcgi_finish_request()` is called before the SDK's batch processors flush
- **AND** measured user-visible request duration excludes export latency

#### Scenario: Non-FPM SAPI
- **WHEN** the request is served by a SAPI where `fastcgi_finish_request()` is unavailable (CLI server, mod_php)
- **THEN** the export happens at script termination via the SDK's auto-shutdown handler
- **AND** the application functions correctly even though export now adds to perceived latency

#### Scenario: Console command shutdown
- **WHEN** a console command finishes
- **THEN** the SDK auto-shutdown flushes all batched spans, metrics, and log records before the process exits

### Requirement: Configuration is environment-driven

The application SHALL be configurable entirely via standard `OTEL_*` environment variables and SHALL ship safe defaults that keep telemetry off in dev and test.

#### Scenario: Default repository state
- **WHEN** a fresh checkout is run with only committed `.env` and `.env.test`
- **THEN** `OTEL_SDK_DISABLED=true` is in effect
- **AND** no telemetry is exported anywhere

#### Scenario: Operator enables telemetry in prod
- **WHEN** `.env.local` sets `OTEL_SDK_DISABLED=false`, `OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example/`, `OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer abc`
- **THEN** the SDK boots and exports to that endpoint with the supplied headers
- **AND** no code change is required

#### Scenario: Sampling override
- **WHEN** `OTEL_TRACES_SAMPLER=parentbased_traceidratio` and `OTEL_TRACES_SAMPLER_ARG=0.1`
- **THEN** approximately 10% of root traces are sampled
- **AND** child spans inherit the parent's sampling decision

#### Scenario: Selective signal exporters
- **WHEN** `OTEL_TRACES_EXPORTER=otlp`, `OTEL_METRICS_EXPORTER=none`, `OTEL_LOGS_EXPORTER=none`
- **THEN** only traces are exported; metrics and log providers are no-ops

### Requirement: Resource attributes identify the service

Every signal SHALL carry resource attributes identifying the service, version, and deployment environment.

#### Scenario: Default resource
- **WHEN** the SDK boots in production
- **THEN** the resource includes `service.name=matter-survey`, a non-empty `service.version`, and `deployment.environment.name=prod`

#### Scenario: Resource attributes overridable via env
- **WHEN** `OTEL_SERVICE_NAME=matter-survey-canary` and `OTEL_RESOURCE_ATTRIBUTES=deployment.environment.name=staging,foo=bar`
- **THEN** the resource reflects those values
- **AND** `service.name=matter-survey-canary`

### Requirement: Telemetry failures are isolated from the application

A failure in the telemetry path SHALL never cause an HTTP request, console command, or background operation to fail.

#### Scenario: OTLP endpoint unreachable
- **WHEN** the configured OTLP endpoint is down or unreachable
- **THEN** the request continues to succeed
- **AND** no exception is propagated to the controller or service layer
- **AND** at most a single warning is logged per export batch failure

#### Scenario: Malformed configuration
- **WHEN** `OTEL_TRACES_SAMPLER_ARG` is set to a non-numeric value
- **THEN** the SDK falls back to a safe default (`1.0`) and logs a warning
- **AND** the application continues to run

#### Scenario: Provider shutdown error
- **WHEN** the auto-shutdown handler raises an exception while flushing
- **THEN** it is caught and logged
- **AND** does not cause the worker process to crash on the next request

### Requirement: An operational doctor command exposes resolved configuration

The application SHALL provide a console command that prints the resolved OpenTelemetry configuration so operators can verify deployment without reading code.

#### Scenario: Doctor command output
- **WHEN** `php bin/console app:otel:doctor` is invoked
- **THEN** the command prints the values of all relevant `OTEL_*` env vars
- **AND** prints whether the SDK is enabled, which exporters are active, and the resolved sampler
- **AND** exits with status 0 on healthy configuration and non-zero when required values are missing for an enabled exporter
