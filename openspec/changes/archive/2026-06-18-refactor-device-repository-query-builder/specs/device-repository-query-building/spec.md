## ADDED Requirements

### Requirement: Queries are constructed with the DBAL QueryBuilder

`DeviceRepository` SHALL construct its SQL via `Doctrine\DBAL\Query\QueryBuilder` (obtained from `$this->db->createQueryBuilder()`) rather than by string concatenation of `SELECT`/`WHERE`/`AND` fragments. The repository MUST continue to query the existing database views (`device_summary`, `cluster_stats`) and raw tables; it MUST NOT introduce ORM entity mappings or DQL for these views.

#### Scenario: A static lookup query uses the builder

- **WHEN** any `DeviceRepository` method runs a fixed (non-conditional) query
- **THEN** it builds the statement with `createQueryBuilder()` and executes it, returning the same row shape callers received before the refactor

#### Scenario: A dynamic filter query uses conditional builder calls

- **WHEN** `getFilteredDevices` / `getFilteredDeviceCount` runs with a subset of filters set
- **THEN** each active filter is added via `->andWhere()` / `->orWhere()` on the builder (no `WHERE 1=1` seed, no `$sql .=` concatenation), and the result set is identical to the pre-refactor query for the same inputs

### Requirement: SQLite-specific SQL is isolated in named fragment helpers

SQLite-specific constructs (`json_each`, `json_extract`, `INTERSECT`, and JSON-array `LIKE` matching) SHALL live in named private helper methods. Each helper MUST receive the shared `QueryBuilder`, bind its own parameters on that builder, and return the SQL fragment string to be passed to `->andWhere(...)`. Public query methods MUST NOT inline these constructs.

#### Scenario: Capability INTERSECT filter is a helper

- **WHEN** a capabilities filter requiring ALL selected capabilities is applied
- **THEN** the `INTERSECT` subquery is produced by a single named helper that binds its cluster parameters and returns the `id IN (...)` fragment, and the filtered results match the prior behavior (device must have every selected capability)

#### Scenario: Device-type JSON filter is a helper

- **WHEN** a device-types filter is applied
- **THEN** the `json_each` / `json_extract` subquery is produced by a named helper, and only devices whose endpoints declare one of the requested device types are returned

### Requirement: Parameter names cannot collide on the shared builder

When multiple filters or fragment helpers bind parameters onto the same `QueryBuilder`, the repository SHALL guarantee unique parameter names so no binding overwrites another. Loop-generated parameter names MUST be namespaced per filter and per helper invocation.

#### Scenario: Multiple loop-generated filters coexist

- **WHEN** a request combines connectivity, device-type, capability, and compatible-with filters in one query
- **THEN** all bound parameters resolve to their intended values with no overwrite, and the query returns the correct intersection of all filters

### Requirement: Emitted SQL semantics are pinned for the gnarly queries

The change SHALL add golden-SQL assertions for the highest-risk queries — the `INTERSECT` capability filter and the JSON-array `LIKE '%"x"%'` matching — so a future edit that alters the emitted SQL fails a test rather than silently changing behavior.

#### Scenario: Capability INTERSECT SQL is asserted

- **WHEN** the test suite builds the capability filter for a known set of capabilities
- **THEN** a test asserts the emitted SQL (and bound parameters) match the expected `INTERSECT` shape

#### Scenario: JSON-array LIKE SQL is asserted

- **WHEN** the test suite builds the connectivity (JSON-array `LIKE`) filter for a known value
- **THEN** a test asserts the emitted SQL and the `%"value"%` parameter form

### Requirement: Observable behavior is preserved

The refactor SHALL NOT change any public method signature, return shape, or query result of `DeviceRepository`. The existing repository and controller test suites MUST pass unchanged as the equivalence oracle.

#### Scenario: Existing suites pass without modification

- **WHEN** the full test suite runs after the refactor
- **THEN** the 34 `DeviceRepository` tests and the device-browser controller tests pass without changes to their assertions
