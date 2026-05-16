## 1. Toggle helper and registry instrumentation

- [x] 1.1 Create `src/Observability/RegistryLookupTracing.php` with a static `enabled(): bool` (cached after first read) and a test-only `reset(): void`
- [x] 1.2 In `src/Service/MatterRegistry.php`, refactor each entry-point getter into a public wrapper + private `do<Name>` implementation (initial set: `getClusterName`, `getClusterMetadata`, `getClusterDescription`, `getClusterCategory`, `getClusterHexId`, `getClusterCommandName`, `getClusterAttributeName`, and the analogous device-type getters)
- [x] 1.3 In each wrapper, short-circuit to the `do<Name>` implementation when `RegistryLookupTracing::enabled()` returns false
- [x] 1.4 When tracing is enabled, emit a `matter_registry.lookup` span via `App\Observability\Tracer::start()` with `lookup.kind`, `lookup.method`, `cluster.hex_id` or `device_type.hex_id`, and `lookup.cache_hit` attributes
- [x] 1.5 Track in-memory cache hit/miss in the registry so `lookup.cache_hit` reflects whether the requested ID is present in the loaded map

## 2. Configuration surface

- [x] 2.1 Add `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` to the `RELEVANT_VARS` list in `OtelDoctorCommand`
- [x] 2.2 Document the flag in `docs/observability.md` under a new "Debugging registry latency" section, including the flip-on / reproduce / flip-off playbook

## 3. Tests

- [x] 3.1 Add `App\Observability\AttributeAllowlist` entry for `matter_registry.lookup` (`lookup.kind`, `lookup.method`, `cluster.hex_id`, `device_type.hex_id`, `lookup.cache_hit`)
- [x] 3.2 Add a `MatterRegistryTracingTest` (under `tests/Observability/`) using `InMemoryOtelTrait`: with the flag off, multiple registry calls emit zero `matter_registry.lookup` spans; with the flag on, calls emit spans with the right attributes; the unknown-ID path emits `lookup.cache_hit=false`
- [x] 3.3 Extend `OtelDoctorCommandTest` to verify the new env row appears
- [x] 3.4 Reset `RegistryLookupTracing` static state in `InMemoryOtelTrait::setUpOtel()` so the cached read does not leak across tests

## 4. Validation and rollout

- [x] 4.1 Run `make lint` and `make analyse`; resolve any new findings (no baseline additions allowed)
- [x] 4.2 Run the full PHPUnit suite; verify no pre-existing test regresses and the new span path is covered
- [x] 4.3 Commit, deploy, and verify the doctor command reports the new flag on prod
