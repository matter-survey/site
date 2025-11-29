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
- **MatterRegistry** - Lookup for Matter cluster/device type names and metadata (database-backed)

### Console Commands

- `app:dcl:sync` - Fetch vendor/product data from Matter DCL API and generate YAML fixtures
- `app:zap:sync` - Fetch cluster details from Matter SDK ZAP XML files and update fixtures

### Matter Registry Data

All Matter specification data (clusters, device types) is stored in the database and loaded from YAML fixtures:

- `fixtures/clusters.yaml` - 50+ cluster definitions with names, descriptions, categories
- `fixtures/device_types.yaml` - 65+ device type definitions with cluster requirements

**Entities:**
- `Cluster` - id, hexId, name, description, specVersion, category, isGlobal
- `DeviceType` - id, hexId, name, description, category, displayCategory, icon, cluster requirements

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

```
Devices (vendor_id, product_id)
  ├─ DeviceVersions (hardware/software versions)
  ├─ DeviceEndpoints (clusters JSON, device_types JSON)
  └─ Installations (UUID deduplication)
```

Database views: `device_summary`, `cluster_stats`

## Configuration

- **Database path:** `data/matter-survey.db` (configured in `services.yaml`)
- **Rate limiting:** Sliding window, 10/min per IP (configured in `rate_limiter.yaml`)
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
