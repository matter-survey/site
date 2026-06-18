## Context

`DeviceRepository` is the data-access layer for the device browser, stats pages, scoring, and capability analysis. Unlike its sibling repositories (which `extend ServiceEntityRepository` and use the ORM `createQueryBuilder` against mapped entities), it was written against a raw `Doctrine\DBAL\Connection` because it queries **database views** (`device_summary` aliased from `product_summary`, `cluster_stats`) and **raw tables with SQLite JSON columns** (`product_endpoints.server_clusters`, `device_types`) that are deliberately *not* mapped as Doctrine entities.

Today the file is 2142 lines, 63 methods, 116 `SELECT`s. SQL is assembled with `$sql .= ...`, `implode(' OR ', $conds)`, manually generated `:placeholder` names, and `$params`/`$types` arrays threaded by reference through methods like `applyFilters` (lines ~288–409). 75 lines touch `json_each` / `json_extract` / `INTERSECT`. The dynamic filter path is the maintenance hotspot.

Constraints:
- **No PHP extensions** in production (KAS shared host); `doctrine/dbal ^4.4` is already present, so the DBAL QueryBuilder is available with zero new dependencies.
- **SQLite** dialect: queries rely on `json_each`, `json_extract`, `INTERSECT`, and `LIKE '%"x"%'` JSON-array matching — none expressible in ORM DQL.
- Behavior-preserving: callers (`DeviceController`, `StatsController`, `VendorController`, `CapabilityService`, `DeviceScoreService`) depend on current method signatures and return shapes.

## Goals / Non-Goals

**Goals:**
- Replace string-concatenation SQL construction across all 63 methods with the DBAL `QueryBuilder`.
- Isolate every SQLite-specific construct in a named, individually-readable private helper.
- Make parameter-name collisions structurally impossible on the shared builder.
- Pin the emitted SQL of the two highest-risk queries with golden assertions.
- Keep the diff reviewable by landing it in commit clusters.

**Non-Goals:**
- No move to ORM entities / DQL for the views (DQL cannot express these queries).
- No schema, view, or index changes.
- No change to public API, return shapes, or query results.
- No performance tuning beyond incidental — the emitted SQL stays equivalent.

## Decisions

### Decision 1: DBAL QueryBuilder, not ORM/DQL

Use `$this->db->createQueryBuilder()` (DBAL) and keep `FROM device_summary` / raw tables.

- **Why:** The ORM builder operates on mapped entities; `device_summary` and `cluster_stats` are views with no entity, and `json_each`/`INTERSECT` have no DQL equivalent. DBAL's builder is a thin fluent layer over the *same* SQL — it gives `->andWhere()` / `->setParameter()` / expression composition while still allowing raw SQL fragments where the dialect requires them.
- **Alternative considered:** Map views to read-only entities and use DQL. Rejected — forces fake entity metadata for views, still can't express the JSON/`INTERSECT` filters, and diverges from how the data is actually shaped.
- **Alternative considered:** Leave it as raw SQL but extract heredocs. Rejected — doesn't solve the dynamic-assembly problem, which is the actual pain.

### Decision 2: SQLite-specific SQL → named fragment helpers with a uniform contract

Each helper signature is uniform: `private function xFilterFragment(QueryBuilder $qb, <args>, string $ns): string`. It binds its own parameters on `$qb` and returns the fragment string for `->andWhere(...)`.

- **Why:** Keeps `json_each`/`INTERSECT` SQL in one named place per concept, makes each fragment unit-testable, and lets public methods read as a list of intents (`->andWhere($this->deviceTypeFilter($qb, $ids, 'dt'))`).
- **Alternative considered:** A generic fragment-builder abstraction. Rejected as over-engineering — a handful of named helpers is clearer than a mini-DSL.

### Decision 3: Parameter-naming discipline via per-fragment namespace prefix

Every helper and every loop receives/uses a short namespace prefix (e.g. `conn`, `dt`, `cap0`, `compat`) and names parameters `{$ns}_{$i}`. Prefer DBAL `createNamedParameter()` (auto-unique) where a helper builds its own fragment, falling back to explicit namespaced names only where readability of the emitted SQL matters for the golden tests.

- **Why:** Loop-generated names (`conn_0`, `cap_1_cluster_0`) already exist but rely on the author remembering to keep prefixes distinct. `createNamedParameter()` removes the foot-gun entirely for helper-internal binds; explicit names stay only where a golden test asserts the SQL text.
- **Risk addressed:** Two filters binding the same name on a shared `$qb` (silent overwrite).

### Decision 4: Golden-SQL assertions for the two gnarly queries only

Add focused tests asserting the emitted SQL string + bound parameters for (a) the capability `INTERSECT` filter and (b) the connectivity JSON-array `LIKE` filter. Not every query gets a golden test.

- **Why:** These two encode non-obvious semantics (ALL-capabilities intersection; `%"value"%` quoting to match a JSON array element). A behavioral change here would pass row-shape tests but be wrong. The other 100+ queries are adequately covered by the existing 34 repo + ~83 controller tests as a behavioral oracle.
- **Alternative considered:** Golden-test everything. Rejected — brittle, high-maintenance, low marginal value.

### Decision 5: Land in reviewable commit clusters

Group methods by concern and convert/commit per cluster: (1) **filters** (`getFilteredDevices`, `getFilteredDeviceCount`, `applyFilters`, fragment helpers, golden tests), (2) **stats** aggregates, (3) **lookups** (device/version/endpoint reads), (4) **scores** (score-table reads). Run the full suite green after each cluster.

- **Why:** A single 2000-line diff is unreviewable; per-cluster commits keep `main` shippable and make regressions bisectable. Aligns with the project's commit-discipline guidance.

## Risks / Trade-offs

- **Parameter-name collision on shared `$qb`** → Decision 3 (`createNamedParameter()` + namespace prefixes); the multi-filter scenario in the spec is a regression test.
- **SQL-semantics drift (esp. `INTERSECT` / JSON `LIKE`)** → Decision 4 golden assertions, plus the existing behavioral suite must stay green unmodified.
- **Large diff hides a subtle regression** → Decision 5 cluster commits + full suite green per cluster; no assertion edits allowed (editing a test to pass signals a behavior change).
- **DBAL builder can't express a construct cleanly (e.g. `INTERSECT`)** → accepted: those stay as raw fragment strings inside helpers; the builder handles only the dynamic glue, which is the explicit design, not a workaround.
- **Churn for zero user-facing value** → mitigated by scoping (helpers + golden tests deliver durable testability), and by the fact that the dynamic filter path is an active source of friction.

## Migration Plan

1. Land cluster-by-cluster on a feature branch; full PHPUnit suite + `make lint` + `make analyse` (PHPStan level 7) green per commit.
2. No data migration, no deploy-time step, no schema change.
3. **Rollback:** pure code revert — no state to unwind. Each cluster is an independent revertible commit.

## Open Questions

- Should the fragment helpers live in `DeviceRepository` as private methods, or be promoted to a small `DeviceFilterQueryBuilder` collaborator? Default: keep private for this change (smaller blast radius); revisit extraction only if the helper set grows unwieldy.
- Is `createNamedParameter()` preferred everywhere, or explicit named params where golden tests assert SQL text? Default per Decision 3: explicit only where a golden test reads the SQL, `createNamedParameter()` elsewhere.
