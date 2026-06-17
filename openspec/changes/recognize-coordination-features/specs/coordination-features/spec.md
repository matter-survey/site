## ADDED Requirements

### Requirement: Coordination cluster detection

The system SHALL derive support for the three Matter coordination primitives — Binding, Groups, and Scenes — from a device's telemetry-reported endpoint clusters, exposing each as a boolean on the device summary.

- Binding support SHALL be true when cluster `30` is present on any endpoint's server **or** client cluster list (preserving existing behavior).
- Groups support SHALL be true when cluster `4` is present on any endpoint's server cluster list.
- Scenes support SHALL be true when **either** cluster `98` (Scenes Management) **or** cluster `5` (deprecated Scenes) is present on any endpoint's server cluster list.

#### Scenario: Device with current Scenes Management cluster
- **WHEN** a device reports server cluster `98` on an endpoint
- **THEN** its summary `supports_scenes` is true

#### Scenario: Device with legacy Scenes cluster only
- **WHEN** a device reports server cluster `5` but not `98`
- **THEN** its summary `supports_scenes` is true

#### Scenario: Device with Groups cluster
- **WHEN** a device reports server cluster `4` on an endpoint
- **THEN** its summary `supports_groups` is true

#### Scenario: Device without coordination clusters
- **WHEN** a device reports no coordination clusters (no `4`, `5`, `30`, or `98`)
- **THEN** `supports_binding`, `supports_groups`, and `supports_scenes` are all false

### Requirement: Device summary exposes coordination columns

The `product_summary`/`device_summary` SQL view SHALL expose `supports_binding`, `supports_groups`, and `supports_scenes` columns aggregated across all of a product's endpoints, so list, facet, and badge surfaces can read support without recomputing from raw cluster JSON.

#### Scenario: Aggregation across endpoints
- **WHEN** a product has Groups on one endpoint and Scenes on another
- **THEN** the summary row reports both `supports_groups` and `supports_scenes` as true

### Requirement: Role-aware device-page coordination badges

A device detail page SHALL display coordination support as distinct, role-aware badges grouped under a single heading, preserving the controller-side vs. controlled-side distinction. Binding (controller-side) SHALL be labeled distinctly from Groups and Scenes (controlled-side).

#### Scenario: Controller-side device
- **WHEN** a device supports Binding but not Groups or Scenes
- **THEN** the page shows a "Controls devices directly" badge (with a hub-less control explainer) and no group/scene badge

#### Scenario: Controlled-side device
- **WHEN** a device supports Groups and Scenes but not Binding
- **THEN** the page shows a "Group control" badge and a "Scene control" badge, and no binding badge

#### Scenario: No coordination support
- **WHEN** a device supports none of the three
- **THEN** no coordination badges are rendered (or a single explicit "no coordination" state, consistent with the existing no-binding treatment)

### Requirement: Coordination facet filtering on the device list

The device list SHALL provide a "Coordination" filter group with three independent toggles (binding, groups, scenes), each showing a count of matching devices, and SHALL filter the listing by any combination of selected toggles.

#### Scenario: Filter by a single coordination feature
- **WHEN** a user selects the "groups" toggle
- **THEN** only devices with `supports_groups = true` are listed and the binding/scenes toggles remain available

#### Scenario: Combine coordination filters
- **WHEN** a user selects both "groups" and "scenes"
- **THEN** only devices supporting both are listed

#### Scenario: Facet counts reflect the dataset
- **WHEN** the device list loads
- **THEN** each coordination toggle shows the count of devices supporting that feature

### Requirement: Unified coordination statistics page

The site SHALL provide a single `/stats/coordination` page presenting aggregate support for Binding, Groups, and Scenes, including a per-category breakdown, and SHALL emit `Dataset` JSON-LD under the CC0 license consistent with other aggregate stats pages.

#### Scenario: Page renders all three breakdowns
- **WHEN** a user visits `/stats/coordination`
- **THEN** the page shows support statistics for Binding, Groups, and Scenes with a breakdown by device category

#### Scenario: Structured data emitted
- **WHEN** `/stats/coordination` is rendered
- **THEN** it includes `Dataset` JSON-LD with a CC0 license

### Requirement: Legacy binding stats route redirects

The former `/stats/binding` route SHALL respond with an HTTP 301 redirect to `/stats/coordination` (anchored to the binding section), and site navigation and the sitemap SHALL reference the coordination page instead of the binding page.

#### Scenario: Old binding URL redirects
- **WHEN** a client requests `/stats/binding`
- **THEN** the server responds 301 with `Location` pointing to `/stats/coordination#binding`

#### Scenario: Sitemap and nav updated
- **WHEN** the sitemap is generated and the stats navigation is rendered
- **THEN** they reference `stats_coordination` and do not reference `stats_binding`
