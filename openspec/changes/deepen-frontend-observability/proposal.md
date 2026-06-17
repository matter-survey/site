## Why

The site just gained a base Grafana Faro frontend setup (error capture + Core Web Vitals, prod-gated), and the backend already has rich manual OpenTelemetry instrumentation. But the two halves are disconnected: frontend telemetry isn't tagged with a release, browser activity can't be traced into the backend, there are no product-level events, and — until this change — backend traces shipped to a separate collector. We have now **converged both signals on Grafana Cloud** (backend OTLP cut over to the Grafana gateway, same region/stack as the Faro collector), which makes a single frontend↔backend trace view *possible*. This change does the work to make that view *real* and to deepen what the frontend reports: release version on every signal, true distributed tracing in both directions, and meaningful user-action/funnel events — within an explicit privacy posture.

## What Changes

- **Backend export migration (done operationally; documented here).** Re-point backend OTLP from the previous collector to the Grafana Cloud gateway (`https://otlp-gateway-prod-eu-west-0.grafana.net/otlp`, Basic auth, `http/json` verified accepted — no `ext-protobuf` needed). Service identity on prod becomes `service.name=site`, `service.namespace=matter-survey`. Update committed `.env` example comments and the CLAUDE.md observability section off the old endpoint.
- **Fix resource-attribute configuration (latent bug).** `OtelBootstrap::mergeResourceAttributes()` reads `getenv()`, which Symfony Dotenv does not populate, then overwrites `$_SERVER` — silently dropping any `OTEL_RESOURCE_ATTRIBUTES` set via `.env.local` (e.g. `service.namespace`). Read existing attributes from `$_SERVER ?? $_ENV ?? getenv()` so env-provided identity is honored.
- **Single release version, both runtimes.** Source one version identifier and feed it to both `OTEL_SERVICE_VERSION` (backend resource) and the Faro `app.version` meta, so errors/traces/events are attributable to a release. **Open decision (design.md): composer.json `version` vs. deploy-time git stamp.**
- **Frontend distributed tracing.** Add `@grafana/faro-web-tracing` (`TracingInstrumentation`) to the AssetMapper importmap; inject W3C `traceparent` on same-origin `fetch`/XHR (including Turbo's fetch-based navigations), which the backend's `RequestTracingSubscriber` already extracts.
- **Document-load correlation via `Server-Timing`.** Emit `Server-Timing: traceparent;desc="00-<traceId>-<spanId>-<flags>"` from the active server span on sampled responses, so the initial cold document load (the one request that is a true browser navigation and carries no `traceparent`) links to its backend trace.
- **Turbo-aware view context.** Update Faro's view/page context on Turbo visits and URL-advancing frame swaps so telemetry isn't all attributed to the first-loaded URL.
- **Events & user actions.** Enable Faro's native user-actions instrumentation, and add manual domain events: `search_submitted` (with query text + result count), `comparison_started`, and `wizard_step_completed` for every wizard step transition.
- **Privacy posture.** Event payloads carry only approved fields; no user identity is set and no auth/PII is captured. Capturing the search query string is an explicit, documented decision (device-search terms are low-sensitivity).

## Capabilities

### New Capabilities
- `frontend-observability`: Browser-side observability via Grafana Faro — gated initialization, release version, distributed tracing (traceparent injection + Turbo view tracking + Server-Timing document-load correlation), and product events/user actions, under a defined privacy posture. Subsumes the base Faro setup, which shipped without a spec.

### Modified Capabilities
- `observability`: add server-side `Server-Timing` trace-context exposure (the backend half of document-load correlation) and make service identity (name, namespace, version, environment) fully configurable from the environment without being clobbered at boot.

## Impact

- **Backend:** `src/Observability/Bootstrap/OtelBootstrap.php` (resource-attribute source fix; optional `service.namespace` param), a response listener for `Server-Timing` (new subscriber or extend `SecurityHeadersSubscriber`), reading the active span's trace/span id.
- **Frontend (AssetMapper):** `importmap.php` (add `@grafana/faro-web-tracing`), `assets/faro.js` (TracingInstrumentation, native user-actions, Server-Timing consumption, Turbo view hook), `assets/controllers/*` (manual events in autocomplete/search/compare controllers), wizard templates/controller for step events.
- **Version plumbing:** `composer.json` or `Makefile` deploy + a Twig global (`app_version`) and `base.html.twig` meta, plus `OTEL_SERVICE_VERSION` wiring — depends on the open A/B decision.
- **Config/docs:** committed `.env` OTel example comments, CLAUDE.md Observability section (endpoint, service identity, frontend tracing), prod `.env.local` (already updated operationally).
- **Tests:** `traceparent` injected on a sample fetch; `Server-Timing` present on a sampled response with the active trace id; resource attributes from env survive boot; event payloads exclude identity; `app:otel:doctor` stays green.
- **CLAUDE.md AEO invariants:** unaffected (no entity-page or crawler-policy changes).
