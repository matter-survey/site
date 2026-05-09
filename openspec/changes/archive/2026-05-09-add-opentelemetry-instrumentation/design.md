## Context

Matter Survey is a Symfony 7.4 application running on PHP 8.4+ (locally 8.5), deployed by rsync to a shared host that does **not** allow installing PHP extensions. This rules out the official `ext-opentelemetry` zend-observer extension, which means none of the `open-telemetry/opentelemetry-auto-*` Composer packages can function: they all hook PHP at the engine level. By the same constraint, gRPC OTLP transport (`ext-grpc`) is unavailable, and protobuf encoding via `ext-protobuf` is at minimum unwanted complexity.

The application already exposes plenty of meaningful boundaries: `POST /api/submit` (DTO-validated, rate-limited), Doctrine 4 (DBAL + ORM), Symfony HttpClient (used by `DclApiService`), Monolog logging, and three console commands (`app:dcl:sync`, `app:zap:sync`, `app:scores:rebuild`) some of which run for minutes at a time. The capability and scoring services are pure CPU but not cheap. Everything currently lives in one PHP-FPM process per request — there is no daemon, no sidecar, no log shipper.

Stakeholders are essentially one: the maintainer (you). The driver is "I cannot answer simple latency or error questions about prod today, and I want to."

## Goals / Non-Goals

**Goals:**

- Bring tracing, metrics, and logs into Matter Survey using the OpenTelemetry SDK without any PHP extension.
- Keep user-facing latency unchanged: telemetry export must not block HTTP responses on PHP-FPM.
- Use environment-variable configuration so the same code runs unchanged across dev / CI / prod, and so an operator can disable telemetry with a single env flip.
- Provide both framework-level instrumentation (HTTP request, Doctrine, outbound HTTP, console) and a small set of domain spans (`telemetry.submit`, `score.calculate`, `dcl.sync`, `zap.sync`, `matter_registry.lookup`) where the framework view is too coarse.
- Correlate Monolog file logs with exported traces by injecting `trace_id` / `span_id`.
- Be backend-agnostic: any OTLP/HTTP-compatible collector or SaaS works (Grafana Cloud, Honeycomb, Uptrace, Tempo, etc.).

**Non-Goals:**

- Installing or relying on `ext-opentelemetry`, `ext-grpc`, or `ext-protobuf`.
- Running a local OpenTelemetry Collector sidecar on the production host.
- Auto-instrumentation of third-party libraries we do not call directly.
- Vendor-specific dashboards, alerts, or SLO definitions — those live with whatever backend the operator chooses, not in this codebase.
- Replacing Monolog's existing file output. Monolog continues to write to `var/log/`; OTel logs are an additional sink.
- Distributed tracing across multiple services. There is one service.

## Decisions

### Decision 1: Manual instrumentation via the pure-PHP SDK

Use `open-telemetry/sdk` + `open-telemetry/exporter-otlp` + `open-telemetry/sem-conv` (composer-only, no extensions). All instrumentation is wired by hand using Symfony's standard extension points.

**Alternatives considered:**

- *Auto-instrumentation packages* (`opentelemetry-auto-symfony`, `opentelemetry-auto-doctrine`): rejected — all require `ext-opentelemetry`.
- *No SDK at all, ship Monolog over syslog*: rejected — leaves traces and metrics on the table, and the correlation problem (which log line belongs to which slow request) stays unsolved.

