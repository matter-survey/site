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
make analyse                 # Run PHPStan (level 6)

# Clear cache
php bin/console cache:clear

# Deploy to production (requires .env.local with SFTP credentials)
make deploy
```

**Important:** Always run `make lint` and `make analyse` before committing.

The SQLite database schema is managed via Doctrine Migrations. Run migrations with:

```bash
php bin/console doctrine:migrations:migrate
```

## Architecture

**Framework:** Symfony 7.3 with MicroKernel (PHP 8.4)

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
- `app:zap:sync` - Fetch cluster details from Matter SDK ZAP XML files and update fixtures
- `app:scores:rebuild` - Rebuild the device scores cache table (used in deployment)

### Matter Registry Data

All Matter specification data (clusters, device types) is stored in the database and loaded from YAML fixtures:

- `fixtures/clusters.yaml` - 50+ cluster definitions with names, descriptions, commands, attributes, features
- `fixtures/device_types.yaml` - 65+ device type definitions with cluster requirements
- `fixtures/capabilities.yaml` - Human-friendly capability definitions mapping clusters to user-facing features

**Entities:**
- `Cluster` - id, hexId, name, description, specVersion, category, isGlobal, attributes (JSON), commands (JSON), features (JSON)
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
- `Cluster` - Matter cluster definitions with attributes, commands, features (JSON)
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

## Environment Files

- `.env` - Committed, safe defaults for development
- `.env.test` - Committed, test environment settings
- `.env.local` - Not committed, local overrides and secrets (SFTP credentials, real APP_SECRET)

## CI/CD

Single workflow in `.github/workflows/ci.yml`:

- `test` job: PHPUnit on PHP 8.4
- `code-quality` job: composer validate, security audit, PHPStan analysis
- `deploy` job: runs after test/code-quality pass, only on main branch push

PHPStan is configured at level 6 with a baseline (`phpstan-baseline.neon`) for existing issues.

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
