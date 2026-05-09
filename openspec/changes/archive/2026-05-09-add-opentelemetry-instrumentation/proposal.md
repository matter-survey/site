## Why

Matter Survey collects telemetry from Matter devices, but the service itself runs blind: there is no way to see request latency, errors, slow Doctrine queries, DCL sync runtimes, or scoring failures in production. Monolog writes to a file inside `var/log/`, which is reachable only via SFTP and impossible to slice or correlate. As traffic and complexity grow, even simple questions ("is `/api/submit` getting slower?", "did the last DCL sync hang?") cannot be answered. Instrumenting with OpenTelemetry establishes a vendor-neutral observability foundation that fits a small budget and a constrained shared host.

## What Changes

- Add OpenTelemetry SDK + OTLP/HTTP exporter as Composer dependencies (pure PHP, no PHP extensions required).
- Bootstrap a global `TracerProvider`, `MeterProvider`, and `LoggerProvider` configured from `OTEL_*` environment variables, registered once during Symfony kernel boot.
- Instrument the HTTP request lifecycle via a Symfony `EventSubscriber` on `kernel.request` / `kernel.controller` / `kernel.exception` / `kernel.terminate`.
- Instrument Doctrine DBAL via the official `Middleware` extension point, producing one span per SQL statement with sanitized SQL as an attribute.
- Instrument outbound HTTP via a decorator over `Symfony\Contracts\HttpClient\HttpClientInterface`, injecting W3C `traceparent` headers.
- Instrument console commands (`app:dcl:sync`, `app:zap:sync`, `app:scores:rebuild`) via a `ConsoleEvents` subscriber.
- Add domain spans inside `TelemetryService::process`, `DeviceScoreService`, `DclApiService`, and `MatterRegistry` lookups, with attributes that turn aggregate latency questions into vendor- and shape-specific ones.
- Bridge Monolog to OTel Logs and add a Monolog processor that injects `trace_id` / `span_id` so file logs and exported telemetry correlate.
- Export spans/metrics/logs in the background via `fastcgi_finish_request()` after the response has been flushed, falling back to synchronous shutdown export when not running under PHP-FPM.
- Document deployment configuration (env vars, choice of OTLP backend such as Grafana Cloud or self-hosted Tempo/Uptrace) and how to disable telemetry entirely.

## Capabilities

### New Capabilities

- `observability`: vendor-neutral OpenTelemetry instrumentation covering HTTP requests, Doctrine queries, outbound HTTP, console commands, and domain operations. Defines what is traced/measured/logged, the configuration surface, and how telemetry is exported without blocking user-facing requests.

### Modified Capabilities

<!-- None — no existing specs in openspec/specs/. -->

## Impact

- **Composer dependencies**: adds `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `open-telemetry/sem-conv`. No new PHP extensions required. OTLP transport is HTTP/JSON to avoid `ext-protobuf` and `ext-grpc`.
- **Environment & deploy**: introduces a small set of `OTEL_*` env vars in `.env` (safe defaults: telemetry off) and `.env.local` (real endpoint + headers). Existing rsync deploy is unaffected; no extension install needed on the production host.
- **Code touched**: a new `src/Observability/` namespace (SDK bootstrap, subscribers, middleware, processors); thin domain spans added inside `TelemetryService`, `DeviceScoreService`, `DclApiService`, `MatterRegistry`, and `CapabilityService`. No public API or DB schema changes.
- **Performance**: with `BatchSpanProcessor` + `fastcgi_finish_request()`, user-facing latency is unaffected on PHP-FPM. Without FPM, end-of-request export adds tens of ms; mitigated by sampling and by leaving telemetry disabled in dev unless opted in.
- **Operational dependency**: an OTLP-compatible backend (Grafana Cloud free tier by default, self-hosted Tempo/Uptrace as alternative). Service degrades safely: if the endpoint is unreachable, exports fail silently and the application continues.
- **Observability for ops**: the `/health` endpoint and CI/deploy workflows can begin to be checked against trace/metric data instead of guesswork.
