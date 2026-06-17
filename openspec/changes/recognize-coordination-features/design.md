## Context

The site already elevates the Matter **Binding** cluster (`30`) into a first-class concept across six layers: a capability definition, a device-page badge, a `standouts` entry, a `supports_binding` column on the `product_summary`/`device_summary` SQL view, a device-list facet filter, and a dedicated `/stats/binding` page with `Dataset` JSON-LD. Groups (cluster `4`) and Scenes (clusters `98` Scenes Management + legacy `5` Scenes) exist only as entries in `fixtures/capabilities.yaml`, surfacing in the per-device capability list but nowhere else.

This change recognizes all three as one **Coordination & Interoperability** category and gives Groups and Scenes parity with Binding. Constraints that shape the design:

- The `product_summary` view is recreated wholesale by ~6 existing migrations; new columns require a fresh migration that follows that pattern, not an `ALTER`.
- CLAUDE.md AEO invariants govern stats pages: `Dataset` JSON-LD via `StructuredDataService`/`_dataset_jsonld.html.twig`, CC0 license, and sitemap `# Last reviewed` discipline.
- Real telemetry spans Matter 1.0–1.4+, so Scenes detection must match both the current and the deprecated cluster.

## Goals / Non-Goals

**Goals:**
- Treat Binding, Groups, and Scenes as one coherent category with shared UI/navigation surfaces.
- Give Groups and Scenes the same six-layer treatment Binding already has (detection → view column → device badge → facet → stats page).
- Preserve the **controller-side vs. controlled-side** semantic distinction so badges remain accurate.
- Keep existing `/stats/binding` deep links working via redirect; avoid orphaning SEO value.

**Non-Goals:**
- No change to telemetry submission format or the `device_endpoints` raw schema — detection is derived from already-collected `server_clusters`/`client_clusters`.
- No new capability-detection engine; Groups/Scenes capability entries in `capabilities.yaml` stay as-is for the per-cluster expandable view.
- No scoring-weight changes (`DeviceScoreService`) in this change.
- No per-feature standalone stats pages (`/stats/groups`, `/stats/scenes`) — one unified page only.

## Decisions

### Decision 1: One unified `/stats/coordination` page, binding redirects in
Binding folds into a single coordination page rather than spawning three sibling pages.

- **Why:** The three features are conceptually one category; three pages fragment the story and triple the JSON-LD/maintenance surface. A single page tells the "do these work together" story coherently.
- **`/stats/binding` → 301 → `/stats/coordination#binding`.** Preserves inbound links and SEO equity. Update `stats_nav` (`Binding` → `Coordination`), `SitemapController` (drop `stats_binding`, add `stats_coordination`), and refresh the robots/sitemap review date.
- **Alternatives considered:** (a) Keep `/stats/binding` + add `/stats/groups` + `/stats/scenes` (4 pages) — rejected for fragmentation and JSON-LD duplication. (b) Anchor-only sections without redirect — rejected because the old route would 404.

### Decision 2: Dedicated cluster detection parallel to binding, with a Scenes ID *set*
Add detection in `DeviceRepository` mirroring the existing `BINDING_CLUSTER_ID = 30` pattern.

- `GROUPS_CLUSTER_ID = 4` (single constant, like binding).
- `SCENES_CLUSTER_IDS = [98, 5]` — **Scenes Management (98)** replaced the **deprecated Scenes (5)** in Matter 1.4+. A device "supports scenes" if *either* is present. This is the one place binding's single-constant pattern does not carry over.
- Derivation sits alongside the existing `has_binding_cluster` logic (`DeviceRepository.php:777`), checking server clusters (Groups/Scenes are server-side on the controlled device).
- **Why:** Detection must be telemetry-version-tolerant. Treating scenes as a set future-proofs against the deprecation split without special-casing per row.
- **Alternative:** Detect only `98` — rejected; would under-report every pre-1.4 device.

