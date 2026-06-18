## Why

`DeviceRepository` has grown to 2142 lines with 63 methods and 116 raw `SELECT` statements built by string concatenation (`$sql .= ' AND ...'`, `implode(' OR ', $conds)`, hand-rolled `:placeholder` lists, `$params`/`$types` arrays threaded by reference). The dynamic filter path (`getFilteredDevices` → `applyFilters`) is where this hurts most: predicates are assembled mid-method as text, parameter names are generated in loops with no collision discipline, and the SQLite-specific bits (`json_each`, `json_extract`, `INTERSECT`) are interleaved with the glue. Every sibling repository (`ClusterRepository`, `ProductRepository`, `VendorRepository`, `DeviceTypeRepository`, …) already uses a query builder; this is the one outlier written against a raw `Doctrine\DBAL\Connection`. The result is hard to read, easy to break, and untestable at the fragment level.

## What Changes

- Convert all 63 methods of `DeviceRepository` from raw `Connection::executeQuery` string concatenation to the **Doctrine DBAL QueryBuilder** (`$this->db->createQueryBuilder()`), repository-wide.
- Keep targeting database **views** (`device_summary`, `cluster_stats`) and raw tables — the DBAL builder (not ORM/DQL) is used precisely because these are unmapped views with SQLite JSON functions that DQL cannot express.
- Extract the SQLite-specific SQL (`json_each`, `json_extract`, `INTERSECT`, ~75 lines today) into **named private fragment-helper methods**. Each helper receives the shared `$qb`, binds its own parameters, and returns the fragment string for `->andWhere(...)`, so public methods read as intent rather than SQL.
- Establish a **parameter-naming discipline** so loop-generated and helper-bound parameters cannot collide on the shared `$qb`.
- Add **golden-SQL assertions** for the gnarly queries (the `INTERSECT` capability filter and the JSON-array `LIKE '%"x"%'` matching) so future edits cannot silently change emitted SQL semantics.
- Land as **reviewable commit clusters** (filters → stats → lookups → scores), not one 2000-line diff.
- Non-goal: no change to public method signatures, return shapes, query results, or the database schema. This is behavior-preserving.

## Capabilities

### New Capabilities
- `device-repository-query-building`: The engineering contract for how `DeviceRepository` constructs queries — DBAL QueryBuilder usage, isolation of SQLite-specific SQL into named fragment helpers, parameter-name collision safety, and SQL-equivalence guarantees verified by the existing test suite plus golden-SQL assertions.

### Modified Capabilities
<!-- None. This refactor preserves all observable behavior; no existing requirement changes. -->

## Impact

- **Code:** `src/Repository/DeviceRepository.php` (whole file). No callers change — `DeviceController`, `StatsController`, `VendorController`, `CapabilityService`, `DeviceScoreService` consume the same method signatures and return shapes.
- **Tests:** Existing oracle is 34 repository tests (`DeviceRepositoryTest` + `DeviceRepositoryExtraTest`) and ~83 controller tests exercising the device browser end-to-end. New: golden-SQL assertions for `INTERSECT` capability filtering and JSON-array `LIKE` matching.
- **Dependencies:** None added; `doctrine/dbal ^4.4` already provides the QueryBuilder.
- **Runtime/behavior:** None intended — same SQL reaches SQLite, same rows return. Risk surface is parameter-name collisions on the shared `$qb` and accidental SQL-semantics drift, both mitigated above.
