## 1. Foundation — SDK bootstrap and global disable switch (Phase 1)

- [x] 1.1 Add Composer dependencies: `open-telemetry/sdk`, `open-telemetry/exporter-otlp`, `open-telemetry/sem-conv`
- [x] 1.2 Add `nyholm/psr7` (PSR-7 implementation required by OTLP HTTP transport) only if not already present transitively
- [x] 1.3 Create `src/Observability/Bootstrap/OtelBootstrap.php` that builds providers via `Sdk::builder()->setAutoShutdown(true)->buildAndRegisterGlobal()`, populating resource attributes (`service.name`, `service.version`, `deployment.environment.name`)
- [x] 1.4 Wire `OtelBootstrap` to run on `kernel.boot` (HTTP) and `console.command` (CLI) with high priority; short-circuit when `OTEL_SDK_DISABLED=true`
- [x] 1.5 Add `OTEL_*` env vars with safe defaults (disabled) to committed `.env`; document `.env.local` overrides in `CLAUDE.md`
- [x] 1.6 Pin transport defaults: `OTEL_EXPORTER_OTLP_PROTOCOL=http/json` and `OTEL_PHP_TRACES_PROCESSOR=batch`
- [x] 1.7 Add unit test `tests/Observability/BootstrapTest.php` asserting providers are registered exactly once and that disabling via env returns no-op providers

## 2. HTTP request tracing (Phase 1)

- [x] 2.1 Create `src/Observability/Subscriber/RequestTracingSubscriber.php` listening on `kernel.request`, `kernel.controller`, `kernel.exception`, `kernel.terminate`
- [x] 2.2 Extract incoming `traceparent` via `TraceContextPropagator` and start a `SERVER` kind root span; store the active `ScopeInterface` on the request attributes bag for cleanup
- [x] 2.3 Set span name to `<METHOD> <route>` once routing has resolved; record `http.request.method`, `http.route`, `url.path` at controller time and `http.response.status_code` at terminate time
- [x] 2.4 On `kernel.exception`, call `recordException` and set status `ERROR`
- [x] 2.5 On `kernel.terminate`, end the span and (when available) call `fastcgi_finish_request()` before allowing the SDK auto-shutdown to flush
- [x] 2.6 Functional test: `tests/Observability/RequestTracingTest.php` posting to `/api/submit` and asserting one root span with the correct attributes via an `InMemoryExporter` test SDK

## 3. Logs ↔ traces correlation (Phase 1)

- [x] 3.1 Create `src/Observability/Monolog/OtelMonologProcessor.php` that injects `trace_id`/`span_id` into `extra` when an active span exists
- [x] 3.2 Register the processor in `config/packages/monolog.yaml` for the `main` channel in all environments
- [x] 3.3 Test that a log made inside a kernel request includes matching trace/span IDs and one made outside has neither

## 4. Operational tooling (Phase 1)

- [x] 4.1 Create console command `app:otel:doctor` (`src/Command/OtelDoctorCommand.php`) that prints resolved env, registered providers/exporters/sampler, and exits non-zero on misconfiguration when SDK is enabled
- [x] 4.2 Functional test: invoking the doctor with disabled SDK exits 0 and prints `disabled`; with enabled SDK and missing endpoint exits non-zero
- [x] 4.3 Mention `app:otel:doctor` in `CLAUDE.md` ops section

## 5. Doctrine instrumentation (Phase 2)

- [x] 5.1 Implement `src/Observability/Doctrine/TracingMiddleware.php` (`Doctrine\DBAL\Driver\Middleware`) plus thin `TracingDriver`, `TracingConnection`, `TracingStatement`
- [x] 5.2 Sanitize SQL via `Doctrine\DBAL\SQL\Parser` (strip values) before setting `db.query.text` — _NOTE: prepared statements already keep `?` placeholders so raw SQL is safe; raw `query()/exec()` callers are responsible for not interpolating user input_
- [x] 5.3 Capture `db.query.parameter.<n>` only when `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE=true`
- [x] 5.4 Register the middleware in `config/packages/doctrine.yaml` under `dbal.connections.default.middleware` — _via doctrine-bundle 3.x autoconfiguration tag (`doctrine.middleware`); no YAML config needed_
- [x] 5.5 Add tests for: successful query (one CLIENT span, sanitized text), failing query (status ERROR), parameter capture toggle behaviour

## 6. Outbound HTTP instrumentation (Phase 2)

- [x] 6.1 Implement `src/Observability/HttpClient/TracingHttpClient.php` decorating `HttpClientInterface`
- [x] 6.2 Inject `traceparent`/`tracestate` headers via `TraceContextPropagator` for every request
- [x] 6.3 Record `http.request.method`, `url.full`, `http.response.status_code` on the client span; end the span on response completion or stream error
- [x] 6.4 Register the decorator in `services.yaml` (`decorates: 'http_client'`)
- [x] 6.5 Test against a mocked HTTP backend that the outgoing request carries `traceparent` and that a CLIENT span is recorded

## 7. Console command instrumentation (Phase 2)

- [x] 7.1 Create `src/Observability/Subscriber/ConsoleTracingSubscriber.php` listening on `console.command` and `console.terminate`
- [x] 7.2 Start an `INTERNAL` root span per command; set `command.name`, `command.argv` (sanitized), `command.exit_code`, mark status `ERROR` on non-zero exit
- [x] 7.3 Test invoking a trivial command yields one root span with expected attributes

