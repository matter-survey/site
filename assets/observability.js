import { faro } from '@grafana/faro-web-sdk';

/**
 * Thin wrapper around Faro custom events. No-op until Faro is initialized
 * (dev/test, and prod before the gated init runs), because `faro.api` is
 * undefined there — so callers can fire events unconditionally.
 *
 * PRIVACY: only pass non-sensitive, allowlisted attributes. Never user
 * identity, auth tokens, or PII (see the frontend-observability privacy
 * posture). Search query text is intentionally allowed; it is device-search
 * input, not personal data.
 *
 * @param {string} name
 * @param {Record<string, string>} attributes
 */
export function trackEvent(name, attributes = {}) {
    faro.api?.pushEvent(name, attributes);
}
