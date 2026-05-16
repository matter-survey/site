## ADDED Requirements

### Requirement: Centralized JSON-LD construction via StructuredDataService

The site SHALL construct JSON-LD payloads for entity and stats pages through a single `App\Service\StructuredDataService` rather than inline Twig literals. The service SHALL expose one method per JSON-LD output (device, vendor, cluster, device-type, dataset, breadcrumb-list). Each method SHALL return a plain PHP associative array suitable for `json_encode`. Templates SHALL invoke the service via a Twig function and emit the resulting JSON in a `<script type="application/ld+json">` tag.

#### Scenario: Device template emits service-rendered JSON-LD

- **WHEN** `device/show.html.twig` is rendered for any product
- **THEN** the template SHALL invoke `StructuredDataService::deviceJsonLd(...)` via a Twig function rather than constructing the JSON-LD object inline
- **AND** the rendered `<script type="application/ld+json">` payload SHALL be parseable JSON

#### Scenario: Single source of truth for shared fields

- **WHEN** a new shared JSON-LD field is added (e.g. `dateModified`)
- **THEN** the field SHALL be added once in `StructuredDataService` and SHALL appear in every entity's JSON-LD without per-template edits to add it

### Requirement: dateModified on every entity JSON-LD

Every public entity page (device, vendor, cluster, device type) SHALL emit a `dateModified` field in its JSON-LD as an ISO-8601 date string. The source per entity type:

- **Product (device)**: the maximum of `device_versions.updated_at` and `device_endpoints.updated_at` for that product's rows.
- **Vendor (Organization)**: the vendor row `updated_at`, or the maximum `dateModified` of its products, whichever is more recent.
- **Cluster (DefinedTerm)**: the Cluster entity's `updated_at` (fixture-load timestamp).
- **DeviceType (DefinedTerm)**: the DeviceType entity's `updated_at` (fixture-load timestamp).

If a backing column is absent, the migration to add it SHALL be part of this change.

#### Scenario: Device JSON-LD dateModified is populated from telemetry rows

- **WHEN** `device/show.html.twig` renders for a product whose latest `device_endpoints.updated_at` is 2026-05-12 and whose latest `device_versions.updated_at` is 2026-05-09
- **THEN** the emitted Product JSON-LD SHALL contain `"dateModified": "2026-05-12"`

#### Scenario: Cluster JSON-LD dateModified reflects fixture load

- **WHEN** a cluster's row `updated_at` is 2026-05-16 (set during the most recent `doctrine:fixtures:load`)
- **THEN** the emitted DefinedTerm JSON-LD on `stats/cluster_show` SHALL contain `"dateModified": "2026-05-16"`

### Requirement: sameAs on vendor JSON-LD when vendorLandingPageURL is present

Vendor pages SHALL emit a `sameAs` field in their Organization JSON-LD containing the value of `Vendor::vendorLandingPageURL` when that field is non-null and is a syntactically valid HTTP(S) URL. When the field is null or invalid, the `sameAs` property SHALL be omitted from the JSON-LD entirely (not emitted as an empty array or null).

#### Scenario: Vendor with landing page

- **WHEN** a vendor with `vendorLandingPageURL = "https://www.aqara.com"` is rendered on `/vendor/{slug}`
- **THEN** the emitted Organization JSON-LD SHALL contain `"sameAs": ["https://www.aqara.com"]`

#### Scenario: Vendor without landing page

- **WHEN** a vendor with `vendorLandingPageURL = null` is rendered
- **THEN** the emitted Organization JSON-LD SHALL NOT contain a `sameAs` key

### Requirement: BreadcrumbList JSON-LD on entity pages

Every entity page that displays visual breadcrumbs (device/show, vendor/show, stats/cluster_show, stats/device_type_show) SHALL emit a `BreadcrumbList` JSON-LD block reflecting the same breadcrumb chain. Each list item SHALL have `position`, `name`, and `item` (a URL).

#### Scenario: Device page breadcrumb list

- **WHEN** `device/show.html.twig` renders for a device whose visual breadcrumb is `Home › Devices › {Vendor Name} › {Product Name}`
- **THEN** the page SHALL emit a `BreadcrumbList` JSON-LD with four `ListItem` entries in positions 1–4 with names "Home", "Devices", the vendor name, and the product name, each with an `item` URL pointing to the corresponding page

### Requirement: Dataset JSON-LD on aggregate stats pages

Every aggregate stats page (`stats/dashboard`, `stats/clusters`, `stats/device_types`, `stats/binding`, `stats/pairings`, `stats/commissioning`, `stats/market`, `stats/versions`) SHALL emit a `Dataset` JSON-LD block with the fields `name`, `description`, `creator`, `license`, `temporalCoverage`, and `dateModified`.

- `creator` SHALL be an Organization with name "Matter Survey" and `url` set to the site root.
- `license` SHALL be a publicly-resolvable URL of the license under which the aggregate statistics are made available. The default SHALL be CC0 (`https://creativecommons.org/publicdomain/zero/1.0/`) subject to owner confirmation at implementation time.
- `temporalCoverage` SHALL be an ISO-8601 interval covering the period the data represents.
- `dateModified` SHALL be the timestamp of the most recent data refresh underlying the page's statistics.

#### Scenario: Dashboard emits Dataset markup

- **WHEN** `/stats` renders
- **THEN** the page SHALL include a `<script type="application/ld+json">` block whose parsed object is a `@type: "Dataset"` with non-empty `name`, `description`, `creator`, `license`, `temporalCoverage`, and `dateModified` fields

#### Scenario: Dataset markup validates against schema.org

- **WHEN** the rendered HTML of any aggregate stats page is submitted to the Schema.org validator or Google Rich Results Test
- **THEN** the Dataset JSON-LD SHALL pass validation with no errors

### Requirement: JSON-LD description matches visible lede

For each entity page, the `description` field of its JSON-LD payload SHALL be byte-identical to the visible lede sentence rendered on the page.

#### Scenario: JSON-LD description equals visible lede

- **WHEN** a cluster page renders for any cluster
- **THEN** the value of `description` in the DefinedTerm JSON-LD SHALL equal the text content of the `.aeo-lede` element on the same page, character for character
