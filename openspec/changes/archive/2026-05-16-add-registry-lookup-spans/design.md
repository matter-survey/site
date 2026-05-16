## Context

`MatterRegistry` is the in-process catalog of Matter cluster and device-type definitions, loaded from YAML fixtures into the database and read back constantly: capability analysis hits it for every cluster on every endpoint of every device in a submission; device scoring iterates all required clusters per device type; the controller layer hits it for cluster names when rendering pages. A single `/api/submit` handling a 10-endpoint device can easily call into the registry 200+ times.

The registry already caches inside its constructor (loads everything from the DB once into `$this->clusters` / `$this->deviceTypes`), so each lookup is an array dereference plus a few branches — sub-microsecond. The actual cost concern is **not** registry latency; it's that 200 spans per request would 10x our trace volume for ~0% additional insight in steady state, while making the request span tree visually unreadable.

What's actually useful: the ability to flip on registry-lookup tracing _ad hoc_ when investigating something specific (a cache miss bug, a regression after a fixture change, a suspicion that some lookup path is doing N+1 DB queries because a cache invariant broke).

## Goals / Non-Goals

**Goals:**

- Restore spec/code consistency for the `matter_registry.lookup` span requirement.
- Make registry-lookup tracing controllable without a deploy: env flag flip + cache clear, no code change.
- Keep the steady-state hot path exactly as fast as today (one boolean read per lookup, no allocation).
- Bound the span count when enabled: only the small number of public entry-point getters get wrapped, not internal helpers.
- Surface the flag's state in `app:otel:doctor` so an operator can quickly confirm.

**Non-Goals:**

