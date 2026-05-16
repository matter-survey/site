## Context

The site already has solid classic-SEO scaffolding: a sitemap index with per-type sub-sitemaps, JSON-LD on every public entity (Product, Organization, DefinedTerm, WebSite + SearchAction), canonical + hreflang (#222), fully SSR templates, and `nelmio_cors` set up. What's missing is the *agent-citation* layer: a lede that an LLM can quote verbatim, JSON-LD fields agents actually read (`dateModified`, `BreadcrumbList`, `sameAs`, `Dataset`), stats phrased as standalone sentences rather than chart captions, and a `robots.txt` that explicitly addresses the agent crawler taxonomy.

The codebase constraints that shape the design:

- **Symfony 7.4 / PHP 8.5**, MicroKernel, attribute routing. Templates are Twig, extending a single `templates/base.html.twig` that already exposes a `structured_data` block.
- **Hosting** is KAS shared host (PHP-FPM), no extensions installable, deploy is rsync over SSH. Rules out anything requiring runtime services beyond the SQLite file already in place.
- **Data shape** is well-suited: `Vendor::vendorLandingPageURL` is already populated from DCL, every Cluster/DeviceType has `name`, `description`, `hexId`, JSON cluster requirement arrays, etc. The lede service can be a pure function over data already at hand.
- **Existing JSON-LD** is template-resident (each `templates/.../show.html.twig` builds its own JSON object inline). That works for the simple cases but invites drift the moment we add `dateModified` and `BreadcrumbList` everywhere — five templates each maintaining their own copy of the same fields.
- **Recent work**: #221 added security headers + tightened OpenAPI, #222 added canonical + hreflang. The CORS/SEO surface has been touched recently so contributors are warm on the area.

## Goals / Non-Goals

**Goals:**

- Every entity page (device, vendor, cluster, device-type) opens with a single declarative sentence containing the entity's name, identifier, and one or two distinguishing facts — agent-quotable, generated from data already in the database, no copywriting required per page.
- Every aggregate stats page contains its headline number in a standalone sentence beginning with `As of {date}, ...`, so the figure is liftable into AI Overview answers without the agent having to chart-read.
- Every entity page emits `dateModified` in its JSON-LD and a visible `<time>` element near the lede.
- Every entity page emits `BreadcrumbList` JSON-LD matching its visual breadcrumb.
- Vendor pages emit `sameAs` when `vendorLandingPageURL` is non-null.
- Aggregate stats pages emit `Dataset` JSON-LD with creator, license, temporal coverage, and `dateModified`.
- `robots.txt` enumerates each AI crawler user-agent the site cares about, with explicit `Allow: /` for each.
- One single source of truth for the lede sentences and one single source of truth for shared JSON-LD fields — no template-level copy/paste.

**Non-Goals:**

- Hand-written copy per entity. The lede is generated from data; if data is missing the lede gracefully omits the missing clause.
- A content management UI for vendor `sameAs` overrides. DCL is authoritative; this change does not introduce manual editing.
- Engine-specific tuning. Agent retrieval signals converge in 2026; per-engine forks are not worth the maintenance overhead.
- FAQPage JSON-LD. Deferred until/unless we have a curated questions corpus — synthetic FAQ is downweighted.
- `llms.txt` / `ai.txt`. See proposal Out of Scope; revisit when adoption shifts.
- A new admin surface for tuning lede templates. The strings live in PHP code (or Twig macros), versioned with the rest of the app.

## Decisions

### Decision 1: Lede generation in a Service, exposed as a Twig function

A new `App\Service\AeoLedeService` exposes:

```php
public function ledeFor(Product $product): string;
public function ledeFor(Vendor $vendor): string;
public function ledeFor(Cluster $cluster): string;
public function ledeFor(DeviceType $deviceType): string;
```

…or, more honestly given PHP's lack of method overloading, four named methods (`ledeForDevice`, `ledeForVendor`, `ledeForCluster`, `ledeForDeviceType`) so call sites in Twig stay explicit.

A `App\Twig\AeoExtension` wraps each method as a Twig function (`aeo_lede(device)`, `aeo_lede_cluster(cluster, totalDevices)`, etc.), so templates can render:

```twig
<p class="aeo-lede">{{ aeo_lede_cluster(cluster, totalDevices) }}</p>
```

**Why a service rather than pure Twig macros:**

- Lede sentences need to read repository data (e.g. "implemented by N device types") for clusters. A service can take a `MatterRegistry` dependency; a macro can only operate on what the template passes in.
- Unit-testable in PHPUnit without spinning up Twig.
- One canonical implementation, one canonical test surface.

**Why a Twig function rather than a controller-injected variable:**

- Eight templates would need the variable plumbed through eight controller methods. A Twig function is added once.
- Composition: the function can be called inside the `structured_data` block as well as inside the body, so the lede that appears as JSON-LD `description` is byte-identical to the lede that appears visibly.

**Alternative considered:** generating the lede in the controller and passing it as `$lede` to the template. Rejected — every controller action gets new boilerplate, and the variable name has to be remembered consistently. The Twig-function form is one line at the call site and zero changes to controllers.

### Decision 2: Lede sentence templates — fill-in-the-blank, not LLM-generated

The lede strings are hand-authored PHP/Twig templates with `sprintf`-style placeholders, e.g.:

```
"The %s cluster (%s) is a Matter cluster %s. It defines %d command%s and %d attribute%s, and is %s for %d device type%s."
```

filled from `$cluster->getName()`, `$cluster->getHexId()`, `$cluster->getDescription()`, command/attribute array counts, and a Doctrine query for mandatory-for count.

**Why not LLM-generated:** non-deterministic, requires runtime API access (which KAS doesn't have configured), hallucination risk on the exact field we want agents to trust. The data is structured; templating is sufficient.

**Why one template per entity type, not a generic builder:** each entity type's distinguishing facts are different (a device has vendor + version + endpoint count; a cluster has commands + attributes + applicable device types). Four sprintf calls is less complex than a generic field-bag-with-conditional-grammar.

**Pluralization:** handled inline (`$n !== 1 ? 's' : ''`). Adequate for English-only. A second locale would warrant Symfony's translator's `transChoice`, but the site is single-locale at the entity-content level today (UI strings are translated, entity names/descriptions come from upstream DCL/ZAP in English).

### Decision 3: A `StructuredDataService` centralizes shared JSON-LD fields

The five entity templates currently each build their own JSON-LD literal inline. Adding `dateModified`, `BreadcrumbList`, and `sameAs` everywhere via copy/paste guarantees drift. A new service:

```php
final class StructuredDataService
{
    public function deviceJsonLd(Product $product, array $endpoints): array;
    public function vendorJsonLd(Vendor $vendor, int $deviceCount): array;
    public function clusterJsonLd(Cluster $cluster, int $totalDevices): array;
    public function deviceTypeJsonLd(DeviceType $dt, int $totalDevices): array;
    public function datasetJsonLd(string $name, string $description, \DateTimeInterface $coverageStart, ?\DateTimeInterface $coverageEnd, \DateTimeInterface $dateModified): array;
    public function breadcrumbListJsonLd(array $crumbs): array; // [{name, url}, ...]
}
```

Returns plain PHP arrays. A Twig function (`structured_data_device(device, endpoints)`) wraps each method and the template emits:

```twig
<script type="application/ld+json">
{{ structured_data_device(device, endpoints)|json_encode(constant('JSON_UNESCAPED_SLASHES'))|raw }}
</script>
```

**Why a service rather than inline Twig literals:**

- One file to add `dateModified` to instead of five.
- PHPUnit-testable: assert structure independently of template rendering.
- The current inline-Twig approach has already produced bugs (`json_encode|raw` on every leaf, easy to forget) — the service centralizes that concern.

**Migration path:** template-by-template. Each entity template gets converted in its own commit. Old inline JSON-LD is removed in the same diff. Visual-only review per template.

**Alternative considered:** a generator-style fluent builder (`->withName(...)->withSameAs(...)->build()`). Rejected — overengineered for five entity types and ~ten fields. Four named methods is plenty.

### Decision 4: `dateModified` source per entity type — fixture-load timestamp for spec entities, real `updated_at` for telemetry entities

The cluster and device-type entities are loaded from YAML fixtures. Their `updated_at` (already on the Doctrine entities — `Cluster::$updatedAt`, `DeviceType::$updatedAt` — verify in code; add via migration if absent) reflects fixture load time, not "last time the Matter spec changed." For an agent quoting "as of May 2026 according to matter-survey...", that's the honest answer: it's when our snapshot was taken.

For devices and vendors, real telemetry drives changes. The `dateModified` for a device is `MAX(device_versions.updated_at, device_endpoints.updated_at)` for that product. For a vendor it's `MAX` over its products' `dateModified`.

**Why not a single global "data last updated" timestamp:** less honest (a single number hides which entities are stale vs fresh) and less useful (agents care about entity-level freshness, not site-level).

**Verification step in tasks:** confirm Cluster and DeviceType have an `updated_at` column. If not, a tiny Doctrine migration adds it and the next fixture load populates it.

### Decision 5: Heading question-phrasing is opt-in per template, not a global Twig filter

The research suggests phrasing H2/H3 as questions matches user query patterns. But mechanical rephrasing produces awkward English: "Commands" → "What are commands?" is worse than the original. The rule:

- Every entity template gets a manual review pass.
- Where a question phrasing reads naturally ("What commands does the OnOff cluster support?"), use it.
- Where it doesn't ("Endpoints" → "What endpoints does this device expose?" — fine; "Vendor ID" → "What is the vendor ID?" — terrible, skip), leave as-is.
- The hierarchy normalization (H1→H2→H3, no skips) is non-negotiable and audited globally.

**Why not automate:** automation produces uniformly mediocre prose. Hand-tuning is a one-time cost for five templates.

### Decision 6: `Dataset` JSON-LD on aggregate stats pages — license, creator, temporalCoverage

Each `stats/*.html.twig` aggregate page (dashboard, clusters, device_types, binding, pairings, commissioning, market, versions) emits:

```json
{
  "@context": "https://schema.org",
  "@type": "Dataset",
  "name": "...",
  "description": "...",
  "creator": { "@type": "Organization", "name": "Matter Survey", "url": "https://matter-survey.org/" },
  "license": "https://creativecommons.org/publicdomain/zero/1.0/",
  "temporalCoverage": "2024-01-01/..",
  "dateModified": "..."
}
```

**License decision:** CC0 (`creativecommons.org/publicdomain/zero/1.0/`). The aggregate statistics are anonymized telemetry counts; the site's purpose is public-good registry of an open standard. CC0 maximizes citation surface (no attribution requirement to keep agents from quoting freely). **This is a stated decision in this design**, not previously declared elsewhere — flag for owner sign-off before merge.

**`temporalCoverage`**: `"2024-01-01/.."` (open-ended ISO 8601 interval, "from project start to ongoing"). Concrete start date is the first telemetry submission; check the `installations` table for the actual min.

**`creator`**: hard-coded to "Matter Survey" / site root URL. Not a person.

### Decision 7: `robots.txt` is hand-maintained, not generated

Eight to twelve `User-agent:` blocks fit on one screen. A controller-rendered `robots.txt` would invite "let's make it dynamic" feature creep with no benefit. Keep it as a static file under `public/robots.txt`, list the bots, and let the file be the spec.

**Allowed crawlers** (each gets `Allow: /` and no `Disallow`):

| Operator | Training UA | Retrieval / on-demand UA |
| --- | --- | --- |
| OpenAI | `GPTBot` | `OAI-SearchBot`, `ChatGPT-User` |
| Anthropic | `ClaudeBot` | `Claude-SearchBot`, `Claude-User` |
| Google | `Google-Extended` | (Googlebot covered by wildcard) |
| Perplexity | — | `PerplexityBot`, `Perplexity-User` |
| Apple | `Applebot-Extended` | `Applebot` |
| Meta | `Meta-ExternalAgent` | — |

Plus a final block:

```
User-agent: *
Allow: /

Sitemap: https://matter-survey.org/sitemap.xml
```

**Why allow training bots:** the site is a public-good registry of an open standard. Future models knowing about Matter via this site is a stated goal. Reopen the decision if/when commercialization plans change.

**Bytespider and other known-non-compliant crawlers:** out of scope for robots.txt; their handling is a WAF/CDN concern (and we don't have one). Not blocking this change.

## Risks / Trade-offs

- [Lede sentences drift from data] Lede is generated at render time from current DB state, so drift is structural-impossible. ✓
- [Lede sentence reads awkwardly for edge cases] e.g. a cluster with zero commands or a device with no endpoints. → Mitigate with unit tests covering zero/null/empty cases; each entity-type lede method handles its own edge cases and falls back to a minimal sentence rather than emitting "0 command s".
- [Heading hierarchy normalization breaks CSS] → CSS in this repo targets classes, not heading levels (spot-checked in `templates/stats/cluster_show.html.twig` extra_styles). Visual review per template as part of the audit task.
- [`dateModified` reads as "spec last changed" but is really "fixture last loaded"] → Documented in CLAUDE.md under the new Structured Data section; agents will quote it as our data freshness regardless.
- [Per-bot robots.txt becomes stale as new crawlers launch] → File is small enough to audit quarterly; we add a `# Last reviewed: YYYY-MM-DD` comment header.
- [License declaration on Dataset is a legal claim] → CC0 chosen as least-restrictive and aligned with public-good positioning. Flag for owner sign-off before merge.
- [The lede service depends on entity associations that are not eager-loaded] → Add explicit fetch joins or `select` clauses in the controllers that render entity pages to avoid N+1 from the lede service. Caught by the existing PHPStan + manual query logging during dev.
- [Schema.org may reject some field choices] → All four schema types used (`Product`, `Organization`, `DefinedTerm`, `Dataset`) and all added properties (`sameAs`, `dateModified`, `BreadcrumbList`) are standard. Validate with the schema.org validator and Google's Rich Results Test as part of testing.

## Migration Plan

Ship in **three commits**, one per track. Each is independently revertible.

1. **`feat(aeo): emit dateModified, BreadcrumbList, sameAs in JSON-LD`** — Track 2. Introduces `StructuredDataService`, migrates the five entity templates from inline JSON-LD to service-rendered. No visible UI change.
2. **`feat(aeo): definitional lede + statistics-sentence framing on entity and stats pages`** — Track 1. Introduces `AeoLedeService` + `AeoExtension`, adds lede to entity templates, rewrites headline stats as standalone sentences, audits heading hierarchy, opt-in question-phrasing where natural.
3. **`feat(aeo): per-bot robots.txt with explicit retrieval and training allowances`** — Track 3. One-file change.

Order rationale: Track 2 is the cleanest groundwork (centralizes JSON-LD before Track 1 starts depending on it). Track 1 is the largest. Track 3 is independent and could ship in any position.

**Rollback:** any commit is `git revert`-able in isolation. The `StructuredDataService` from Track 2 stays installed even if Track 1 reverts; Track 3 reverts cleanly to today's blanket robots.txt.

**Verification per commit:** `make lint`, `make analyse`, `make rector`, `php bin/phpunit`. Visual smoke test of one device, one vendor, one cluster, one device-type, one stats page locally on `php -S localhost:8000`. Validate JSON-LD with [Schema.org Validator](https://validator.schema.org/) and [Google Rich Results Test](https://search.google.com/test/rich-results) on at least one sample of each entity type before push to main.

## Open Questions

- **Dataset license**: CC0 proposed. Owner sign-off requested. If a non-CC0 license is chosen, the `license` URL changes; no other code shifts.
- **`temporalCoverage` start date**: should be set from the actual min `installations.created_at` rather than hard-coded `2024-01-01`. Decide whether to query at render time (cached) or hard-code at the value of the first known import. Suggest: hard-code with a comment, revisit annually.
- **Cluster / DeviceType `updated_at` column**: needs verification. If absent, a one-column migration is added in commit 1 (Track 2). If present, no migration.
- **Lede visible placement**: directly below the H1, as a single paragraph, or in a styled "definition" block? Suggest: simple `<p class="aeo-lede">` directly below H1, styled to look intentional (slight indent, slightly larger text, muted color). Visual designer review optional.
- **Statistics-sentence framing on aggregate stats pages**: should it be a section heading sentence ("As of May 2026, 87% of Matter devices implement the OnOff cluster.") or a sentence embedded in the chart's caption block? Suggest: section-heading-sentence above the chart, chart caption stays as-is for human reading. Two sentences saying the same thing in different registers (formal claim + chart caption) is fine and matches how news outlets structure data graphics.
- **Heading question-phrasing audit**: do per-template once during implementation, or split into a follow-up content PR? Suggest: in-band, since the templates are open in the editor anyway during the lede work.
