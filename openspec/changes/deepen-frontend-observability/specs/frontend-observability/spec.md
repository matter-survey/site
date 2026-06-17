## ADDED Requirements

### Requirement: Faro is initialized once and gated on configuration

The application SHALL initialize Grafana Faro exactly once per page context, and ONLY when a collector URL is configured. When no collector URL is present the Faro module SHALL be inert and make no network calls. Initialization SHALL occur as the first import of the JavaScript entry point so errors are captured from load.

#### Scenario: Configured production page
- **WHEN** a page renders with a `faro-collector-url` meta tag (collector URL configured)
- **THEN** Faro initializes with the configured collector URL, app name, and environment
- **AND** JavaScript errors, unhandled rejections, and Core Web Vitals are captured automatically

#### Scenario: Unconfigured development/test page
- **WHEN** a page renders without a collector URL (empty `FARO_COLLECTOR_URL`)
- **THEN** Faro does not initialize
- **AND** no requests are made to any collector

#### Scenario: Browser noise is filtered
- **WHEN** a known-harmless error occurs (e.g. `ResizeObserver loop limit exceeded`, `Script error.`, or a browser-extension error)
- **THEN** it is suppressed via `ignoreErrors` and not reported

### Requirement: Frontend telemetry is tagged with the release version

The application SHALL tag all Faro telemetry with a release version (`app.version`) sourced from a single origin shared with the backend `service.version`, so frontend signals are attributable to a specific deployment.

#### Scenario: Version present on signals
- **WHEN** Faro initializes on a deployed page
- **THEN** the configured release version is set as `app.version`
- **AND** the value equals the backend `service.version` for the same release

### Requirement: Browser-to-backend trace propagation

The application SHALL inject W3C `traceparent` headers on same-origin `fetch`/XHR requests (including Turbo navigation and frame requests, which are fetch-based), producing frontend spans that the backend continues via its existing trace-context extraction.

#### Scenario: AJAX request is traced end-to-end
- **WHEN** the browser issues a same-origin request (e.g. autocomplete `/api/search`)
- **THEN** the request carries a `traceparent` header
- **AND** the backend root server span uses the same trace id as the frontend span

#### Scenario: Turbo navigation is traced
- **WHEN** a Turbo visit or frame swap fetches a same-origin URL
- **THEN** the fetch carries a `traceparent` header continued by the backend

#### Scenario: Cross-origin requests are not propagated by default
- **WHEN** the browser issues a cross-origin request not on the trace-propagation allowlist
- **THEN** no `traceparent` header is injected

### Requirement: Document-load trace correlation

The application SHALL correlate the initial document load (a true browser navigation that carries no outgoing `traceparent`) with its backend trace by consuming the backend-provided `Server-Timing` trace context from the navigation performance entry.

#### Scenario: Cold load links to backend trace
- **WHEN** the initial HTML response includes `Server-Timing: traceparent;desc="00-<traceId>-<spanId>-<flags>"`
- **THEN** the frontend page-load is associated with that backend trace id

#### Scenario: No server timing present
- **WHEN** the document response carries no `Server-Timing` trace context
- **THEN** the page-load telemetry is still recorded, without a backend link

### Requirement: View context tracks Turbo navigations

Because the document persists across Turbo navigations, the application SHALL update Faro's view/page context on each Turbo visit so telemetry is attributed to the current URL rather than the first-loaded URL, without double-counting a single visit.

#### Scenario: Subsequent Turbo navigation updates the view
- **WHEN** the user navigates via Turbo to a new URL after the initial load
- **THEN** Faro's view context reflects the new URL
- **AND** errors/events emitted afterward are attributed to that URL

### Requirement: User actions and product events are captured

The application SHALL enable Faro's native user-actions instrumentation AND emit manual domain events for key flows: search submission, comparison start, and each wizard step transition.

#### Scenario: Native user action grouping
- **WHEN** a user click triggers subsequent network activity or an error
- **THEN** that activity is grouped under a named user action

#### Scenario: Search event
- **WHEN** a user submits a search (autocomplete or list filter)
- **THEN** a `search_submitted` event is emitted with the query text, result count, and originating surface

#### Scenario: Comparison event
- **WHEN** a user starts a device comparison
- **THEN** a `comparison_started` event is emitted with the number of selected devices

#### Scenario: Wizard funnel event
- **WHEN** a user completes a wizard step
- **THEN** a `wizard_step_completed` event is emitted identifying the step, enabling a drop-off funnel

### Requirement: Telemetry excludes user identity and sensitive data

The application SHALL NOT set a user identity on Faro and SHALL restrict event payloads to an allowlist of non-sensitive fields, carrying no authentication tokens, session secrets, or personal data.

#### Scenario: No identity is attached
- **WHEN** any user (including an authenticated admin) generates telemetry
- **THEN** no personal user identifier is set on the Faro session

#### Scenario: Event payloads are bounded
- **WHEN** a domain event is emitted
- **THEN** its payload contains only the documented allowlisted fields for that event and no auth/PII data
