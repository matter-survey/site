## ADDED Requirements

### Requirement: Definitional lede sentence on every entity page

Every public entity page (device, vendor, cluster, device type) SHALL render a single declarative sentence at the top of the visible page body, immediately following the H1, that names the entity, identifies it, and states one or two distinguishing facts. The sentence MUST be generated from data already present on the entity at render time, MUST be plain prose (no markup besides the wrapping element), and MUST appear within the first 30% of the rendered HTML body to align with where LLM retrievers extract citations.

The lede sentence content MUST be byte-identical to the `description` field used in the JSON-LD for the same entity, so visible text and structured data agree.

#### Scenario: Device page lede

- **WHEN** a public visitor (or crawler) requests `/device/{slug}` for a device with vendor name "Aqara", product name "FP2", vendor ID `0x115F`, product ID `0x2002`, and three endpoints
- **THEN** the rendered HTML SHALL contain a sentence of the form "The Aqara FP2 is a Matter device (Vendor 0x115F, Product 0x2002) with 3 endpoints." within the first 30% of the document body
- **AND** the same sentence SHALL appear as the `description` field of the Product JSON-LD

#### Scenario: Cluster page lede

- **WHEN** a visitor requests `/stats/cluster/0x0006` for the OnOff cluster which has 6 commands, 4 attributes, and is mandatory for 12 device types
- **THEN** the rendered HTML SHALL contain a sentence of the form "The OnOff cluster (0x0006) is a Matter cluster that provides on/off control for endpoints. It defines 6 commands and 4 attributes, and is mandatory for 12 device types." within the first 30% of the document body

#### Scenario: Vendor page lede

- **WHEN** a visitor requests `/vendor/{slug}` for a vendor named "Aqara" with 47 known Matter devices
- **THEN** the rendered HTML SHALL contain a sentence of the form "Aqara is a Matter device vendor with 47 known Matter-certified products in the Matter Survey registry." within the first 30% of the document body

#### Scenario: Device type page lede

- **WHEN** a visitor requests `/stats/device-type/{hexId}` for "OnOff Light" (0x0100) which has N mandatory clusters and M optional clusters
- **THEN** the rendered HTML SHALL contain a sentence of the form "The OnOff Light (0x0100) is a Matter device type that defines a basic on/off lighting endpoint, requiring N mandatory clusters and supporting M optional clusters." within the first 30% of the document body

#### Scenario: Lede degrades gracefully on missing data

- **WHEN** the entity is missing fields normally included in the lede (e.g. a cluster with no `description`, or a device with zero endpoints)
- **THEN** the lede SHALL omit the missing clause rather than emit "0 endpoints" or "null description"
- **AND** SHALL still produce a grammatical sentence containing at least the entity name and identifier

### Requirement: Statistics-as-sentence framing on aggregate stats pages

Every aggregate stats page (dashboard, clusters, device types, binding, pairings, commissioning, market, versions) SHALL present each headline figure as a standalone declarative sentence containing the figure inline, in addition to any chart visualization. The sentence MUST begin with an attribution phrase of the form `"As of {ISO-8601 date}, ..."` so the figure is liftable into an AI answer with its temporal context intact.

#### Scenario: Cluster usage stat on dashboard

- **WHEN** the stats dashboard renders and 87% of submitted devices implement the OnOff cluster as of 2026-05-16
- **THEN** the page SHALL contain a sentence of the form "As of 2026-05-16, 87% of submitted Matter devices implement the OnOff cluster (0x0006)." adjacent to the corresponding chart

#### Scenario: Stat sentence is server-rendered

- **WHEN** a crawler that does not execute JavaScript fetches an aggregate stats page
- **THEN** every statistics-as-sentence framing SHALL be present in the initial server-rendered HTML response body, not added by client-side JavaScript

### Requirement: Strict heading hierarchy on entity and stats pages

Every public-facing entity page and aggregate stats page SHALL use a strict H1 → H2 → H3 heading hierarchy with no skipped levels. There SHALL be exactly one H1 per page, corresponding to the entity name or page title. Section headings SHALL be H2. Sub-section headings under an H2 SHALL be H3. No template SHALL emit an H3 or H4 without an enclosing H2.

#### Scenario: Cluster page heading audit

- **WHEN** the rendered HTML of `/stats/cluster/{hexId}` is parsed for heading elements
- **THEN** there SHALL be exactly one `<h1>` element
- **AND** every `<h3>` SHALL be preceded by an `<h2>` earlier in the document tree
- **AND** no `<h4>` or deeper level SHALL appear unless an `<h3>` precedes it in the same section

### Requirement: Question-phrased headings where natural

Section headings on entity pages SHOULD be phrased as questions where the resulting phrasing reads naturally in English (e.g. "What commands does this cluster support?" rather than "Commands"). The question-phrasing rule SHALL be applied template-by-template, not by a global string transformation, and SHALL be skipped for any heading where the question form reads worse than the noun form (e.g. "Vendor ID").

#### Scenario: Cluster page commands heading

- **WHEN** the cluster page template renders the section listing the cluster's commands
- **THEN** the section heading SHALL be phrased as a question (e.g. "What commands does the {cluster name} cluster support?") rather than a bare noun phrase ("Commands")

### Requirement: Visible attribution timestamp near the lede

Every entity page SHALL render a visible `<time datetime="{ISO-8601}">` element near the lede sentence (within the first 30% of the body) displaying the entity's `dateModified` value, so retrieval crawlers that read prose rather than JSON-LD obtain the same attribution signal.

#### Scenario: Device page shows dateModified visibly

- **WHEN** a visitor or crawler fetches a device page whose `dateModified` is 2026-05-10
- **THEN** the rendered HTML SHALL contain a `<time datetime="2026-05-10">` element within the first 30% of the body
- **AND** the displayed text SHALL be a human-readable date (e.g. "Last updated 10 May 2026")
