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

# Clear cache
php bin/console cache:clear

# Deploy to production (requires .env.local with SFTP credentials)
make deploy
```

The SQLite database auto-initializes on first request using `schema.sql`. For tests, initialize manually:

```bash
mkdir -p data && sqlite3 data/matter-survey.db < schema.sql
```

## Architecture

**Framework:** Symfony 7.3 with MicroKernel (PHP 8.4)

**Request Flow:** `public/index.php` → `Kernel` → Controllers (attribute-based routing)

### Key Components

- **ApiController** (`POST /api/submit`) - Telemetry submission with rate limiting and validation via DTOs
- **HealthController** (`GET /health`) - Health check endpoint with database connectivity verification
- **DeviceController** - Device browser with pagination and search (`/`, `/device/{id}`)
- **TelemetryService** - Processes submissions, logs via `LoggerInterface`
- **DeviceRepository** - Data access for devices, versions, and endpoints
- **MatterRegistry** - Lookup for Matter cluster/device type names and metadata

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
- `code-quality` job: composer validate, security audit
- `deploy` job: runs after test/code-quality pass, only on main branch push

## Commit Guidelines

Use semantic commits (e.g., `feat:`, `fix:`, `ci:`, `docs:`, `refactor:`, `chore:`).