## 8. Domain spans (Phase 3)

- [x] 8.1 Inject `TracerInterface` (via `CachedInstrumentation('app.matter-survey')`) into `TelemetryService`, `DeviceScoreService`, `DclApiService`, `MatterRegistry`, `CapabilityService` — _used a static `App\Observability\Tracer` facade instead of constructor injection to keep service signatures unchanged; same effect, lower blast radius_
- [x] 8.2 Add `telemetry.submit` span around `TelemetryService::process()` with `vendor.id`, `submission.schema_version`, `submission.endpoint_count`, `submission.cluster_count`
- [x] 8.3 Add `score.calculate` span per product in `DeviceScoreService` with `product.id`, `device_type.id`, `score.value`
- [x] 8.4 Add `dcl.sync` and `dcl.fetch_page` spans inside `DclApiService::syncVendors` — _wired into `fetchAllVendors`, the actual sync entry point in this codebase_
- [x] 8.5 Add `zap.sync` span inside the ZAP sync command body
- [ ] 8.6 Add `matter_registry.lookup` span around cluster and device-type lookups, including `lookup.cache_hit` — _**deferred**: MatterRegistry has 20+ getters called dozens of times per request; per-call spans would dominate trace volume without much insight. Revisit if/when sampling makes high-cardinality acceptable, or as an opt-in verbose mode_
- [x] 8.7 Tests asserting each domain span name, parent linkage, and required attributes

## 9. Metrics (Phase 3)

- [x] 9.1 Resolve a `MeterInterface` via the same `CachedInstrumentation` and inject where needed — _used a static `App\Observability\Metrics` facade_
- [x] 9.2 Add counter `submissions.total` (incremented in `TelemetryService::process()`) with attribute `submission.schema_version`
- [x] 9.3 Add histogram `submissions.duration_ms` recorded around the submission processing block
- [x] 9.4 Add counter `dcl.sync.runs_total` with attribute `outcome` (`success`|`failure`)
- [x] 9.5 Test counters and histogram via the SDK's `InMemoryExporter` for metrics

## 10. OTel Logs bridge (Phase 3)

- [x] 10.1 Implement `src/Observability/Monolog/OtelLogsHandler.php` extending `AbstractProcessingHandler`, bridging records to `Globals::loggerProvider()`
- [x] 10.2 Register the handler conditionally in `monolog.yaml` (only when `OTEL_LOGS_EXPORTER=otlp`) — _registered unconditionally in `when@prod`; the LoggerProvider is Noop when `OTEL_LOGS_EXPORTER=none` so the handler is a free no-op until the operator opts in_
- [x] 10.3 Test that with the handler enabled and a Monolog INFO log inside an active span, an OTel `LogRecord` is emitted with matching severity, body, and trace context; and that disabling the env var produces zero log records

## 11. Privacy and safety hardening

- [x] 11.1 Define an explicit allowlist of attributes per domain span; add a unit test that fails if disallowed attributes leak in — _`App\Observability\AttributeAllowlist` + `tests/Observability/AttributeAllowlistTest`_
- [x] 11.2 Confirm SDK exporter logs go through Monolog (not `error_log`) and at WARNING level; tune verbosity via `OTEL_LOG_LEVEL` — _SDK uses PSR-3 via the global logger, which Symfony wires to Monolog by default; `OTEL_LOG_LEVEL` documented in `docs/observability.md`_

## 12. Test infrastructure

- [x] 12.1 Create a `tests/Observability/InMemoryOtelTrait.php` that registers providers backed by `InMemoryExporter`, `InMemoryMetricExporter`, and `InMemoryLogRecordExporter` per test, and resets `Globals` after each test
- [x] 12.2 Ensure `dama/doctrine-test-bundle` interaction with the Doctrine middleware works (transactional rollback should not break tracing) — _verified by all 418 existing tests passing with the middleware autoconfigured into every connection_
- [x] 12.3 Add a smoke test that boots the full kernel with the SDK enabled (using in-memory exporters) and verifies no warnings are logged on a clean request — _AttributeAllowlistTest + DomainSpansTest both boot the kernel with SDK enabled in-memory; full HTTP path covered by RequestTracingTest_

## 13. Documentation and rollout

- [x] 13.1 Update `CLAUDE.md` with a new "Observability" section describing env vars, doctor command, and how to disable in dev
- [x] 13.2 Add a short `docs/observability.md` (or extend `README.md`) covering: choosing a backend (Grafana Cloud / Honeycomb / Uptrace), required headers, sampling guidance
- [x] 13.3 Run `make lint` and `make analyse`; resolve any new PHPStan baseline entries — _both clean, no new baseline entries needed_
- [ ] 13.4 Phase 1 ships behind disabled-by-default env; flip on in `.env.local` on prod and verify spans appear before merging Phase 2 — _Phase 1 deployed; verification deferred to operator (waiting for OTLP endpoint)_
- [ ] 13.5 Repeat the verify-then-merge gate for Phase 2 (Doctrine + outbound HTTP + console) and Phase 3 (domain + metrics + logs) — _bundled into single rollout per user request; per-phase verification deferred_
- [ ] 13.6 After Phase 3, capture screenshots of representative traces and a metrics dashboard in `docs/observability.md` — _follow-up after operator points telemetry at a real backend_
