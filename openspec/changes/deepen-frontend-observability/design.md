## Context

The backend has mature manual OpenTelemetry instrumentation (root server span per request with W3C context **extraction** in `RequestTracingSubscriber`, outbound `traceparent` **injection** in `TracingHttpClient`, Doctrine/console/domain spans, Monolog correlation). The frontend just gained a base Grafana Faro setup (errors + Core Web Vitals, prod-gated, vendored into the AssetMapper importmap). Backend OTLP export has been operationally migrated to the Grafana Cloud gateway in the same region as the Faro collector, so both signals can share one Tempo.

Constraints that shape the design:

- **Shared host, no PHP extensions.** Transport stays `http/json` (verified accepted by the Grafana OTLP gateway — `HTTP 200`), avoiding `ext-protobuf`/`ext-grpc`.
- **AssetMapper, not npm/webpack.** Frontend deps are vendored via `importmap:require`; the deploy runs `importmap:install` + `asset-map:compile`. App code is not minified (only the vendored SDK bundle is).
- **Turbo app.** `data-turbo-frame` / `data-turbo-action="advance"` means most "navigations" are `fetch()` under the hood; the document is loaded once and persists.
- **Deploy strips `.git`** (rsync exclude) and runs `composer install` on the server, so Composer cannot derive the commit SHA in prod.
- **Symfony Dotenv populates `$_SERVER`/`$_ENV` but not `getenv()`** — relevant to resource-attribute handling.
- **Privacy.** The site advertises a privacy note; telemetry must not carry user identity or auth data.

## Goals / Non-Goals

**Goals:**
- A single frontend↔backend trace view in Grafana Cloud, covering both AJAX/Turbo navigations and the initial cold document load.
- Every frontend signal (error, event, trace) tagged with a release version that matches the backend `service.version`.
- Product-meaningful events: search, comparison, wizard funnel.
- Correct, env-configurable service identity (`service.name=site`, `service.namespace=matter-survey`).

**Non-Goals:**
- **No user-identity tracking.** The public site is anonymous; admins aside, no `setUser`. Keeps PII surface minimal.
- **No source-map upload** this round — AssetMapper doesn't minify app code, so stack traces are already mostly readable; revisit if that changes.
- **No change to backend span structure, sampling strategy, or telemetry submission format.**
- **No second analytics vendor** — Faro/Grafana only.
- **No permanent dual-export** to the previous collector; the cutover is a straight swap with a documented rollback.

## Decisions

### Decision 1: Converge all signals on Grafana Cloud (resolved)
Backend OTLP traces/metrics/logs now export to `https://otlp-gateway-prod-eu-west-0.grafana.net/otlp` (Basic auth, `http/json`), the same stack/region as the Faro collector. Frontend Faro traces and backend OTLP traces share one Tempo, so a propagated `trace_id` yields a unified waterfall.

- **Why:** A single trace store is the only way to get one waterfall; correlating across two backends by `trace_id` alone means two tabs.
- **Verified:** the gateway returns `HTTP 200` for an `http/json` span, so the no-extension constraint holds.
- **Rollback:** `cp .env.local.pre-grafana .env.local` on the server.

### Decision 2: Two-directional trace correlation
Correlation needs both directions because the browser cannot inject `traceparent` on a top-level navigation.

```
fetch / XHR / Turbo navigation  →  traceparent REQUEST header  (browser → backend; backend already extracts)
initial cold document load      →  Server-Timing RESPONSE header (backend → browser; backend exposes its trace id)
```

- Add `@grafana/faro-web-tracing` `TracingInstrumentation` for same-origin `traceparent` injection. Because Turbo navigations are `fetch()`, they are covered automatically; only the first/full document load is a true navigation.
- The backend emits `Server-Timing: traceparent;desc="00-<traceId>-<spanId>-<flags>"` from the active server span, **only on sampled requests**, so Faro can link the cold load.
- **Verify in implementation:** whether Faro's `TracingInstrumentation` auto-consumes `Server-Timing` to create the linked navigation span, or whether a small reader over `performance.getEntriesByType('navigation')[0].serverTiming` is needed.
- **Same-origin**, so no `Timing-Allow-Origin` needed. **Caveat:** don't emit a stale trace id on HTTP-cached responses (low risk — pages are dynamic).

