import { Controller } from '@hotwired/stimulus';

/**
 * Compare selection controller for the device listing page.
 * Handles checkbox selection and floating compare button.
 *
 * Usage:
 *   <div data-controller="compare-select"
 *        data-compare-select-compare-url-value="/compare">
 *     <div class="compare-floating" data-compare-select-target="button" style="display: none;">
 *       <span>Compare (<span data-compare-select-target="count">0</span>)</span>
 *       <button data-action="compare-select#compare">Compare</button>
 *       <button data-action="compare-select#clear">Clear</button>
 *     </div>
 *     <input type="checkbox"
 *            data-compare-select-target="checkbox"
 *            data-action="change->compare-select#toggle"
 *            data-slug="device-slug">
 *   </div>
 */
export default class extends Controller {
    static targets = ['checkbox', 'button', 'count'];
    static values = {
        compareUrl: String,
        maxDevices: { type: Number, default: 5 }
    };

    connect() {
        this.selectedSlugs = this.loadFromStorage();
        this.syncCheckboxes();
        this.updateButton();
    }

    toggle(event) {
        const checkbox = event.currentTarget;
        const slug = checkbox.dataset.slug;

        if (checkbox.checked) {
            if (this.selectedSlugs.length >= this.maxDevicesValue) {
                checkbox.checked = false;
                this.showMaxWarning();
                return;
            }
            if (!this.selectedSlugs.includes(slug)) {
                this.selectedSlugs.push(slug);
            }
        } else {
            this.selectedSlugs = this.selectedSlugs.filter(s => s !== slug);
        }

        this.saveToStorage();
        this.updateButton();
    }

    updateButton() {
        const count = this.selectedSlugs.length;

        if (this.hasButtonTarget) {
            this.buttonTarget.style.display = count > 0 ? 'flex' : 'none';
        }

        if (this.hasCountTarget) {
            this.countTarget.textContent = count;
        }

        // Update compare button state
        const compareBtn = this.element.querySelector('[data-action="compare-select#compare"]');
        if (compareBtn) {
            compareBtn.disabled = count < 2;
            compareBtn.title = count < 2 ? 'Select at least 2 devices to compare' : '';
        }
    }

    compare() {
        if (this.selectedSlugs.length >= 2) {
            window.location.href = `${this.compareUrlValue}/${this.selectedSlugs.join(',')}`;
        }
    }

    clear() {
        this.selectedSlugs = [];
        this.checkboxTargets.forEach(cb => cb.checked = false);
        this.saveToStorage();
        this.updateButton();
    }

    // Persist selection in localStorage
    saveToStorage() {
        try {
            localStorage.setItem('compare_selected', JSON.stringify(this.selectedSlugs));
        } catch (e) {
            // localStorage not available
        }
    }

    loadFromStorage() {
        try {
            const stored = localStorage.getItem('compare_selected');
            if (stored) {
                const parsed = JSON.parse(stored);
                if (Array.isArray(parsed)) {
                    return parsed.slice(0, this.maxDevicesValue);
                }
            }
        } catch (e) {
            // localStorage not available or invalid data
        }
        return [];
    }

    // Sync checkboxes with stored selection on page load
    syncCheckboxes() {
        this.checkboxTargets.forEach(cb => {
            cb.checked = this.selectedSlugs.includes(cb.dataset.slug);
        });
    }

    showMaxWarning() {
        // Could be enhanced with a toast notification
        alert(`You can compare up to ${this.maxDevicesValue} devices at a time.`);
    }
}
