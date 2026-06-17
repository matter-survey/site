## 1. Cluster detection (DeviceRepository)

- [x] 1.1 Add `GROUPS_CLUSTER_ID = 4` and `SCENES_CLUSTER_IDS = [98, 5]` constants alongside `BINDING_CLUSTER_ID`
- [x] 1.2 Derive `has_groups_cluster` / `has_scenes_cluster` per endpoint next to the existing `has_binding_cluster` logic (server-cluster membership; scenes matches the ID set)
- [x] 1.3 Generalize `getBindingFacets()` into `getCoordinationFacets()` returning `with/without` counts for binding, groups, and scenes
- [x] 1.4 Add coordination breakdown-by-category method(s) (generalize `getBindingByCategory()` to cover groups and scenes)
- [x] 1.5 Extend the list-filter plumbing to accept `groups` and `scenes` filters alongside `binding`
- [x] 1.6 Unit-test detection: cluster 98, legacy cluster 5, cluster 4, none; assert correct booleans

## 2. SQL view migration

- [x] 2.1 Copy the newest `product_summary`/`device_summary` view migration as the base for a new dated migration
- [x] 2.2 Add `supports_groups` and `supports_scenes` columns aggregated across endpoints (mirroring the `supports_binding` computation)
- [x] 2.3 Run `doctrine:migrations:migrate` locally and verify the `device_summary` query returns the new columns with no dropped fields

## 3. Device-page badges (role-aware)

- [x] 3.1 Add a "Works with other devices" heading/group in `device/show.html.twig`
- [x] 3.2 Render binding as "Controls devices directly" (keep the hub-less explainer tooltip)
- [x] 3.3 Render "Group control" and "Scene control" badges driven by `supports_groups` / `supports_scenes`, with clarifying tooltips
- [x] 3.4 Add badge styles (`badge-groups`, `badge-scenes`) in `base.html.twig`
- [x] 3.5 Decide and apply standouts treatment in `CapabilityService::identifyStandouts()` (drop groups/scenes if the badge row supersedes them)

## 4. Device-list facet

- [x] 4.1 Add a "Coordination" filter group in `device/index.html.twig` with three independent toggles + counts
- [x] 4.2 Wire `groups` / `scenes` filters and active-filter chips (mirror the binding filter UI)
- [x] 4.3 Add list-row badges for groups/scenes (mirror `badge-binding`)
- [x] 4.4 Pass coordination facets from `DeviceController` to the template

## 5. Unified stats page

- [x] 5.1 Add a `coordination` action + `/stats/coordination` route in `StatsController` wiring the new repository methods
- [x] 5.2 Create `templates/stats/coordination.html.twig` with binding/groups/scenes breakdowns and per-category tables
- [x] 5.3 Emit `Dataset` JSON-LD via `_dataset_jsonld.html.twig` with the CC0 license (and lede only if treated as an entity page)
- [x] 5.4 Update `templates/stats/_nav.html.twig`: replace the `Binding` entry with `Coordination` (`stats_coordination`)

## 6. Binding route redirect & sitemap

- [x] 6.1 Change `/stats/binding` to a 301 redirect to `/stats/coordination#binding`
- [x] 6.2 Update `SitemapController`: drop `stats_binding`, add `stats_coordination`
- [x] 6.3 Refresh the `# Last reviewed` date in `public/robots.txt` if the crawler/sitemap policy comment is touched
- [x] 6.4 Grep for remaining `stats_binding` references (templates, tests, hardcoded links) and update

## 7. Tests & quality gates

- [x] 7.1 Controller test: `/stats/coordination` renders and includes Dataset JSON-LD
- [x] 7.2 Controller test: `/stats/binding` returns 301 to the coordination anchor
- [x] 7.3 Facet-filter tests for `groups` and `scenes` (single and combined)
- [x] 7.4 Run `make lint`, `make analyse`, `make rector` and fix findings
- [x] 7.5 Run the full PHPUnit suite green
