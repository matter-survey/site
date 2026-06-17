# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

```bash
# Install dependencies
composer install

# Run local development server
php -S localhost:8000 -t public public/router.php
# Or use make
make dev

# Run tests
php bin/phpunit
php bin/phpunit --filter ApiController  # Run specific test class
php bin/phpunit --filter testMethodName  # Run specific test method

# Linting & Static Analysis
make lint                    # Check code style (php-cs-fixer dry-run)
make lint-fix                # Fix code style issues
make analyse                 # Run PHPStan (level 7)
make rector                  # Check for pending Rector refactors (dry-run)
make rector-fix              # Apply Rector refactors

# Clear cache
php bin/console cache:clear

# Deploy to production (requires .env.local with SFTP credentials)
make deploy
```

**Important:** Always run `make lint`, `make analyse`, and `make rector` before committing.

A pre-commit hook script lives at `.githooks/pre-commit`. Enable it once per clone with `make install-hooks` (sets `core.hooksPath` to the committed directory). It runs the same quality gates CI does — lint, PHPStan, Rector dry-run — and blocks the commit if any fail. Skip with `git commit --no-verify` if you must, but the CI check will then fail.

### Dev tooling layout

Dev-only tools that would pollute the main app's `composer.json` (and its lockfile resolution) with transitive dependencies are installed under `tools/<tool>/` with their own composer manifest and committed lockfile:

```
tools/
  rector/
    composer.json   # require-dev: rector/rector
    composer.lock   # committed
    .gitignore      # vendor/ (not committed)
```

The `make rector` and `make rector-fix` targets run `composer install -d tools/rector` before invoking the binary at `tools/rector/vendor/bin/rector`. PHPStan and php-cs-fixer remain in the root `require-dev` for now and can migrate independently if conflicts or upgrade friction emerge.

The SQLite database schema is managed via Doctrine Migrations. Run migrations with:

```bash
php bin/console doctrine:migrations:migrate
```

## Architecture

**Framework:** Symfony 7.4 with MicroKernel (PHP 8.5)

**Request Flow:** `public/index.php` → `Kernel` → Controllers (attribute-based routing)

### Key Components

- **ApiController** (`POST /api/submit`) - Telemetry submission with rate limiting and validation via DTOs
- **HealthController** (`GET /health`) - Health check endpoint with database connectivity verification
- **DeviceController** - Device browser with pagination and search (`/`, `/device/{id}`)
- **VendorController** - Vendor pages (`/vendor/{id}`)
- **StatsController** - Statistics pages for clusters and device types
- **TelemetryService** - Processes submissions, logs via `LoggerInterface`
- **DeviceRepository** - Data access for devices, versions, and endpoints
- **MatterRegistry** - Lookup for Matter cluster/device type names, metadata, commands, attributes (database-backed)
- **CapabilityService** - Analyzes device endpoints and maps clusters to human-friendly capabilities
- **DeviceScoreService** - Calculates device compliance scores based on cluster implementation
- **DclApiService** - Interacts with the Matter DCL (Distributed Compliance Ledger) API
- **DatabaseService** - Centralized database connection management

### Console Commands

- `app:dcl:sync` - Fetch vendor/product data from Matter DCL API and generate YAML fixtures
- `app:zap:backfill` - Snapshot Matter cluster spec per release tag into `fixtures/clusters/{1.0..1.5,master}.yaml`. Run `--matter-version=master` daily (scheduled in CI) to keep master fresh; the released-version tags are frozen.
- `app:scores:rebuild` - Rebuild the device scores cache table (used in deployment)
- `app:otel:doctor` - Print resolved OpenTelemetry configuration (env vars, providers, sampler) and exit non-zero on misconfiguration when the SDK is enabled
- `app:user:create` - Create an admin user (form-login credential for the `/admin` area)
- `app:api-token:create` / `app:api-token:list` / `app:api-token:revoke` - Manage stateless API tokens used by the `/api` firewall

### Authentication & Authorization

