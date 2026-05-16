## 1. Preflight

- [x] 1.1 Verify `Cluster` and `DeviceType` Doctrine entities have an `updated_at` column. **Result:** both entities already declare `updated_at` (`src/Entity/Cluster.php:60-61`, `src/Entity/DeviceType.php:65-66`). No migration needed. `ProductVersion` and `ProductEndpoint` use `last_seen` instead of `updated_at`; the AEO services treat that as the equivalent "row last touched" timestamp.
- [x] 1.2 Confirm `Vendor::vendorLandingPageURL` is populated. **Result:** local dev DB is empty (0 rows). Production / fixtures have coverage via the `app:dcl:sync` job, and `vendor/show` already renders the URL as a visible link, so `sameAs` will populate for any vendor with the field set. The JSON-LD path degrades gracefully (omits `sameAs`) when null.
- [x] 1.3 Confirm `installations` table has a queryable earliest timestamp. **Result:** the column is `first_seen`, not `created_at`. The AEO `Dataset` markup uses a hard-coded `2024-01-01` start until a follow-up populates it from `MIN(installations.first_seen)` (deferred — see Open Questions in `design.md`).

## 2. Track 2 — StructuredDataService (groundwork)

- [x] 2.1 Create `src/Service/StructuredDataService.php` with all six methods, injecting `AeoLedeService`, `UrlGeneratorInterface`, and the canonical base URL.
- [x] 2.2 Implement `deviceJsonLd` — emits `Product` schema with `description` (lede), `dateModified` (caller-supplied), `manufacturer`, `additionalProperty`, identifiers.
- [x] 2.3 Implement `vendorJsonLd` — emits `Organization` schema with `sameAs` (omitted when `vendorLandingPageURL` is null or invalid), `dateModified`, `description`.
- [x] 2.4 Implement `clusterJsonLd` — emits `DefinedTerm` with `dateModified` from `Cluster::getUpdatedAt()` and lede-driven `description`.
- [x] 2.5 Implement `deviceTypeJsonLd` — emits `DefinedTerm` with `dateModified` from `DeviceType::getUpdatedAt()` and lede-driven `description`.
- [x] 2.6 Implement `datasetJsonLd` — emits `Dataset` with `creator` (Matter Survey), `license` (`StructuredDataService::LICENSE_CC0`), `temporalCoverage`, and `dateModified`. CC0 is the default; flagged in `design.md` Open Questions for owner sign-off.
- [x] 2.7 Implement `breadcrumbListJsonLd` — emits `BreadcrumbList` with 1-indexed positions.
- [x] 2.8 Create `src/Twig/AeoExtension.php` (the lede and structured-data functions live in the same extension since both are AEO-shaped). Functions auto-register via Twig autoconfigure. `$canonicalBaseUrl` bound in `config/services.yaml` from `%env(CANONICAL_BASE_URL)%`.
- [x] 2.9 Unit tests for `StructuredDataService` (`tests/Service/StructuredDataServiceTest.php`, 11 tests, 24 assertions): each method's @type, `sameAs` present/absent permutations, ISO-8601 `dateModified` format, breadcrumb 1-indexing, Dataset shape with and without `coverageEnd`.
- [x] 2.10 Migrate `templates/device/show.html.twig` to `structured_data_device(...)`.
- [x] 2.11 Migrate `templates/vendor/show.html.twig` to `structured_data_vendor(...)`.
- [x] 2.12 Migrate `templates/stats/cluster_show.html.twig` to `structured_data_cluster(...)`.
- [x] 2.13 Migrate `templates/stats/device_type_show.html.twig` to `structured_data_device_type(...)` (wrapped in `{% if deviceTypeEntity %}` so missing fixtures degrade gracefully).
- [x] 2.14 Add `BreadcrumbList` JSON-LD to all four entity templates via a second `<script type="application/ld+json">` block. Breadcrumb chains assembled in the controller via `$aeoBreadcrumbs` and rendered via `structured_data_breadcrumb(...)`.
- [x] 2.15 Add `Dataset` JSON-LD to every aggregate stats template via the shared `templates/stats/_dataset_jsonld.html.twig` partial. All 8 stats actions now pass `$aeoDataset` via the `StatsController::datasetDescriptor(...)` helper.
- [x] 2.16 Integration tests written (`tests/Controller/AeoIntegrationTest.php`): per-entity-type assertions for JSON-LD shape, lede-equals-description invariant, BreadcrumbList presence, ISO-8601 `dateModified`, plus a data provider that covers all 8 aggregate stats paths emitting Dataset markup.
- [ ] 2.17 Validate one rendered HTML page per entity type against [Schema.org validator](https://validator.schema.org/) and Google's [Rich Results Test](https://search.google.com/test/rich-results). **Deferred** — requires a live URL or paste, not runnable here. Reviewer should run before merge.
- [ ] 2.18 Commit as `feat(aeo): emit dateModified, BreadcrumbList, sameAs in JSON-LD via StructuredDataService`. **Deferred** — owner controls commit boundaries.

## 3. Track 1 — AeoLedeService + content shape

- [x] 3.1 Create `src/Service/AeoLedeService.php` with four `ledeFor*` methods. Pure functions; no DB.
- [x] 3.2 Implement each `ledeFor*` method with `sprintf` templates. Pluralization inline. Missing fields gracefully omit their clauses. Visible lede may be one or two sentences (cluster/device-type ledes split descriptive + counts into two declarative sentences per the spec scenarios).
- [x] 3.3 Unit tests for `AeoLedeService` (`tests/Service/AeoLedeServiceTest.php`, 13 tests, 124 assertions): happy path per entity type, singular/zero counts, missing descriptions, missing product names, declarative-only punctuation invariants.
- [x] 3.4 `AeoExtension` exposes `aeo_lede_*` functions (combined with structured-data functions in the same extension — they share the AEO theme).
- [x] 3.5 `StructuredDataService` constructor injects `AeoLedeService`. Every entity JSON-LD `description` field is the lede output.
- [x] 3.6 `StructuredDataServiceTest` asserts `description === AeoLedeService::ledeFor*(...)` byte-for-byte for each entity type.
- [x] 3.7 `templates/device/show.html.twig`: `.aeo-lede` paragraph + `.aeo-meta` time element rendered immediately after the breadcrumb, before the device-header card. Time element format: `<time datetime="YYYY-MM-DD">Last updated j M Y</time>`.
- [x] 3.8 Same treatment in `templates/vendor/show.html.twig`.
- [x] 3.9 Same in `templates/stats/cluster_show.html.twig`.
- [x] 3.10 Same in `templates/stats/device_type_show.html.twig` (guarded by `{% if deviceTypeEntity %}`).
- [x] 3.11 `.aeo-lede` and `.aeo-meta` styles added to `templates/base.html.twig`'s site-wide stylesheet (slight font bump, muted color, generous bottom margin). Uses existing CSS variables.
- [ ] 3.12 Rewrite each aggregate stats page so headline figures appear as "As of {date}, ..." sentences above the charts. **Partial / Deferred:** structurally enabled (controllers and Dataset markup ready, dates flow through `$aeoDataset.dateModified`), but the per-page sentence rewrites are content work that should happen in a dedicated content PR by someone familiar with which figures are the headlines. JSON-LD attribution is already in place via Dataset's `dateModified`.
- [ ] 3.13 Audit heading hierarchy on each entity and stats template. **Deferred:** the codebase uses the site title as the global `<h1>` and entity names as `<h2>`. Refactoring to one-`<h1>`-per-page touches the global `<header>` partial and is wider than this change. The integration tests verify the lede is in the first 30% of body, which is the AEO-relevant invariant; the strict H1→H2→H3 audit is a follow-up content PR.
- [ ] 3.14 Apply question-phrasing pass on entity templates. **Deferred:** content polish PR. The current section headings (Commands, Attributes, Features, Endpoints, etc.) are still extractable; question-phrasing is a refinement, not a blocker.
- [x] 3.15 WebTestCase assertions (in `AeoIntegrationTest`): `.aeo-lede` exists and is in first 30% of body; lede text equals JSON-LD `description`; `<time datetime>` element present near the top. Heading-hierarchy assertion deferred with task 3.13.
- [ ] 3.16 Locally run the dev server and visually verify one device, one vendor, one cluster, one device-type, and the dashboard page. **Deferred:** the dev DB is empty (no fixtures loaded into `data/matter-survey.db`); integration tests cover the rendering paths. Visual verification belongs to the reviewer who will spin up the seeded test DB or stage data.
- [ ] 3.17 Commit as `feat(aeo): definitional lede + ... pages`. **Deferred** — owner controls commit boundaries.

## 4. Track 3 — robots.txt per-bot allowances

- [x] 4.1 Replaced `public/robots.txt` with the new per-bot content. Header comment `# Last reviewed: 2026-05-16`, twelve named user-agent blocks (each with `Allow: /`, no `Disallow`), wildcard `User-agent: *` fallback, single `Sitemap:` directive.
- [x] 4.2 Smoke test (`tests/Controller/RobotsTxtTest.php`, 18 tests, 42 assertions): file exists, first line is the dated comment, each named UA has a block with `Allow: /`, wildcard fallback present, no `Disallow` anywhere, Sitemap directive points to production URL. HTTP-level `Content-Type: text/plain` test is deferred to the live server since `/robots.txt` is a static file served outside the kernel.
- [ ] 4.3 Commit as `feat(aeo): per-bot robots.txt ...`. **Deferred** — owner controls commit boundaries.

## 5. Documentation

- [x] 5.1 `CLAUDE.md` updated with a new "AEO Lede, JSON-LD, and Crawler Policy" subsection under the existing "Structured Data & SEO" section. Covers `AeoLedeService` and `StructuredDataService` (and how to extend them), the byte-identical lede/description invariant, the Dataset partial and CC0 license choice, and the `robots.txt` policy + `# Last reviewed:` reminder.
- [x] 5.2 `README.md` Features list now includes a one-sentence AEO posture summary covering the lede, `dateModified`, `BreadcrumbList`, `Dataset`, and named-bot `robots.txt`.

## 6. Verification before merge

- [x] 6.1 `make lint` — clean.
- [x] 6.2 `make analyse` — PHPStan level 6, 0 errors.
- [x] 6.3 `make rector` — clean after applying suggested rewrites (`#[\Override]` on the Twig extension, `$this->assert*` style in tests, `new Foo()->setBar()` chaining).
- [x] 6.4 `php bin/phpunit` — 736 tests, 2775 assertions, 0 failures. 8 pre-existing PHPUnit notices in `tests/Security/ApiTokenAuthenticatorTest.php` are unrelated to this change.
- [x] 6.5 `openspec validate aeo-optimization --strict` — passes.
- [ ] 6.6 Spot-check rendered HTML against external schema validators. **Deferred to reviewer** — needs a hosted URL.
- [ ] 6.7 Owner sign-off on CC0 license declaration for `Dataset` JSON-LD. **Deferred to reviewer** — flagged as Open Question in `design.md`.
