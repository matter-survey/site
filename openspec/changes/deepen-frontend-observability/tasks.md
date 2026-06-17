## 1. Backend export migration (Grafana Cloud)

- [x] 1.1 Re-point prod `OTEL_EXPORTER_OTLP_ENDPOINT` to the Grafana Cloud gateway and switch auth to Basic in `.env.local` (backup `.env.local.pre-grafana`)
- [x] 1.2 Verify `http/json` is accepted by the gateway (`HTTP 200` probe) — no `ext-protobuf` needed
- [x] 1.3 Verify resolved config with `app:otel:doctor` on prod (exit 0)
- [ ] 1.4 Update committed `.env` OTel example comments off the previous collector; update CLAUDE.md Observability section (endpoint, Basic auth, `http/json`, service identity)
- [ ] 1.5 Confirm traces/metrics/logs are arriving in Grafana Cloud (Tempo/Mimir/Loki) for service `site`

## 2. Service identity & resource-attribute fix

- [x] 2.1 Set prod `OTEL_SERVICE_NAME=site` and `OTEL_RESOURCE_ATTRIBUTES=service.namespace=matter-survey` in `.env.local`
- [ ] 2.2 Fix `OtelBootstrap::mergeResourceAttributes()` to read existing attributes from `$_SERVER ?? $_ENV ?? getenv()` so env-provided attributes (e.g. `service.namespace`) are preserved, not clobbered
- [ ] 2.3 (Optional) Add a `service.namespace` constructor param/binding mirroring `service.name`/`service.version`, for parity
- [ ] 2.4 Test: an `OTEL_RESOURCE_ATTRIBUTES` value set via env survives boot and appears in the resource; `app:otel:doctor` shows `service.namespace=matter-survey` on prod after deploy

## 3. Release version (single source — pending A/B decision)

- [x] 3.1 Decide version source — **B: deploy-time git stamp** (design.md Decision 3)
- [ ] 3.2 `make deploy` computes `git describe --tags --always` and writes a generated `config/version.php` (rsynced up)
- [ ] 3.3 Feed it to backend `OTEL_SERVICE_VERSION` (replace the hardcoded `dev`) from the same value
- [ ] 3.4 Expose it to Twig (`app_version` global) and render `<meta name="faro-app-version">`; set Faro `app.version` from it in `assets/faro.js`
- [ ] 3.5 Verify backend `service.version` and frontend `app.version` report the same value for a given release

## 4. Frontend distributed tracing

- [ ] 4.1 `importmap:require @grafana/faro-web-tracing` (vendored, like the base SDK)
- [ ] 4.2 Add `TracingInstrumentation` to `initializeFaro` in `assets/faro.js`
- [ ] 4.3 Confirm same-origin `traceparent` is injected on `/api/*` fetch and on Turbo navigation/frame fetches; confirm backend `RequestTracingSubscriber` continues the trace
- [ ] 4.4 Configure `propagateTraceHeaderCorsUrls` only if any traced call is cross-origin (none expected)
- [ ] 4.5 Test: a sample fetch carries a `traceparent` header

## 5. Document-load correlation (Server-Timing)

- [ ] 5.1 Add a response listener (new subscriber or extend `SecurityHeadersSubscriber`) that, on sampled requests, sets `Server-Timing: traceparent;desc="00-<traceId>-<spanId>-<flags>"` from the active server span
- [ ] 5.2 Emit only when the request is sampled/traced; never on a request without an active recorded span
- [ ] 5.3 Verify Faro links the initial document load to the backend trace; add a small navigation-span reader only if Faro doesn't auto-consume `Server-Timing`
- [ ] 5.4 Test: a sampled response includes `Server-Timing` with the active span's trace id; unsampled requests omit it

## 6. Turbo-aware view context

- [ ] 6.1 Update Faro's view/page context on `turbo:load` (and URL-advancing frame renders), once per visit
- [ ] 6.2 Verify telemetry after several Turbo navigations is attributed to the current URL, not the first-loaded one, without double-counting views

## 7. Events & user actions

- [ ] 7.1 Enable Faro native user-actions instrumentation
- [ ] 7.2 `search_submitted` with `query`, `result_count`, `surface` in the autocomplete/search controllers (Decision 5)
- [ ] 7.3 `comparison_started` (with selected count) in the compare controllers
- [ ] 7.4 `wizard_step_completed { step, name }` on every wizard step transition, plus terminal completion
- [ ] 7.5 Privacy guardrail: events carry only allowlisted fields; assert no user identity / auth / PII is set
- [ ] 7.6 Test: event payloads contain the expected keys and exclude identity

## 8. Docs & verification

- [ ] 8.1 Add a CLAUDE.md "Frontend Observability (Faro)" subsection (gating, tracing, events, privacy posture)
- [ ] 8.2 End-to-end check: trigger a browser action in prod and confirm a single FE↔BE waterfall in Grafana Tempo
- [ ] 8.3 Confirm `app:otel:doctor` stays green and lint/PHPStan/Rector pass
