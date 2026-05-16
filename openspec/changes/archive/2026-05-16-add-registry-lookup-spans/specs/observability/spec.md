## MODIFIED Requirements

### Requirement: Domain operations have named spans

The application SHALL emit named spans for the following domain operations, with the listed attributes when the SDK is enabled.

| Span name | Where | Required attributes | Emission |
|---|---|---|---|
| `telemetry.submit` | `TelemetryService::process` | `vendor.id`, `submission.schema_version`, `submission.endpoint_count`, `submission.cluster_count` | always |
| `score.calculate` | `DeviceScoreService` (per product) | `product.id`, `device_type.id`, `score.value` | always |
| `dcl.sync` | `DclApiService::syncVendors` | `dcl.vendor_count`, `dcl.product_count`, `dcl.page_count` | always |
| `dcl.fetch_page` | `DclApiService` (per page) | `dcl.page`, `dcl.page_size` | always |
| `zap.sync` | ZAP sync command body | `zap.cluster_count` | always |
| `matter_registry.lookup` | `MatterRegistry` (cluster/device-type lookups) | `lookup.kind`, `lookup.method`, `cluster.hex_id` or `device_type.hex_id`, `lookup.cache_hit` | only when `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true` |

#### Scenario: Telemetry submission span
- **WHEN** `TelemetryService::process()` is called with a valid v3 submission containing 3 endpoints and 7 distinct clusters
- **THEN** a span named `telemetry.submit` is recorded
- **AND** attributes include `submission.endpoint_count=3` and `submission.cluster_count=7`
- **AND** the span is a child of the active HTTP request span

#### Scenario: Score calculation span
- **WHEN** `DeviceScoreService::calculate()` runs for a product
- **THEN** a span named `score.calculate` is recorded with `product.id` and `score.value` attributes

#### Scenario: DCL sync produces a tree
- **WHEN** `app:dcl:sync` runs and fetches 3 pages
- **THEN** one `dcl.sync` span exists with three child `dcl.fetch_page` spans
- **AND** each child outbound HTTP request span sits under its corresponding `dcl.fetch_page`

#### Scenario: Registry lookup spans are off by default
- **WHEN** `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` is unset or `false`
- **AND** `MatterRegistry::getClusterName()` (or any other entry-point getter) is called many times during a request
- **THEN** **no** `matter_registry.lookup` spans are emitted
- **AND** the cost on the calling path is at most one boolean read per call

#### Scenario: Registry lookup spans when enabled
- **WHEN** `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true`
- **AND** `MatterRegistry::getClusterName(0x0006)` is called
- **THEN** one `matter_registry.lookup` span is emitted
- **AND** attributes include `lookup.kind=cluster`, `lookup.method=getClusterName`, `cluster.hex_id=0x0006`
- **AND** the span has `lookup.cache_hit=true` because the cluster is in the registry's in-memory map

#### Scenario: Lookup of an unknown identifier
- **WHEN** `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true`
- **AND** `MatterRegistry::getClusterName(0xFFFE)` is called for an ID not present in the registry
- **THEN** the emitted span has `lookup.cache_hit=false`
- **AND** the calling code receives the registry's normal fallback for unknown IDs (not an exception)

#### Scenario: Internal helpers do not double-span
- **WHEN** registry lookup spans are enabled
- **AND** a public getter internally consults another helper method on the same registry instance
- **THEN** only one `matter_registry.lookup` span is emitted per public call (no nested span tree from internal helpers)

### Requirement: Configuration is environment-driven

The application SHALL be configurable entirely via standard `OTEL_*` environment variables and SHALL ship safe defaults that keep telemetry off in dev and test.

#### Scenario: Default repository state
- **WHEN** a fresh checkout is run with only committed `.env` and `.env.test`
- **THEN** `OTEL_SDK_DISABLED=true` is in effect
- **AND** no telemetry is exported anywhere

#### Scenario: Operator enables telemetry in prod
- **WHEN** `.env.local` sets `OTEL_SDK_DISABLED=false`, `OTEL_EXPORTER_OTLP_ENDPOINT=https://otlp.example/`, `OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer abc`
- **THEN** the SDK boots and exports to that endpoint with the supplied headers
- **AND** no code change is required

#### Scenario: Sampling override
- **WHEN** `OTEL_TRACES_SAMPLER=parentbased_traceidratio` and `OTEL_TRACES_SAMPLER_ARG=0.1`
- **THEN** approximately 10% of root traces are sampled
- **AND** child spans inherit the parent's sampling decision

#### Scenario: Selective signal exporters
- **WHEN** `OTEL_TRACES_EXPORTER=otlp`, `OTEL_METRICS_EXPORTER=none`, `OTEL_LOGS_EXPORTER=none`
- **THEN** only traces are exported; metrics and log providers are no-ops

#### Scenario: Registry lookup spans are opt-in
- **WHEN** `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` is unset
- **THEN** the application treats it as `false`
- **AND** registry lookup spans are not emitted

#### Scenario: Doctor command surfaces the registry-lookup flag
- **WHEN** `php bin/console app:otel:doctor` is invoked
- **THEN** the printed environment table includes a row for `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED`
- **AND** its value reflects the resolved env (`<unset>`, `true`, or `false`)
