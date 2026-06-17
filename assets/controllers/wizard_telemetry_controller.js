import { Controller } from '@hotwired/stimulus';
import { trackEvent } from '../observability.js';

/**
 * Emits a wizard funnel event on each step view. The wizard is server-rendered
 * (/wizard?step=N), so each step is a fresh render and `connect()` fires once
 * per step entered — giving a drop-off funnel across the flow.
 *
 * Usage:
 *   <div data-controller="wizard-telemetry"
 *        data-wizard-telemetry-step-value="{{ step }}"
 *        data-wizard-telemetry-name-value="category">
 */
export default class extends Controller {
    static values = {
        step: Number,
        name: String,
    };

    connect() {
        trackEvent('wizard_step_completed', {
            step: String(this.stepValue),
            name: this.nameValue,
        });
    }
}