The trade-off is that we own the instrumentation surface. Upside: the surface is small (one bundle's worth of code) and is exactly the set we care about, with domain attributes baked in.

### Decision 2: OTLP over HTTP with JSON encoding

Configure the exporter with `OTEL_EXPORTER_OTLP_PROTOCOL=http/json`. This requires no PHP extensions. Endpoint defaults to whatever the operator sets via `OTEL_EXPORTER_OTLP_ENDPOINT`.

**Alternatives considered:**

- *http/protobuf*: works without `ext-protobuf` (pure-PHP `google/protobuf` falls back automatically), but adds a dependency and CPU cost we don't need at our volumes.
- *grpc*: rejected — needs `ext-grpc`.

Trade-off: JSON payloads are larger over the wire than protobuf. At expected request volumes for a Matter survey site, this is invisible.

### Decision 3: Bootstrap with `Sdk::builder()->setAutoShutdown(true)->buildAndRegisterGlobal()`

Construct providers in a single `OtelBootstrap` service registered as `kernel.event_listener` on `kernel.boot` priority high (and `console.command` priority high for CLI). Resource attributes are populated from `APP_ENV` and `composer.json` version. The SDK's auto-shutdown handler flushes batched spans/metrics/logs at script termination.

**Alternatives considered:**

- *Lazy init on first span*: harder to reason about; risks first-request bias on dependency resolution.
- *Manual shutdown call in a `kernel.terminate` listener*: works, but auto-shutdown already covers both SAPI and CLI paths and is one less place to forget.

### Decision 4: BatchSpanProcessor + `fastcgi_finish_request()` for non-blocking export

Use `BatchSpanProcessor` (default queue 2048, schedule delay 5000 ms, max export batch 512) so spans are buffered in memory until shutdown. A `kernel.terminate` listener calls `fastcgi_finish_request()` (when available) before the shutdown handler runs the export. The user gets the response immediately; the export happens in the still-running FPM worker.

```
   request ─▶ kernel.request ─▶ controller ─▶ kernel.response
                                                    │
                              user sees response ◀──┤  fastcgi_finish_request()
                                                    │
                              kernel.terminate ─────┤
                                                    │
                              SDK auto-shutdown ────▶ flush BatchSpanProcessor
                                                       (HTTP POST to OTLP)
```

**Alternatives considered:**

- *SimpleSpanProcessor*: each span ends with a synchronous HTTP call. Adds latency to every request. Rejected for prod; useful for debug.
- *Cron-based offline export*: would need a queue and a worker, overkill.
- *Pure async via something like ReactPHP*: pushing too far for a Symfony monolith.

Trade-off: if the FPM worker is killed mid-export (e.g., timeout), the batched spans for that request are lost. Acceptable; we're not building a billing system.

### Decision 5: Sampling — parent-based, ratio configurable, default 1.0

Use `ParentBased(traceIdRatioBased(ratio))` configured via `OTEL_TRACES_SAMPLER=parentbased_traceidratio` and `OTEL_TRACES_SAMPLER_ARG`. Default ratio is `1.0` (sample everything). Volume is low; sampling can be tuned down later.

### Decision 6: HTTP request tracing via Symfony EventSubscriber

A single `RequestTracingSubscriber` listens on:

- `kernel.request` (priority high) — extract incoming `traceparent`, start a root server span, store on the request attribute bag.
- `kernel.controller` — set `http.route` and controller class as span attributes once routing has resolved.
- `kernel.exception` — record exception, mark span status `ERROR`.
- `kernel.terminate` — set `http.response.status_code`, end span.

Span name follows `{HTTP method} {route}` (e.g. `POST api_submit`) per OTel HTTP semconv.

**Alternative considered:** a Symfony "middleware" via the security firewall stack — rejected, kernel events are the canonical hook and apply globally.

### Decision 7: Doctrine instrumentation via DBAL Middleware

Implement `Doctrine\DBAL\Driver\Middleware` to wrap `Connection`, `Statement`, and `Result`. One span per `executeQuery`/`executeStatement` with attributes:

- `db.system.name = sqlite`
- `db.query.text` (statement, sanitized via `Doctrine\DBAL\SQL\Parser` strip-values)
- `db.query.parameter.<n>` (only when `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE=true`, default off — privacy)
- `db.response.returned_rows` for queries

Registered via `doctrine.yaml` `dbal.connections.default.middleware`.

**Alternative considered:** Doctrine SQL logger — deprecated in DBAL 4 in favor of middleware. Don't use it.

### Decision 8: Outbound HTTP via decorator over `HttpClientInterface`

A `TracingHttpClient implements HttpClientInterface` decorator wraps the default client. It:

1. Starts a client span before delegating.
2. Injects W3C `traceparent` (and `tracestate`) headers via `TraceContextPropagator`.
3. Records `http.request.method`, `url.full`, `http.response.status_code` once the response is awaited.
4. Ends the span on response completion or stream error.

Bound globally via `services.yaml` `decorates: 'http_client'`. Existing services (`DclApiService`) get tracing for free.

### Decision 9: Console command tracing via ConsoleEvents

A `ConsoleTracingSubscriber` listens on `console.command` and `console.terminate`. One root span per invocation, attributes:

- `command.name` (e.g. `app:dcl:sync`)
- `command.argv` (sanitized)
- `command.exit_code`

Long-running syncs become traceable in the same backend as HTTP requests.

### Decision 10: Domain spans via a thin `Tracer` service

Inject `OpenTelemetry\API\Trace\TracerInterface` into `TelemetryService`, `DeviceScoreService`, `DclApiService`, `MatterRegistry`, `CapabilityService`. Use `CachedInstrumentation('app.matter-survey')` so the tracer is fetched lazily and survives provider rebuilds in tests.

Initial domain spans:

| Span | Owner | Attributes |
|---|---|---|
| `telemetry.submit` | TelemetryService::process | `vendor.id`, `vendor.name`, `submission.schema_version`, `submission.endpoint_count`, `submission.cluster_count` |
| `score.calculate` | DeviceScoreService | `product.id`, `device_type.id`, `score.value` |
| `dcl.sync` | DclApiService::syncVendors | `dcl.vendor_count`, `dcl.product_count`, `dcl.page_count` |
| `dcl.fetch_page` | DclApiService (per page) | `dcl.page`, `dcl.page_size` |
| `zap.sync` | ZAP sync command | `zap.cluster_count` |
| `matter_registry.lookup` | MatterRegistry | `cluster.hex_id` or `device_type.hex_id`, `lookup.cache_hit` |

Names are dot-separated lowercase, attributes use OTel-style namespacing.

### Decision 11: Logs — Monolog handler + processor for trace correlation

Two Monolog pieces:

1. **`OtelMonologProcessor`** runs first; for every record, when an active span exists, sets `extra.trace_id` and `extra.span_id`. The existing file output then includes IDs that join cleanly to traces in the backend.
2. **`OtelLogsHandler`** (level INFO+) bridges Monolog records to the OTel `LoggerProvider` so logs flow over OTLP alongside traces.

Both are registered in `monolog.yaml`. The handler is enabled only when `OTEL_LOGS_EXPORTER=otlp`.

### Decision 12: Single global enable/disable switch

`OTEL_SDK_DISABLED=true` short-circuits bootstrap to no-op providers. This is the safe default in `.env` and `.env.test`. Operators flip it via `.env.local` in prod.

### Decision 13: Code layout

```
src/Observability/
  Bootstrap/
    OtelBootstrap.php           # builds + registers global SDK on kernel.boot
  Subscriber/
    RequestTracingSubscriber.php   # Symfony kernel events
    ConsoleTracingSubscriber.php   # Console events
  HttpClient/
    TracingHttpClient.php          # decorator over HttpClientInterface
  Doctrine/
    TracingMiddleware.php          # DBAL Middleware
    TracingDriver.php              # wraps Driver
    TracingConnection.php          # wraps Connection
    TracingStatement.php           # wraps Statement
  Monolog/
    OtelMonologProcessor.php       # adds trace_id/span_id
    OtelLogsHandler.php            # bridges to OTel LoggerProvider
  Sampling/                         # placeholder for future custom samplers
config/packages/
  observability.yaml              # OTel-specific bundle config (services + monolog wiring)
```

Tests under `tests/Observability/` use the SDK's `InMemoryExporter` + `InMemoryLogRecordExporter` to assert spans and log records without hitting any network.

### Decision 14: Configuration surface

| Variable | Default | Purpose |
|---|---|---|
| `OTEL_SDK_DISABLED` | `true` (in `.env`) | Master switch. |
| `OTEL_SERVICE_NAME` | `matter-survey` | Service identifier. |
| `OTEL_SERVICE_VERSION` | derived from composer | Surfaces in resource attrs. |
| `OTEL_RESOURCE_ATTRIBUTES` | `deployment.environment.name=${APP_ENV}` | Extra resource attrs. |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | unset | OTLP endpoint URL. |
| `OTEL_EXPORTER_OTLP_PROTOCOL` | `http/json` | Pinned to JSON. |
| `OTEL_EXPORTER_OTLP_HEADERS` | unset | For `Authorization=Bearer …` (Grafana Cloud, Honeycomb). |
| `OTEL_TRACES_EXPORTER` | `otlp` | Or `none` to skip traces only. |
| `OTEL_TRACES_SAMPLER` | `parentbased_traceidratio` | |
| `OTEL_TRACES_SAMPLER_ARG` | `1.0` | |
| `OTEL_METRICS_EXPORTER` | `none` initially | Phase 3 flips to `otlp`. |
| `OTEL_LOGS_EXPORTER` | `none` initially | Phase 3 flips to `otlp`. |
| `OTEL_PHP_TRACES_PROCESSOR` | `batch` | |

`.env` ships safe defaults (disabled). `.env.local` carries real endpoint and headers and is not committed.

## Risks / Trade-offs

- **[Risk] Worker-tail export is best-effort.** A killed FPM worker drops batched spans for that request. → Mitigation: small batch size (512) and short schedule delay (5 s) keep the unflushed window small. Domain-critical signals (errors, score failures) would also surface via Monolog file logs as a fallback.
- **[Risk] Endpoint unreachable adds error log noise.** → Mitigation: SDK exporter logs at `warning` not `error`; Monolog will not page. We rely on the SDK's built-in exponential backoff and bounded queue (drops oldest when full).
- **[Risk] PII in attributes.** Submissions contain `installation_id` UUIDs, vendor names, product names. → Mitigation: explicit attribute allowlist per span — no raw payload. SQL parameter capture is off by default (`OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE`).
- **[Risk] Performance overhead in CPU-heavy paths.** Wrapping every Doctrine statement adds object allocation. → Mitigation: spans are cheap; batch processor amortises; if a benchmark shows a hot path, opt out of Doctrine spans for that path via a marker on the connection options.
- **[Risk] Test pollution.** Global SDK registration leaks across tests. → Mitigation: a `kernel.shutdown` test trait that calls `Sdk::builder()->buildAndRegisterGlobal()` per test with InMemory exporters; reset the `Globals` registry between tests.
- **[Risk] Drift in env vars vs actual config.** → Mitigation: a single `OtelConfigDoctor` console command that prints resolved config and the chosen processors/exporters; document its invocation in CLAUDE.md.
- **[Trade-off] Manual instrumentation requires keeping spans in sync with code changes.** → Accepted. The instrumentation surface is small and lives in one namespace; PR review covers it.
- **[Trade-off] JSON over the wire is bigger than protobuf.** → Accepted at our volumes; revisit if egress becomes a concern.

## Migration Plan

Implementation is staged so each phase is independently shippable and observable.

**Phase 1 — Foundation + HTTP request tracing.** Land SDK + OTLP/HTTP+JSON + bootstrap + `RequestTracingSubscriber` + `fastcgi_finish_request()` flush + Monolog `trace_id` processor + global disable switch. Acceptance: in dev, with `OTEL_SDK_DISABLED=false` and a local Uptrace/console exporter, a `/api/submit` request produces a single root span with method/route/status attrs; Monolog file output for that request includes `trace_id`.

**Phase 2 — Framework breadth.** Doctrine middleware, outbound HTTP decorator, console subscriber. Acceptance: one DCL sync run produces one root span with N child spans for outbound HTTP and M child spans for SQL statements; the trace tree is navigable end-to-end.

**Phase 3 — Domain spans + metrics + logs.** Inject domain spans into services. Add a counter (submissions) and histogram (process latency). Wire `OtelLogsHandler` to bridge Monolog → OTLP logs. Acceptance: a Grafana / Uptrace dashboard can answer "p99 of `telemetry.submit` for vendor X over the last 24h" and "submissions/min over the last 7 days."

**Rollout.** Each phase merges to main with `OTEL_SDK_DISABLED=true` in committed `.env`. The operator flips it in `.env.local` on the prod host after each phase.

**Rollback.** Set `OTEL_SDK_DISABLED=true` and clear cache. Revert by removing `src/Observability/` and the three Composer packages — there is no DB migration to undo.

## Open Questions

- **Backend choice.** Resolved — operator will provide an OTLP endpoint accepting `http/json`. Real endpoint and `Authorization` header live in `.env.local` (`OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_HEADERS`). Documentation will give Grafana Cloud / Uptrace as worked examples but does not assume either.
- **PHP-FPM availability on the target host.** Resolved — confirmed via probe against `matter-survey.org` (KAS / all-inkl.com): `php_sapi_name()=fpm-fcgi`, `function_exists('fastcgi_finish_request')=true`, PHP `8.4.16-nmm1`. The non-blocking export path is the primary path; the synchronous fallback remains as defensive coding for dev/CI/non-FPM SAPIs but is not exercised in production.
- **Sampling policy for prod.** Default 1.0 is fine while volume is small. Define a "we hit X requests/day, drop sampling to Y" trigger? Probably leave as a future tuning task.
- **Should `installation_id` be hashed before becoming a span attribute?** Resolved — do **not** hash. The value is already a random UUID with no user-identifying content, and hashing would only make it harder to correlate spans with `installations` table rows during debugging. If `installation_id` is added as a span attribute in future work, it goes on as-is.
- **Do we want a dedicated `app:otel:doctor` command in this change or a follow-up?** Lean: include in Phase 1, cheap and high-payoff for ops.