Two firewalls are configured in `config/packages/security.yaml`:

- **`/api`** - stateless, token-based. `App\Security\ApiTokenAuthenticator` validates a bearer token against the `ApiToken` entity. Mint/list/revoke tokens with the `app:api-token:*` commands.
- **Main (`/admin`)** - session-based form login. `^/admin` requires `ROLE_ADMIN`; successful login redirects to `admin_dashboard`. Admin UI lives in `src/Controller/Admin/`. Create users with `app:user:create`.

`SecurityHeadersSubscriber` (in `src/EventSubscriber/`) injects security headers on responses.

### Internationalization

The site is bilingual (English/German). `LocaleSubscriber` resolves the request locale; translations live in `translations/` split by domain (`messages`, `navigation`, `wizard`, `faq`, `glossary`) with `.en`/`.de` variants. User-facing strings belong in these catalogs, not hard-coded in templates.

### Matter Registry Data

All Matter specification data (clusters, device types) is stored in the database and loaded from YAML fixtures:

- `fixtures/clusters.yaml` - 50+ cluster definitions with names, descriptions, commands, attributes, features
- `fixtures/device_types.yaml` - 65+ device type definitions with cluster requirements
- `fixtures/capabilities.yaml` - Human-friendly capability definitions mapping clusters to user-facing features

**Entities:**
- `Cluster` - id, hexId, name, description, category, isGlobal. Hand-curated annotation layer only — spec data (attributes/commands/features/apiMaturity/ClusterRevision) lives on `ClusterVersion`.
- `ClusterVersion` - (clusterId, matterVersion) → name, description, clusterRevision, apiMaturity, attributes (JSON), commands (JSON), features (JSON). One row per Matter release (1.0..1.5 + master) the cluster appeared in.
- `DeviceType` - id, hexId, name, description, specVersion, category, displayCategory, deviceClass, scope, superset, icon, mandatoryServerClusters (JSON), optionalServerClusters (JSON), mandatoryClientClusters (JSON), optionalClientClusters (JSON), scoringWeights (JSON)

**Fixture Groups:**
- `clusters` - Load only cluster data
- `device_types` - Load only device type data
- `matter` - Load both clusters and device types (used in deploy)

To add new Matter spec data, edit the YAML fixtures and redeploy. Data is loaded via `doctrine:fixtures:load --group=matter --append`.

### Validation

API submissions are validated using Symfony Validator with DTOs:

- `src/Dto/TelemetrySubmission.php` - UUID validation for installation_id
- `src/Dto/TelemetryDevice.php` - Field type and length constraints

### Data Model

**Doctrine Entities:**
- `Product` - Main device entity (vendor_id, product_id, slug, vendor_name, product_name)
- `Vendor` - Vendor information and device counts
- `Cluster` - Hand-curated annotation layer (name, description, category, isGlobal)
- `ClusterVersion` - Per-Matter-version spec snapshots (attributes/commands/features JSON)
- `DeviceType` - Matter device type definitions with cluster requirements (JSON)

**Raw SQL Tables (accessed via DeviceRepository):**
```
products (vendor_id, product_id)
  ├─ device_versions (hardware/software versions)
  ├─ device_endpoints (clusters JSON, device_types JSON)
  └─ installations / installation_products (UUID deduplication)
```

Database views: `product_summary` (aliased as `device_summary`), `cluster_stats`

## Configuration

- **Database path:** `data/matter-survey.db` (configured in `services.yaml`)
- **Rate limiting:** Sliding window, 10/min per IP (configured in `framework.yaml`)
- **CORS:** Configured in `nelmio_cors.yaml` for API endpoints
- **Logging:** Monolog configured in `monolog.yaml`, logs to `var/log/`

## Observability (OpenTelemetry)

Manual instrumentation via the pure-PHP OpenTelemetry SDK. No PHP extension required.

