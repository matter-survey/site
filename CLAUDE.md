# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Build & Development Commands

```bash
# Install dependencies
composer install

# Run local development server
php -S localhost:8000 -t public public/router.php

# Run with Docker
docker-compose up -d
# Access at http://localhost:8080
```

The SQLite database auto-initializes on first request using `schema.sql`.

## Architecture

**Framework:** Symfony 7.0 with MicroKernel

**Request Flow:** `public/index.php` → `Kernel` → Controllers (attribute-based routing)

### Key Components

- **ApiController** (`POST /api/submit`) - Telemetry submission endpoint with rate limiting (10 req/min per IP)
- **DeviceController** - Device browser with pagination and search (`/`, `/device/{id}`)
- **TelemetryService** - Validates payloads, processes submissions, manages installations
- **DeviceRepository** - Data access for devices, versions, and endpoints
- **DatabaseService** - SQLite connection and schema initialization
- **MatterRegistry** - Lookup for Matter cluster/device type names

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

## Commit Guidelines

Use semantic commits (e.g., `feat:`, `fix:`, `ci:`, `docs:`).