- Wrapping every method on `MatterRegistry` (over 20 getters; many are internal helpers called by other getters and would double-count).
- Adding lookup metrics (counter, histogram). Domain spans suffice for the debug use case; metrics can be a follow-up if real demand emerges.
- A finer-grained sampling mechanism (e.g. "1% of lookups"). The flag is binary by design; flip on, debug, flip off.
- Wrapping `CapabilityService` cluster references (those go through `MatterRegistry` already, so they're covered transitively).

## Decisions

### Decision 1: Wrap entry-point getters only

The getters that callers _outside_ `MatterRegistry` use are a small set:

- `getClusterName(int $id)`
- `getClusterMetadata(int $id)`
- `getClusterDescription(int $id)`
- `getClusterCategory(int $id)`
- `getClusterHexId(int $id)`
- `getClusterCommandName(int $clusterId, int $commandId)`
- `getClusterAttributeName(int $clusterId, int $attributeId)`
- `getDeviceTypeName(int $id)` (and analogous device-type getters)

Internal helpers like `decodeFeatureMap` are called _by_ these getters; wrapping them too would double-span. Map the entry-points explicitly. Span count under load: ≤ one per public lookup call, which for a typical submission is in the low hundreds — manageable.

**Alternative considered:** wrap the underlying private `findCluster()` / `findDeviceType()` methods instead of every public getter. Cleaner code-wise, but loses the calling-context attribute (was this a name lookup, a metadata lookup, a description lookup?) which is the whole point of having lookup spans.

### Decision 2: Binary opt-in via `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED`

Two-state flag, parsed from env once per process and cached in a static. Off by default, exactly as `OTEL_PHP_TRACES_DB_PARAMETER_CAPTURE` is today. When false, the wrapper short-circuits before touching the SDK.

```php
public function getClusterName(int $id): string
{
    if (!RegistryLookupTracing::enabled()) {
        return $this->doGetClusterName($id);
    }
    $span = Tracer::start('matter_registry.lookup', [
        'lookup.kind' => 'cluster',
        'lookup.method' => 'getClusterName',
        'cluster.hex_id' => $this->getClusterHexId($id),
    ]);
    try {
        $result = $this->doGetClusterName($id);
        $span->setAttribute('lookup.cache_hit', $this->lastLookupHitCache);
        return $result;
    } finally {
        $span->end();
    }
}
```

The implementation moves into `do<Name>` private methods. Slight code-shape cost, but the wrapping pattern is uniform and reviewable.

**Alternative considered:** a sampler-based approach (1% of lookups). Rejected — the use case is "debug a specific issue right now", not "always-on partial sampling". Binary is easier to reason about.

### Decision 3: `lookup.cache_hit` requires the registry to expose hit/miss state

Today, `MatterRegistry` doesn't expose whether the last lookup hit its in-memory cache. It always does (because the constructor loads everything), so `cache_hit` would always be `true`. To make the attribute meaningful, the registry needs to track when an ID is _missing_ from its cache (i.e. a lookup for an unknown cluster ID), distinct from a cache hit on a known ID.

**Decision:** `lookup.cache_hit=true` when the looked-up ID is present in the in-memory map; `false` when not (and the getter returns `null` / a fallback). This isn't strictly a cache miss in the database sense, but it's the most operator-meaningful signal: "did the registry know about this ID?"

If a future change introduces lazy loading from the DB, this attribute keeps its meaning naturally.

### Decision 4: Helper class to avoid scattering env-var reads

Add `App\Observability\RegistryLookupTracing` with a single static `enabled(): bool` method that resolves the env once and caches in a static. Callers (the wrapped getters) call it on every invocation; the boolean check + one static read is well below noise.

```php
final class RegistryLookupTracing
{
    private static ?bool $enabled = null;

    public static function enabled(): bool
    {
        return self::$enabled ??= self::resolve();
    }

    private static function resolve(): bool
    {
        $value = $_SERVER['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED']
            ?? $_ENV['OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED']
            ?? getenv('OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED');
        if (false === $value || '' === $value) {
            return false;
        }
        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    /** @internal for tests */
    public static function reset(): void { self::$enabled = null; }
}
```

The `reset()` method exists so tests can flip the flag without leaking static state across tests.

### Decision 5: Doctor command surfaces the new flag

Add `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED` to `OtelDoctorCommand::RELEVANT_VARS`. Operators running `app:otel:doctor` see whether registry lookup spans are on, alongside the existing config rows. No validation needed — any string maps to false safely.

## Risks / Trade-offs

- **[Risk] Operator forgets to turn the flag back off** after debugging, and prod traces are flooded for days. → Mitigation: the doctor command makes the state visible; sampling at the trace level (`OTEL_TRACES_SAMPLER_ARG`) still bounds backend cost; doc emphasizes "flip it back off" in the playbook.
- **[Risk] Wrapping pattern duplicates each getter (`getX` + `doGetX`)** which inflates `MatterRegistry` line count. → Accepted; the file is already 999 lines and another 30-40 from the wrapping is below review fatigue. Alternative single-call-site indirection (a `traceLookup(string $method, callable $fn, ...)`) was considered but adds runtime overhead on the steady-state path, which we explicitly want to keep flat.
- **[Risk] Static state in `RegistryLookupTracing`** leaks across PHPUnit tests. → Mitigation: `reset()` method called from `InMemoryOtelTrait::setUpOtel()` so every test starts with a clean read.
- **[Trade-off] `lookup.cache_hit` semantic isn't a true cache hit/miss** today (the registry is always in-memory). → Documented in the spec scenario; the attribute's meaning still survives if lazy loading is added later.
- **[Trade-off] Bounded list of wrapped getters means new getters added later won't auto-trace.** → Accepted. The tests assert which span names are emitted; adding a new wrapped getter will require an explicit allowlist update.

## Migration Plan

Single-phase rollout, no migrations:

1. Implement and merge.
2. Deploy with the flag off (default). Production behavior is unchanged.
3. When debugging, operator sets `OTEL_PHP_TRACES_REGISTRY_LOOKUPS_ENABLED=true` in `.env.local` on the host, runs `cache:clear`, reproduces the issue, then unsets and clears cache again.

**Rollback:** revert the commit (or just leave the flag off — the code is dormant when disabled).

## Open Questions

- Should `lookup.cache_hit=false` also bump a Monolog WARNING (so unknown-ID lookups surface even without trace export)? Lean: no — it's a normal occurrence for legacy data with old cluster IDs no longer in the spec. Don't pollute logs.
