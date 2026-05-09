# Observability

Matter Survey is instrumented with [OpenTelemetry](https://opentelemetry.io/) using the pure-PHP SDK — no PHP extension required, suitable for shared hosting. Telemetry is **off by default** and exports nothing until you flip it on.

## Quick start

1. Pick an OTLP-compatible backend that accepts `http/json`. Free-tier options:

   | Backend            | Endpoint shape                                    | Auth                                                  |
   | ------------------ | ------------------------------------------------- | ----------------------------------------------------- |
   | Grafana Cloud      | `https://otlp-gateway-<region>.grafana.net/otlp/` | `Authorization=Basic <instance:token>` (base64)       |
   | Honeycomb          | `https://api.honeycomb.io/`                       | `x-honeycomb-team=<api-key>`                          |
   | Uptrace            | `https://otlp.uptrace.dev/`                       | `uptrace-dsn=<dsn>`                                   |
   | Self-hosted Tempo  | `http://your-tempo:4318/`                         | (none, or whatever your reverse proxy enforces)       |

2. On the production host, edit `.env` (or `.env.local` if you prefer to keep secrets out of the main file):

   ```dotenv
   OTEL_SDK_DISABLED=false
   OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example/
   OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer <token>
   ```

3. Clear the Symfony cache so the new env values are picked up:

   ```bash
   php bin/console cache:clear --env=prod
   ```

4. Verify the resolved configuration:

   ```bash
   php bin/console app:otel:doctor
   ```

   Exits non-zero if anything required is missing.

5. Trigger a request (e.g. `curl https://matter-survey.org/health`) and check your backend for spans named `GET health_check`.

## What's instrumented

```
HTTP request
  └─ GET <route>           SERVER  http.request.method, http.route, status_code
      ├─ <controller logic>
      ├─ telemetry.submit  INTERNAL  schema_version, endpoint_count, ...
      │   ├─ SELECT/INSERT/UPDATE  CLIENT  db.system.name=sqlite, db.query.text
      │   └─ score.calculate INTERNAL  endpoint_count, score.value
      └─ <doctrine queries> CLIENT  ...

Console command (e.g. app:dcl:sync)
  └─ command app:dcl:sync  INTERNAL  command.name, command.exit_code
      ├─ dcl.sync          INTERNAL  vendor_count, page_count
      │   ├─ dcl.fetch_page INTERNAL page, page_size
      │   │   └─ GET        CLIENT   url.full, http.response.status_code
      │   └─ ...
      └─ <doctrine queries>

Metrics
  submissions.total           Counter,    attribute submission.schema_version
  submissions.duration_ms     Histogram,  ms wall time
  dcl.sync.runs_total         Counter,    attribute outcome (success|failure)

Logs
  Monolog → file (always)
  Monolog → OTLP logs (when OTEL_LOGS_EXPORTER=otlp on prod)
  Every Monolog record inside a span carries trace_id and span_id in `extra`.
```

## Configuration reference

All standard `OTEL_*` env vars work. The defaults shipped in `.env` are:

| Variable                          | Default                       | Purpose                                                       |
| --------------------------------- | ----------------------------- | ------------------------------------------------------------- |
| `OTEL_SDK_DISABLED`               | `true`                        | Master switch.                                                |
| `OTEL_SERVICE_NAME`               | `matter-survey`               |                                                               |
| `OTEL_SERVICE_VERSION`            | `dev`                         | Surface in `service.version` resource attribute.              |
| `OTEL_EXPORTER_OTLP_ENDPOINT`     | _unset_                       | Required when SDK is enabled.                                 |
| `OTEL_EXPORTER_OTLP_PROTOCOL`     | `http/json`                   | Pinned to JSON; protobuf would need `ext-protobuf`.           |
| `OTEL_EXPORTER_OTLP_HEADERS`      | _unset_                       | E.g. `Authorization=Bearer …`. Comma-separated `k=v` pairs.   |
| `OTEL_TRACES_EXPORTER`            | `otlp`                        | Or `none`.                                                    |
| `OTEL_METRICS_EXPORTER`           | `none`                        | Flip to `otlp` to start sending metrics.                      |
| `OTEL_LOGS_EXPORTER`              | `none`                        | Flip to `otlp` to start sending logs.                         |
| `OTEL_PHP_TRACES_PROCESSOR`       | `batch`                       | `batch` is required for non-blocking export.                  |
| `OTEL_TRACES_SAMPLER`             | `parentbased_traceidratio`    |                                                               |
| `OTEL_TRACES_SAMPLER_ARG`         | `1.0`                         | 0.0–1.0. Lower this if volume gets high.                      |
| `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE` | _unset_ (off)            | When `true`, captures bound query parameters as span attrs. _Off by default for privacy._ |

## How export works (and why it doesn't slow user requests)

1. The SDK's `BatchSpanProcessor` buffers spans in memory.
2. When the Symfony kernel reaches `kernel.terminate`, the response is finalised.
3. The `RequestTracingSubscriber` then calls `fastcgi_finish_request()` if available — under PHP-FPM (which `matter-survey.org` uses), this releases the response to the user immediately.
4. The OpenTelemetry SDK's auto-shutdown handler then runs in the still-alive FPM worker, flushing the batched spans over OTLP/HTTP.

Net effect: user-visible latency is unchanged. Export adds tens of ms _after_ the user has the response.

If FPM is unavailable (CLI, dev server, mod_php), the SDK falls back to synchronous shutdown export — still correct, just adds a small tail to perceived latency.

## Disabling

Set `OTEL_SDK_DISABLED=true` and clear the cache. All providers immediately become no-ops; no outbound traffic, no overhead.

## Troubleshooting

- **Run the doctor**: `php bin/console app:otel:doctor` — prints resolved env, registered providers, and exits non-zero on misconfiguration.
- **No spans appearing**: confirm the doctor reports `SDK disabled: no` and TracerProvider isn't `(no-op)`. Then verify outbound HTTPS to your endpoint isn't blocked.
- **Spans appear but trace_id doesn't show in logs**: the Monolog processor is registered as a service tag; restart PHP-FPM after pulling new code.
- **Errors logged about export failures**: SDK warnings go through Monolog; check `var/log/prod.log`. Use `OTEL_LOG_LEVEL=debug` to get verbose SDK logs.
