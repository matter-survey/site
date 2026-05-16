## Why

The previous observability change (`add-opentelemetry-instrumentation`) committed to a `matter_registry.lookup` span in its spec but deferred the implementation when the trade-off became clear: `MatterRegistry` exposes 20+ getters that are called dozens (sometimes hundreds) of times per request — a span per call would dominate trace volume and cost without much insight in the steady state. The deferral leaves the spec inconsistent with shipped code, and leaves no way to debug registry latency when it _does_ matter (e.g. investigating slow capability analysis, suspected cache misses).

This change reconciles the spec with reality: registry-lookup spans become an **opt-in debug mode**, off in steady state, on for ad-hoc investigations.

## What Changes

- Add an opt-in `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` env flag. Default: off.
- When **off** (steady state): no `matter_registry.lookup` spans emitted. Cost on the hot path is one boolean check.
- When **on**: emit one `INTERNAL` span per `MatterRegistry` lookup call with `cluster.hex_id` or `device_type.hex_id` plus `lookup.cache_hit` (and `lookup.kind=cluster|device_type` for filtering).
- Wrap the small number of `MatterRegistry` _entry-point_ getters that callers actually use (`getClusterName`, `getClusterMetadata`, `getDeviceTypeName`, etc.) — not every internal helper — to keep the span count bounded even when enabled.
- Update `app:otel:doctor` to surface the new flag in its environment table so operators can confirm whether lookup spans are on.
- Update `docs/observability.md` with a "debugging registry latency" section describing when to flip the flag and what the resulting traces look like.

## Capabilities

### New Capabilities

<!-- None — no new capability introduced. -->

### Modified Capabilities

- `observability`: tighten the "Domain operations have named spans" requirement to make `matter_registry.lookup` conditional on `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true`. Tighten "Configuration is environment-driven" with the new flag's default and behavior.

## Impact

- **Code touched**: `src/Service/MatterRegistry.php` (entry-point getters wrapped via the existing `App\Observability\Tracer` facade), `src/Command/OtelDoctorCommand.php` (one row added to the env table), `src/Observability/AttributeAllowlist.php` (allowlist for the new span name).
- **Performance**: when off, one boolean check per lookup — within noise. When on, one OTel span object per lookup — cheap but additive; the bounded set of entry-point getters keeps it manageable.
- **Configuration surface**: one new env var. No effect on existing deployments unless the operator opts in.
- **Tests**: extend `DomainSpansTest` to cover both modes (off → no spans; on → expected attributes), extend `AttributeAllowlistTest` to cover the new span.
- **Spec drift**: closes the gap left by deferred task `8.6` in the previous change; the `observability` spec becomes consistent with shipped code again.
