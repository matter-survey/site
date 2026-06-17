## Why

Matter's three multi-device coordination primitives — **Binding** (direct device-to-device control), **Groups** (multicast bulk control), and **Scenes** (saved/recalled device states) — are what distinguish a connected *system* from a pile of independently-controlled devices. Today only Binding is recognized as a first-class concept across the site (dedicated stats page, list facet, device badge, SQL view column), while Groups and Scenes exist merely as entries in the per-device capability list. This asymmetry hides two of the three features users most care about when judging whether devices will actually work together. We should recognize these features as one coherent **Coordination & Interoperability** category and give Groups and Scenes parity with Binding.

## What Changes

- Introduce a site-wide **"Coordination & Interoperability"** concept that treats Binding, Groups, and Scenes as members of one category rather than three unrelated features.
- Add **dedicated cluster detection** for Groups (cluster `4`) and Scenes (clusters `98` Scenes Management + legacy `5` Scenes) in `DeviceRepository`, parallel to the existing Binding (`30`) detection. Scenes requires matching an **ID set**, not a single constant.
- Add `supports_groups` and `supports_scenes` columns to the `product_summary`/`device_summary` SQL view via a new Doctrine migration (following the existing view-rebuild pattern used for `supports_binding`).
- Render **role-aware device-page badges** under one heading: "Controls devices directly" (Binding, controller-side) vs. "Group control" / "Scene control" (Groups/Scenes, controlled-side). The controller/controlled distinction is preserved so a wall switch and a smart bulb are not conflated.
- Add a **"Coordination" facet group** on the device list with three independent toggles (binding / groups / scenes) plus counts.
- Add a unified **`/stats/coordination`** stats page covering all three breakdowns with `Dataset` JSON-LD (CC0 license, carried over from the binding page) and the AEO lede pattern.
- **BREAKING (URL):** `/stats/binding` becomes a **301 redirect** to `/stats/coordination#binding`. Update the stats nav (`Binding` → `Coordination`) and `sitemap.xml` (drop `stats_binding`, add `stats_coordination`, refresh `# Last reviewed`).

## Capabilities

### New Capabilities
- `coordination-features`: Site-wide recognition of Matter's multi-device coordination primitives (Binding, Groups, Scenes) — detection from telemetry clusters, role-aware device-page badges, device-list facets, and a unified stats page with structured data. Subsumes the existing binding-only behavior.

### Modified Capabilities
<!-- No existing OpenSpec spec governs binding/stats behavior today (only `observability` exists), so binding's current behavior is captured as part of the new `coordination-features` capability rather than a delta. -->

## Impact

- **Detection / data:** `src/Repository/DeviceRepository.php` (new cluster constants, `has_groups_cluster`/`has_scenes_cluster` derivation, coordination facet + by-category methods); new migration under `migrations/` rebuilding the `product_summary` view with two new columns.
- **Capability layer:** `fixtures/capabilities.yaml` (`groups`/`scenes` already defined — tag into the coordination category; reconsider `standouts` in `src/Service/CapabilityService.php`).
- **Controllers / routing:** `src/Controller/StatsController.php` (new `coordination` action, binding redirect), `src/Controller/DeviceController.php` (new facet filter wiring), `src/Controller/SitemapController.php`.
- **Templates:** `templates/device/show.html.twig` (role-aware badges), `templates/device/index.html.twig` (facet group + list badges), new `templates/stats/coordination.html.twig`, `templates/stats/_nav.html.twig`, `templates/base.html.twig` (badge styles).
- **SEO / AEO invariants (CLAUDE.md):** new Dataset JSON-LD via `StructuredDataService`/`_dataset_jsonld.html.twig`, lede via `AeoLedeService` if the page is treated as an entity page, sitemap `# Last reviewed` discipline, robots policy unaffected.
- **Tests:** controller tests for the new stats page + redirect, repository detection tests for groups/scenes (including legacy cluster 5), facet-filter tests.
