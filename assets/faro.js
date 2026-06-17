// Grafana Faro frontend observability.
//
// Initialization is gated on a collector URL that the server renders into the
// page <head> via a <meta name="faro-collector-url"> tag. The tag is only
// emitted when the FARO_COLLECTOR_URL env var is set (prod), so this module is
// inert in dev/test and never phones home from a developer's machine.
//
// When active it captures errors, Core Web Vitals, sessions, and user actions
// (getWebInstrumentations defaults), plus distributed tracing: TracingInstrumentation
// injects W3C `traceparent` on same-origin fetch/XHR — including Turbo's
// fetch-based navigations — which the backend continues. The initial document
// load (a true browser navigation, no outgoing traceparent) is correlated
// separately via the backend's Server-Timing header.
import { initializeFaro, getWebInstrumentations } from '@grafana/faro-web-sdk';
import { TracingInstrumentation } from '@grafana/faro-web-tracing';

const meta = (name) => document.querySelector(`meta[name="${name}"]`)?.content;

const url = meta('faro-collector-url');

if (url) {
    const faro = initializeFaro({
        url,
        app: {
            name: meta('faro-app-name') || 'matter-survey.org',
            version: meta('faro-app-version') || undefined,
            environment: meta('faro-environment') || 'production',
        },
        instrumentations: [
            ...getWebInstrumentations(),
            // Same-origin traceparent injection on fetch/XHR; no
            // propagateTraceHeaderCorsUrls needed (all traced calls are same-origin).
            new TracingInstrumentation(),
        ],
        ignoreErrors: [
            // Layout quirks — harmless, not real errors
            /^ResizeObserver loop limit exceeded$/,
            /^ResizeObserver loop completed with undelivered notifications$/,
            // Cross-origin scripts with no useful stack
            /^Script error\.$/,
            // Browser extension interference
            /chrome-extension:\/\//,
            /moz-extension:\/\//,
        ],
    });

    // Turbo swaps the DOM without a full document reload, so Faro would otherwise
    // attribute every later signal to the first-loaded URL. Update the view once
    // per visit. turbo:load fires on the initial load and on each Turbo visit.
    document.addEventListener('turbo:load', () => {
        faro.api?.setView({ name: window.location.pathname });
    });
}