### Decision 3: Role-aware badges (controller vs. controlled)
Binding lives on the **controller** side (often the client cluster of a switch/sensor that drives others); Groups and Scenes live on the **controlled** side (server clusters of a device that can be orchestrated). The device page renders three distinct badges under one "Works with other devices" heading:

- Binding → "Controls devices directly" (with the existing hub-less explainer tooltip).
- Groups → "Group control".
- Scenes → "Scene control".

- **Why:** A single "Supports Coordination" badge would light up for both a wall switch (binding only) and a smart bulb (groups+scenes only), implying equivalence when they play opposite roles. Distinct labels keep the meaning honest.
- **Alternative:** One badge that expands to show which three — rejected as less scannable and semantically lossy.

### Decision 4: New migration rebuilds the view; no ALTER
Add `supports_groups` and `supports_scenes` to `product_summary` by recreating the view in a new dated migration, copying the most recent view-definition migration and extending its `SELECT`/`CASE` blocks (the binding column is computed via `MAX(de.has_*)`-style aggregation across endpoints).

- **Why:** SQLite views can't be altered in place, and every prior view change in this repo recreates the whole view. Diverging would break the established migration lineage.
- The `has_groups_cluster`/`has_scenes_cluster` per-endpoint booleans feed the view aggregation, matching how `has_binding_cluster` already works.

### Decision 5: Coordination facet group with three independent toggles
The device-list filter gains a "Coordination" section with three independent controls (binding / groups / scenes), each with a count, rather than one combined radio.

- **Why:** The features are independent and a user may want "group control" specifically. Independent toggles compose; a single tri-state radio cannot express "groups AND scenes".
- Repository gains a `getCoordinationFacets()` returning per-feature `with/without` counts (generalizing `getBindingFacets()`), and the existing `?binding=` filter is joined by `?groups=`/`?scenes=` in the same filter-array plumbing.

## Risks / Trade-offs

- **Migration view-rebuild drift** → Copy the newest existing `product_summary` migration verbatim as the base and add only the two columns; run the full suite + a manual `device_summary` query after migrating to confirm no column was dropped.
- **`/stats/binding` redirect breaks tests/sitemap/JSON-LD** → Grep for `stats_binding` (nav, sitemap, controller tests, any hardcoded links) and update atomically; add a controller test asserting the 301 target.
- **Scenes false-negatives from the cluster split** → Cover both `98` and `5` in a repository unit test with fixtures on each Matter version; the ID-set approach is the mitigation.
- **AEO invariant regressions on the new page** → Reuse `_dataset_jsonld.html.twig` and `StructuredDataService::LICENSE_CC0`; if the page is treated as an entity page, extend `AeoLedeService` so the lede matches JSON-LD `description` byte-for-byte (per CLAUDE.md).
- **Badge semantics confuse users despite role labels** → Keep the binding hub-less explainer tooltip and add short tooltips to group/scene badges clarifying "this device can be controlled in bulk / via saved scenes".

## Migration Plan

1. Add per-endpoint `has_groups_cluster`/`has_scenes_cluster` derivation + cluster constants in `DeviceRepository`.
2. New dated migration recreating `product_summary`/`device_summary` with `supports_groups` + `supports_scenes`; deploy runs `doctrine:migrations:migrate`.
3. Ship detection/facet/badge/stats changes together so the new columns are immediately consumed.
4. `/stats/binding` 301 redirect + nav/sitemap updates in the same change.
5. **Rollback:** the new migration is additive (extra view columns + new route); reverting the migration restores the prior view, and the binding redirect can be reverted to the original action without data loss.

## Open Questions

- Should the new page be modeled as an AEO **entity page** (with a lede + `Dataset` description parity) or purely an aggregate stats page (Dataset JSON-LD only, no lede)? Leaning aggregate-only, consistent with other `/stats/*` pages.
- Should `CapabilityService::identifyStandouts()` add `groups`/`scenes`, or does the dedicated badge row make the standout redundant? Leaning: drop them from standouts once the coordination badge row exists, to avoid double-surfacing.
