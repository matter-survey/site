## Why

Matter Survey is a public, factual registry of Matter devices, vendors, clusters, and device types — exactly the kind of source AI agents (ChatGPT, Claude, Perplexity, Google AI Overviews, Gemini) want to cite when users ask "what is the OnOff cluster?" or "is the Aqara FP2 a Matter device?". Classic SEO foundations already exist (sitemap index, per-type sitemaps, canonical + hreflang from #222, JSON-LD on every public entity, fully SSR templates), but the pages are not yet *shaped for citation*: they open with breadcrumbs and capability grids rather than declarative sentences, the JSON-LD is missing fields agents use heavily (`sameAs`, `BreadcrumbList`, `dateModified`, `Dataset`), and `robots.txt` is a permissive wildcard with no per-bot policy.

2026 research is unambiguous about what moves the needle: **44% of LLM citations come from the first 30% of page text** (the lede), **schema markup yields a 67% discoverability boost** and appears on 61% of cited pages, **83% of AI Overview citations come from pages outside the organic top 10** (structure beats domain authority), and the Princeton/KDD GEO study singled out "Statistics Addition" and "Cite Sources" as the top-performing tactics with up to **+40% visibility lift**. Three of those four findings map directly onto leverage points this codebase has not yet exploited.

## What Changes

This change ships **three independent tracks**. Each is reviewable and shippable on its own, but the proposal scopes them together because they share design context (entity-page templating, JSON-LD conventions) and are the coherent answer to "make the site agent-citable."

### Track 1 — Entity-page content shape (highest ROI)

- Introduce a server-rendered **definitional lede sentence** at the top of every entity page (`device/show`, `vendor/show`, `stats/cluster_show`, `stats/device_type_show`), generated from data already in the database. One sentence, declarative, agent-quotable, contains entity name + identifier + one or two distinguishing facts. Implementation lives in a new `AeoLedeService` (see design).
- Rephrase headline numbers on **stats pages** (`stats/cluster_show`, `stats/device_type_show`, and the aggregate `stats/dashboard`, `stats/clusters`, `stats/device_types`) so each major figure appears as a standalone sentence with `As of {date}, ...` framing, not just a chart caption. Numbers in declarative sentences are what Princeton called "Statistics Addition."
- Audit and normalize **heading hierarchy** on entity templates to strict H1→H2→H3 (no skipped levels). Where natural, rewrite H2/H3 labels as questions (`"Commands"` → `"What commands does this cluster support?"`). Question-phrasing is opt-in per template, not a global string mangler.

### Track 2 — Structured data expansion

- Add `sameAs` to the Organization JSON-LD on vendor pages, pointing to the vendor's official site. The data is already on hand: `Vendor::vendorLandingPageURL` is populated from DCL's `vendorLandingPageURL` via `app:dcl:sync` and stored in fixtures — this track only needs to wire that field into JSON-LD on `vendor/show`. No migration required.
- Add `BreadcrumbList` JSON-LD mirroring the visual breadcrumbs already present on `device/show`, `vendor/show`, `stats/cluster_show`, `stats/device_type_show`.
- Add `dateModified` (ISO-8601) to every entity's JSON-LD. Source per entity type:
  - **Device**: `MAX(device_versions.updated_at, device_endpoints.updated_at)` for that product
  - **Vendor**: vendor row `updated_at` (or `MAX` over its products)
  - **Cluster / DeviceType**: the `updated_at` of the Cluster/DeviceType row (fixture load timestamp)
- Add `Dataset` schema on aggregate stats pages (`stats/dashboard`, `stats/clusters`, `stats/device_types`, `stats/binding`, `stats/pairings`, `stats/commissioning`, `stats/market`, `stats/versions`) — these *are* datasets and currently advertise themselves as nothing of the sort. Each gets `name`, `description`, `creator`, `license`, `temporalCoverage`, `dateModified`.
- Mirror `dateModified` as a visible `<time datetime="...">` element near the lede on entity pages, so agents that read text (not just JSON-LD) get the same attribution signal.

### Track 3 — Crawler policy

- Replace the blanket `robots.txt` with explicit per-bot allow rules. The site is a public-good registry of an open standard — both **retrieval bots** (OAI-SearchBot, ChatGPT-User, Claude-SearchBot, Claude-User, PerplexityBot, Perplexity-User, Applebot) and **training bots** (GPTBot, ClaudeBot, Google-Extended, Meta-ExternalAgent, Applebot-Extended) are allowed; future models knowing about Matter via this site is a goal, not a leak.
- Keep the wildcard `User-agent: *` `Allow: /` as a final fallback so unknown well-behaved bots still see the same access.
- Keep the existing `Sitemap:` directive untouched.
- File is hand-maintained, not generated — single source of truth, easy to audit.

### Explicitly out of scope

- **`llms.txt`**: 10% site adoption and near-zero crawler fetch rate per 2026 SE Ranking / Codersera data. Revisit if/when major AI vendors formally commit to the protocol.
- **`ai.txt`**: even lower adoption than `llms.txt`.
- **Hugging Face dataset publication**: separate, longer-horizon initiative for training-time inclusion. Not blocked by this change but tracked separately.
- **Vendor-specific tuning** (optimizing copy for ChatGPT vs Claude vs Perplexity): retrieval signals converge; per-engine tuning is not where the lift is.
- **FAQPage JSON-LD on cluster/device-type pages**: deferred. Real Q&A content would need to be authored or sourced; synthetic FAQ blocks are detected and downweighted by 2026-era retrieval models. Reopen if/when we have a curated questions corpus.

## Capabilities

### New Capabilities

- `entity-page-content`: How public entity pages (device, vendor, cluster, device-type) and aggregate stats pages are shaped for both human and agent consumption — definitional lede sentences, statistic-as-sentence phrasing, heading hierarchy, question-phrased headings, visible attribution timestamps.
- `structured-data`: What schema.org JSON-LD the site emits, on which pages, with which fields — including the new `sameAs`, `BreadcrumbList`, `dateModified`, and `Dataset` requirements.
- `crawler-policy`: What `robots.txt` declares — which AI crawler user-agents are permitted, what the default policy is for unknown bots, what the sitemap directive points to.

### Modified Capabilities

None — there is no existing `seo` or `structured-data` spec to delta. The current `openspec/specs/` only contains `observability`.

## Impact

- **Code touched**:
  - New: `src/Service/AeoLedeService.php` (entity-type-keyed lede sentence generation), `src/Twig/AeoExtension.php` (Twig functions wrapping the service), `src/Service/StructuredDataService.php` (centralizes JSON-LD construction so `dateModified` / `BreadcrumbList` / `sameAs` are added in one place rather than duplicated across templates).
  - Modified: `templates/device/show.html.twig`, `templates/vendor/show.html.twig`, `templates/stats/cluster_show.html.twig`, `templates/stats/device_type_show.html.twig`, every `templates/stats/*.html.twig` aggregate page that becomes a `Dataset`, `templates/base.html.twig` if a shared lede block is added.
  - Modified: `public/robots.txt` (full rewrite to per-bot rules).
  - No migrations. `Vendor::vendorLandingPageURL` already populated from DCL is sufficient for `sameAs`.
- **Runtime risk**: low. All changes are additive to render output; lede service is pure and side-effect-free; JSON-LD additions don't displace existing fields. The robots.txt change is a content swap with a wildcard fallback, so reachability for unknown bots is preserved.
- **SEO risk**: the heading-hierarchy normalization could shift visual layout if templates currently use H3 where they should use H2 (or vice versa). Mitigation: visual review of every touched template; CSS targets classes, not heading levels, so style impact should be near-zero.
- **Data freshness risk**: `dateModified` is only useful if it's accurate. The fixture-load timestamp for clusters/device-types means a re-deploy refreshes all of them on the same date — acceptable, but worth documenting so the value isn't read as "last spec change" when it's really "last fixture import."
- **Reversibility**: high per track. Track 3 is a single-file revert. Track 2 is template-and-service edits, revertible. Track 1 is the largest blast radius but isolated to public-facing entity templates and one new service.
- **Testing**:
  - Unit tests for `AeoLedeService` covering each entity type and edge cases (missing description, zero counts, unknown names).
  - WebTestCase assertions on each entity-page template that the lede sentence is present in the first 500 bytes of rendered body and that the JSON-LD includes the new fields.
  - A `robots.txt` smoke test that asserts the file is served with `Content-Type: text/plain` and contains each declared user-agent block.
- **Deployment**: standard rsync deploy, no new infra. No Doctrine migrations. `sameAs` degrades gracefully (field omitted) when `vendorLandingPageURL` is null.
