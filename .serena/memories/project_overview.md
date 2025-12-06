# Matter Survey Site - Project Overview

## Purpose
A Matter smart home device telemetry collection and analysis platform. The site:
- Collects telemetry data from Matter devices in the wild
- Displays device statistics, cluster information, and vendor data
- Imports product data from the Matter DCL (Distributed Compliance Ledger)
- Provides browsing/search of Matter devices and their capabilities

## Tech Stack
- **Framework:** Symfony 7.3 with MicroKernel (PHP 8.4)
- **Database:** SQLite (file-based at `data/matter-survey.db`)
- **ORM:** Doctrine
- **Frontend:** Twig templates with vanilla CSS/JS
- **API:** Rate-limited REST API for telemetry submission

## Key Concepts
- **Products**: Matter devices identified by vendor_id + product_id
- **Vendors**: Device manufacturers (synced from Matter DCL)
- **Clusters**: Matter protocol capabilities (e.g., OnOff, LevelControl)
- **Device Types**: Matter device classifications (e.g., Light, Thermostat)
- **Telemetry**: Survey submissions containing device endpoint/cluster data
- **Seen vs DCL**: Products are "seen" only when submitted via telemetry, not when imported from DCL fixtures
