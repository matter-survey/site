# Code Style & Conventions

## Commit Guidelines
- Use semantic commits: `feat:`, `fix:`, `ci:`, `docs:`, `refactor:`, `chore:`
- Run `make lint` and `make analyse` before committing

## PHP Code Style
- PHP 8.4 with strict types
- Follows Symfony coding standards (enforced by php-cs-fixer)
- Use typed properties and return types
- Use constructor property promotion where appropriate
- Nullable types indicated with `?` prefix

## Architecture Patterns
- Attribute-based routing on controllers
- Service classes for business logic (TelemetryService, DeviceScoreService, etc.)
- Repository pattern for data access
- DTOs for API validation (TelemetrySubmission, TelemetryDevice)
- Doctrine entities for ORM-managed data

## Database Access
- Doctrine ORM for entity management (Product, Vendor, Cluster, DeviceType)
- Raw SQL via DeviceRepository for complex queries and views
- SQLite with JSON columns for flexible data (clusters, device_types arrays)

## Important Conventions
- Products from DCL fixtures have `submission_count = 0` and `first_seen/last_seen = null`
- Only telemetry submissions populate the "seen" fields
- Use COALESCE in SQL to handle null values properly
