## 1. Baseline & scaffolding

- [x] 1.1 Run the full suite green and record the pre-refactor baseline (`php bin/phpunit`, especially `tests/Repository/DeviceRepositoryTest.php`, `DeviceRepositoryExtraTest.php`, and the device-browser controller tests) so any divergence is attributable to the refactor.
- [x] 1.2 Inventory all 63 `DeviceRepository` methods and assign each to a cluster: filters, stats, lookups, scores. Capture the list in the PR description.
- [x] 1.3 Decide and document the parameter-naming convention in a class-level comment: `createNamedParameter()` for helper-internal binds, explicit `{$ns}_{$i}` only where a golden test asserts SQL text (per design Decision 3).

## 2. Fragment helpers (SQLite-specific SQL)

- [x] 2.1 Add a private device-type fragment helper: takes `QueryBuilder $qb`, the requested type ids, and a namespace; binds its own params; returns the `id IN (SELECT ... json_each ... json_extract ...)` fragment.
- [x] 2.2 Add a private capability fragment helper producing the `INTERSECT` subquery for ALL-capabilities matching (one cluster `IN (...)` subquery per capability, joined by `INTERSECT`), binding cluster params via the namespace.
- [x] 2.3 Add a private connectivity fragment helper producing the JSON-array `LIKE '%"value"%'` predicate(s) joined by `OR`, binding the `%"value"%` params.
- [x] 2.4 Add private fragment helpers for the remaining JSON/`json_each` and `min_rating` / `compatible_with` subqueries currently inlined in `applyFilters`.
- [x] 2.5 Unit-test each fragment helper in isolation (fragment string + bound parameters), independent of a full query.

## 3. Golden-SQL assertions

- [x] 3.1 Add a golden-SQL test asserting the emitted SQL and bound parameters for the capability `INTERSECT` filter for a known set of capabilities.
- [x] 3.2 Add a golden-SQL test asserting the emitted SQL and the `%"value"%` parameter form for the connectivity JSON-array `LIKE` filter.
- [x] 3.3 Add a multi-filter regression test combining connectivity + device-types + capabilities + compatible-with in one query, asserting no parameter overwrite and the correct intersection of results.

## 4. Cluster: filters (convert to QueryBuilder)

- [x] 4.1 Rewrite `getFilteredDevices` to build via `createQueryBuilder()` (`select`/`from('device_summary')`, `setMaxResults`/`setFirstResult` for limit/offset, `orderBy`).
- [x] 4.2 Rewrite `getFilteredDeviceCount` to build the `COUNT(*)` query via the builder, sharing the same filter application as 4.1.
- [x] 4.3 Replace `applyFilters` string concatenation with conditional `->andWhere()` / `->orWhere()` calls that delegate SQLite-specific predicates to the section-2 helpers; remove the `WHERE 1=1` seed and the by-ref `$params`/`$types` arrays.
- [x] 4.4 Run the suite; confirm filter + golden + multi-filter tests pass with no assertion edits. Commit the filters cluster.

## 5. Cluster: stats (aggregate queries)

- [x] 5.1 Convert the cluster/device-type/version aggregate `SELECT`s (those over `cluster_stats` and grouped counts) to the builder, using fragment helpers for any JSON predicates.
- [x] 5.2 Run the suite (repository + `StatsController` tests) green; commit the stats cluster.

## 6. Cluster: lookups (device / version / endpoint reads)

- [x] 6.1 Convert single-device, version, and endpoint read queries to the builder.
- [x] 6.2 Convert vendor-scoped and search lookups, reusing the connectivity/search predicates as helpers where shared.
- [x] 6.3 Run the suite (repository + `DeviceController`/`VendorController` tests) green; commit the lookups cluster.

## 7. Cluster: scores

- [x] 7.1 Convert the `device_scores`-table read queries (similar/related device queries with `ds.id != :exclude_id`, ordering, limits) to the builder.
- [x] 7.2 Run the suite (repository + `DeviceScoreService` tests) green; commit the scores cluster.

## 8. Finalization

- [x] 8.1 Confirm zero remaining `$sql .=` concatenation and zero inlined `json_each`/`json_extract`/`INTERSECT` outside the named helpers in `DeviceRepository`.
- [x] 8.2 Run `make lint`, `make analyse` (PHPStan level 7), and `make rector` (dry-run); fix or baseline as appropriate.
- [x] 8.3 Run the full `php bin/phpunit` suite one final time; confirm all repository and controller tests pass with no assertion changes (behavioral oracle intact).
- [x] 8.4 Verify no public method signature or return shape changed (callers untouched: `DeviceController`, `StatsController`, `VendorController`, `CapabilityService`, `DeviceScoreService`).