- **Default state:** disabled. `.env` ships `OTEL_SDK_DISABLED=true`.
- **Enable in prod:** set in `.env.local` (prod exports to the Grafana Cloud OTLP gateway, same stack as the Faro collector, so frontend and backend traces share one Tempo):
  ```
  OTEL_SDK_DISABLED=false
  OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp-gateway-<zone>.grafana.net/otlp
  OTEL_EXPORTER_OTLP_HEADERS=Authorization=Basic%20<base64(instanceID:token)>
  ```
  Grafana Cloud uses **Basic** auth (base64 of `instanceID:token`), percent-encoded — the OTLP exporter `rawurldecode`s header values.
- **Transport pinned to** `http/json` (avoids `ext-protobuf`/`ext-grpc`; verified accepted by the Grafana gateway).
- **Non-blocking export:** spans are batched and flushed via `fastcgi_finish_request()` after the response is sent (PHP-FPM only; falls back to synchronous shutdown export on other SAPIs).
- **Verify config:** `php bin/console app:otel:doctor` prints resolved env, active providers, and exits non-zero on misconfiguration.
- **Service identity:** `service.name`/`service.namespace`/`service.version`/`deployment.environment.name` come from the environment. `OtelBootstrap::mergeResourceAttributes()` reads existing attrs from `$_SERVER`/`$_ENV` (not just `getenv()`, which Symfony Dotenv leaves empty), so operator-set `OTEL_RESOURCE_ATTRIBUTES` (e.g. `service.namespace`) survive boot.
- **OTLP log level:** `OtelLogsHandler` enforces an INFO+ minimum in its constructor — MonologBundle ignores `level:` for `type: service` handlers, so the threshold can't live in `monolog.yaml`.
- **Document-load correlation:** `ServerTimingSubscriber` adds `Server-Timing: traceparent;…` on sampled responses so the browser can link the initial page load to its backend trace.

### Frontend Observability (Grafana Faro)

Browser instrumentation via the Faro Web SDK, vendored into the AssetMapper importmap (`@grafana/faro-web-sdk` + `@grafana/faro-web-tracing`). Init lives in `assets/faro.js` (first import in `assets/app.js`).

