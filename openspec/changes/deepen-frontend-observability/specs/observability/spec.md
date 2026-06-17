## ADDED Requirements

### Requirement: HTTP responses expose server trace context via Server-Timing

The application SHALL expose the active server span's trace context on sampled HTTP responses via a `Server-Timing` header, so a browser navigation (which cannot send an outgoing `traceparent`) can correlate the document load with its backend trace. The header value SHALL follow the form `traceparent;desc="00-<trace-id>-<span-id>-<trace-flags>"` and SHALL be emitted only when a recorded server span exists for the request.

#### Scenario: Sampled request exposes its trace
- **WHEN** a sampled HTTP request produces a recorded root server span
- **THEN** the response includes a `Server-Timing` header whose `desc` carries the span's trace id and span id
- **AND** the trace id matches the request's root server span

#### Scenario: Unsampled or untraced request
- **WHEN** a request has no recorded server span (SDK disabled or not sampled)
- **THEN** no `Server-Timing` trace context header is added

#### Scenario: Same-origin readability
- **WHEN** the document is served from the same origin as the page
- **THEN** the `Server-Timing` entry is readable by the browser without additional `Timing-Allow-Origin` configuration

### Requirement: Service identity is configurable from the environment

The application SHALL derive its service identity — `service.name`, `service.namespace`, `service.version`, and `deployment.environment.name` — from environment configuration, and SHALL preserve operator-provided `OTEL_RESOURCE_ATTRIBUTES` rather than discarding them during boot-time merging. Resource-attribute resolution SHALL read existing values from the same sources the SDK uses (`$_SERVER`/`$_ENV`), not solely `getenv()`.

#### Scenario: Namespace provided via environment
- **WHEN** `OTEL_RESOURCE_ATTRIBUTES=service.namespace=matter-survey` is set in the environment (e.g. `.env.local`)
- **THEN** the registered resource includes `service.namespace=matter-survey`
- **AND** the boot-time additions (`service.version`, `deployment.environment.name`) are merged in without removing the operator-provided keys

#### Scenario: Service name and namespace on prod
- **WHEN** the production environment sets `OTEL_SERVICE_NAME=site` and `service.namespace=matter-survey`
- **THEN** exported telemetry identifies the service as name `site` within namespace `matter-survey`

#### Scenario: Defaults when unset
- **WHEN** no `OTEL_RESOURCE_ATTRIBUTES` is provided
- **THEN** the resource still includes the default `service.version` and `deployment.environment.name` derived at boot

### Requirement: OTLP log export excludes debug records

When Monolog records are bridged to the OpenTelemetry LoggerProvider, the bridge SHALL enforce a minimum severity of INFO in the handler itself, because the MonologBundle `level:` key is ignored for `type: service` handlers. Debug-level records SHALL NOT be exported to the OTLP backend.

#### Scenario: Debug record is not exported
- **WHEN** a `DEBUG` Monolog record is produced while OTLP log export is enabled
- **THEN** it is not emitted to the OpenTelemetry LoggerProvider

#### Scenario: Info and above are exported
- **WHEN** an `INFO`, `WARNING`, or `ERROR` Monolog record is produced while OTLP log export is enabled
- **THEN** it is emitted to the OpenTelemetry LoggerProvider with the corresponding severity

#### Scenario: Threshold holds under default construction
- **WHEN** the bridge handler is constructed with no explicit level (as the service-aliased handler is in production)
- **THEN** its minimum level is INFO, not the Monolog DEBUG default