### Decision 3: Single release version, both runtimes — OPEN (A vs B)
One version identifier must feed both `OTEL_SERVICE_VERSION` (backend) and the Faro `app.version` meta. The deploy strips `.git`, so Composer can't auto-derive the SHA in prod. Two viable single-source options:

| Option | Mechanism | Pros | Cons |
| --- | --- | --- | --- |
| **A. composer.json `version`** | add `"version"`, read via `Composer\InstalledVersions` → meta + `OTEL_SERVICE_VERSION` | one static source, no deploy plumbing | manual bump; CI `composer validate` may warn on root `version` |
| **B. deploy-time git stamp** (recommended) | `make deploy`: `git describe --tags --always` → generated `config/version.php` (read by Twig + OTel) and `OTEL_SERVICE_VERSION` | auto per-deploy, real ref | small Makefile plumbing |

- **Recommendation: B** — accurate per-deploy versioning with no manual step. **This is the one open decision for the user to confirm before implementation.**

### Decision 4: Fix env-sourced resource attributes; set service identity
`OtelBootstrap::mergeResourceAttributes()` reads `getenv('OTEL_RESOURCE_ATTRIBUTES')`, which Symfony Dotenv leaves empty, then overwrites `$_SERVER` — silently dropping any `.env.local`-provided attribute (confirmed: `service.namespace=matter-survey` set on prod is dropped; `cache:clear` does not help).

- **Fix:** read existing attributes from `$_SERVER[...] ?? $_ENV[...] ?? getenv(...)` before merging, preserving env-provided keys. Apply the same source precedence consistently with how the OTel SDK itself reads config (`$_SERVER`).
- Prod identity: `service.name=site`, `service.namespace=matter-survey` (both via `.env.local`; name already works through the DI param path, namespace works once the fix ships).
- **Why a real fix, not a workaround:** this affects *any* operator-set resource attribute, not just namespace.

### Decision 5: Capture search query text (privacy posture)
`search_submitted` events include the typed query string plus `result_count` and `surface` (autocomplete vs. list filter).

- **Why:** device-search terms are overwhelmingly product/vendor names — low sensitivity — and "what do people search for / what returns zero results" is the highest-value product signal here.
- **Guardrails:** no user identity is ever set; events carry only an allowlist of fields; nothing from auth/session/PII. Documented as a deliberate decision so it isn't silently broadened later.

### Decision 6: User actions — native + manual funnel
Enable Faro's built-in user-actions instrumentation (auto-groups click → fetch → error) **and** add manual domain events the DOM can't infer.

- Manual events: `search_submitted` (Decision 5), `comparison_started` (with selected count), `wizard_step_completed` (Decision 7).
- **Why both:** native gives interaction hygiene for free; manual gives funnel semantics.

### Decision 7: Wizard — event on every step transition
The find-your-gear wizard emits `wizard_step_completed { step, name }` on each step entered/completed, plus a terminal completion, yielding a full drop-off funnel.

- **Why:** start/finish-only tells conversion but not where users abandon; full transitions are cheap to emit here.

### Decision 8: Frontend deps via the vendored importmap (consistent with base)
`@grafana/faro-web-tracing` is added with `importmap:require` and self-hosted under `assets/vendor/` (gitignored; fetched by `importmap:install` at deploy), exactly like the base `@grafana/faro-web-sdk`.

## Open Questions / Risks

- **Version A vs B** — the one decision blocking the version slice (Decision 3).
- **Faro Server-Timing consumption** — auto vs. small reader (Decision 2); verify against faro-web-tracing behavior during implementation.
- **Turbo view double-counting** — ensure the view hook fires once per visit, not per frame render, to avoid inflated view counts.
- **PII in error payloads** — exception messages could theoretically contain user input; rely on Faro's defaults and the no-identity stance; revisit if noisy.
- **`http/json` longevity** — verified today; if the gateway ever requires protobuf, the no-extension constraint would force a rethink (out of scope, noted).
