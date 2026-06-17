// Grafana Faro frontend observability.
//
// Initialization is gated on a collector URL that the server renders into the
// page <head> via a <meta name="faro-collector-url"> tag. The tag is only
// emitted when the FARO_COLLECTOR_URL env var is set (prod), so this module is
// inert in dev/test and never phones home from a developer's machine.
import { initializeFaro, getWebInstrumentations } from '@grafana/faro-web-sdk';

const meta = document.querySelector('meta[name="faro-collector-url"]');
const url = meta?.content;

if (url) {
    initializeFaro({
        url,
        app: {
            name: document.querySelector('meta[name="faro-app-name"]')?.content || 'matter-survey.org',
            environment: document.querySelector('meta[name="faro-environment"]')?.content || 'production',
        },
        instrumentations: [...getWebInstrumentations()],
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
}