- **Gating:** Faro initializes only when the server renders a `<meta name="faro-collector-url">` tag, emitted from `FARO_COLLECTOR_URL` (prod `.env.local`). Inert in dev/test.
- **Distributed tracing:** `TracingInstrumentation` injects W3C `traceparent` on same-origin fetch/XHR (incl. Turbo's fetch-based navigations); the backend `RequestTracingSubscriber` continues the trace. Frontend and backend traces share one Grafana Cloud Tempo.
- **Turbo views:** `assets/faro.js` calls `faro.api.setView()` on `turbo:load` so telemetry isn't all attributed to the first-loaded URL.
- **Version:** `app.version` and backend `service.version` share one source — `config/version.php`, stamped at deploy (`git describe`, see the Makefile) and exposed via the `app.version` container parameter (`config/packages/app_version.php`). Absent in dev → `dev`.
- **Events:** domain events go through `trackEvent()` in `assets/observability.js` (`faro.api.pushEvent`, no-op until init). **Privacy:** never set a user identity; pass only allowlisted, non-PII fields. Search query text is intentionally captured (device-search input, not personal data).

## Environment Files

- `.env` - Committed, safe defaults for development
- `.env.test` - Committed, test environment settings
- `.env.local` - Not committed, local overrides and secrets (SFTP credentials, real APP_SECRET)

## CI/CD

Single workflow in `.github/workflows/ci.yml`:

- `test` job: PHPUnit on PHP 8.5
- `code-quality` job: composer validate, security audit, php-cs-fixer, Rector (dry-run), PHPStan analysis
- `deploy` job: runs after test/code-quality pass, only on main branch push

PHPStan is configured at level 7 with a baseline (`phpstan-baseline.neon`) for existing issues.

## Structured Data & SEO

All public-facing pages should include structured data markup:

- **Schema.org JSON-LD** - For search engine rich snippets and knowledge graphs
- **OpenGraph meta tags** - For social media link previews (Facebook, Slack, Discord)
- **Twitter Cards** - For Twitter/X previews

### Entity Mappings

| Entity | Schema.org Type | Template |
|--------|-----------------|----------|
| Product (device) | `Product` | `device/show.html.twig` |
| Vendor | `Organization` | `vendor/show.html.twig` |
| DeviceType | `DefinedTerm` | `stats/device_type_show.html.twig` |
| Homepage | `WebSite` + `SearchAction` | `device/index.html.twig` |

When creating new pages with entities, include appropriate JSON-LD in the `structured_data` block and OpenGraph tags in the `og_meta` block.

### AEO Lede, JSON-LD, and Crawler Policy

The site optimizes for AI-agent citation (AEO/GEO) on top of classic SEO. The
core invariants:

- **Lede:** Every public entity page (`device/show`, `vendor/show`,
  `stats/cluster_show`, `stats/device_type_show`) renders a one-to-two
  sentence definitional lede in a `<p class="aeo-lede">` immediately after
  the visual breadcrumb. The lede is generated by `App\Service\AeoLedeService`
  and must equal the JSON-LD `description` field byte-for-byte. To add a new
  entity type, extend `AeoLedeService` and expose a matching Twig function in
  `App\Twig\AeoExtension`.
- **Structured data:** `App\Service\StructuredDataService` is the single
  source of truth for entity JSON-LD payloads. Templates render via
  `structured_data_*` Twig functions, never inline literals. New shared
  fields (e.g. `dateModified`, `BreadcrumbList`) are added in one place.
- **Dataset markup:** All aggregate stats pages (`stats/dashboard`,
  `stats/clusters`, `stats/device_types`, `stats/binding`, `stats/pairings`,
  `stats/commissioning`, `stats/market`, `stats/versions`) emit `Dataset`
  JSON-LD via the `stats/_dataset_jsonld.html.twig` partial. The license is
  CC0 (`StructuredDataService::LICENSE_CC0`); revisit if data licensing
  changes.
- **Crawler policy:** `public/robots.txt` enumerates named AI crawler
  user-agents (`GPTBot`, `OAI-SearchBot`, `ChatGPT-User`, `ClaudeBot`,
  `Claude-SearchBot`, `Claude-User`, `PerplexityBot`, `Perplexity-User`,
  `Google-Extended`, `Applebot`, `Applebot-Extended`, `Meta-ExternalAgent`)
  with explicit `Allow: /`, plus a `User-agent: *` fallback. The header
  comment carries a `# Last reviewed:` date — update it when changing the
  policy. This is a public-good Matter registry, so retrieval AND training
  crawlers are intentionally permitted.

## Spec-Driven Changes (OpenSpec)

Larger features are planned as OpenSpec changes under `openspec/changes/<name>/`
(proposal, design, tasks, and per-capability specs). The canonical capability
specs live under `openspec/specs/`. Use the `openspec-*` / `opsx:*` skills to
propose, apply, and archive changes; consult an in-progress change's `tasks.md`
before implementing related work.

## Commit Guidelines

Use semantic commits (e.g., `feat:`, `fix:`, `ci:`, `docs:`, `refactor:`, `chore:`).

## Testing Patterns

Tests use Symfony's WebTestCase with an in-memory test database. Key patterns:

- Controller tests navigate from index to detail pages using crawler
- Service tests use the real MatterRegistry with fixture data
- V3 telemetry tests include `server_cluster_details` with `feature_map`, `accepted_command_list`, `attribute_list`

Example test endpoint data structure:
```php
$endpoints = [
    [
        'endpoint_id' => 1,
        'device_types' => [256],
        'server_clusters' => [6, 29],
        'client_clusters' => [],
        'server_cluster_details' => [
            ['id' => 6, 'feature_map' => 0, 'accepted_command_list' => [0, 1, 2], 'attribute_list' => [0]],
        ],
    ],
];
```
