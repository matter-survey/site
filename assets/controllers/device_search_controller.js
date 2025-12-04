import { Controller } from '@hotwired/stimulus';

/**
 * Device search controller for the wizard compatibility step.
 * Combines autocomplete search with device tag management.
 * Usage:
 *   <div data-controller="device-search" data-device-search-url-value="/wizard/device-search">
 *     <input data-device-search-target="input" data-action="input->device-search#search">
 *     <div data-device-search-target="results" class="device-search-results"></div>
 *     <div data-device-search-target="selectedList" class="owned-devices"></div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'results', 'selectedList'];
    static values = {
        url: String,
        minLength: { type: Number, default: 2 }
    };

    connect() {
        this.timeout = null;
        this.selectedIndex = -1;
        document.addEventListener('click', this.handleClickOutside.bind(this));
    }

    disconnect() {
        document.removeEventListener('click', this.handleClickOutside.bind(this));
        if (this.timeout) clearTimeout(this.timeout);
    }

    search() {
        clearTimeout(this.timeout);
        const query = this.inputTarget.value.trim();

        if (query.length < this.minLengthValue) {
            this.hideResults();
            return;
        }

        this.timeout = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                this.renderResults(data.results);
            } catch (e) {
                this.hideResults();
            }
        }, 200);
    }

    renderResults(results) {
        this.selectedIndex = -1;

        if (results.length === 0) {
            this.resultsTarget.innerHTML = '<div class="device-search-empty" style="padding: 0.75rem; color: var(--gray-500);">No devices found</div>';
        } else {
            this.resultsTarget.innerHTML = results.map((r, index) => `
                <div class="device-search-item"
                     data-index="${index}"
                     data-device-id="${r.id}"
                     data-device-name="${this.escapeHtml(r.name)}"
                     data-action="click->device-search#selectDevice">
                    <div class="device-search-item-name">${this.escapeHtml(r.name)}</div>
                    <div class="device-search-item-vendor">${this.escapeHtml(r.vendor)}</div>
                </div>
            `).join('');
        }
        this.showResults();
    }

    selectDevice(event) {
        const item = event.currentTarget;
        const deviceId = item.dataset.deviceId;
        const deviceName = item.dataset.deviceName;

        // Check if already selected
        if (this.selectedListTarget.querySelector(`input[value="${deviceId}"]`)) {
            this.hideResults();
            this.inputTarget.value = '';
            return;
        }

        // Add device tag
        const tag = document.createElement('span');
        tag.className = 'owned-device-tag';
        tag.dataset.deviceId = deviceId;
        tag.innerHTML = `
            ${this.escapeHtml(deviceName)}
            <input type="hidden" name="owned[]" value="${deviceId}">
            <span class="owned-device-remove" data-action="click->device-search#removeDevice">&times;</span>
        `;
        this.selectedListTarget.appendChild(tag);

        // Clear and hide
        this.hideResults();
        this.inputTarget.value = '';
    }

    removeDevice(event) {
        event.currentTarget.closest('.owned-device-tag').remove();
    }

    onKeydown(event) {
        const items = this.resultsTarget.querySelectorAll('.device-search-item');

        switch (event.key) {
            case 'Escape':
                this.hideResults();
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.selectedIndex = Math.min(this.selectedIndex + 1, items.length - 1);
                this.updateSelection(items);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.selectedIndex = Math.max(this.selectedIndex - 1, -1);
                this.updateSelection(items);
                break;
            case 'Enter':
                event.preventDefault();
                if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                    items[this.selectedIndex].click();
                }
                break;
        }
    }

    updateSelection(items) {
        items.forEach((item, index) => {
            if (index === this.selectedIndex) {
                item.style.background = 'var(--gray-100)';
            } else {
                item.style.background = '';
            }
        });
    }

    showResults() {
        this.resultsTarget.classList.add('show');
    }

    hideResults() {
        this.resultsTarget.classList.remove('show');
        this.selectedIndex = -1;
    }

    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.hideResults();
        }
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
