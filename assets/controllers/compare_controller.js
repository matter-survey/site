import { Controller } from '@hotwired/stimulus';

/**
 * Compare controller for the device comparison page.
 * Handles:
 * - Device search and add
 * - Device removal
 * - Row expansion for details
 * - URL state management
 * - Share/copy functionality
 *
 * Usage:
 *   <div data-controller="compare"
 *        data-compare-slugs-value="device-a,device-b"
 *        data-compare-search-url-value="/api/compare/search"
 *        data-compare-base-url-value="/compare">
 *     <input data-compare-target="searchInput" data-action="input->compare#search">
 *     <div data-compare-target="results"></div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['searchInput', 'results', 'addColumn'];
    static values = {
        slugs: String,
        searchUrl: String,
        baseUrl: String
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

    // Search for devices to add
    search() {
        clearTimeout(this.timeout);

        if (!this.hasSearchInputTarget) return;

        const query = this.searchInputTarget.value.trim();

        if (query.length < 2) {
            this.hideResults();
            return;
        }

        this.timeout = setTimeout(async () => {
            try {
                const exclude = this.slugsValue;
                const response = await fetch(
                    `${this.searchUrlValue}?q=${encodeURIComponent(query)}&exclude=${encodeURIComponent(exclude)}`
                );
                const data = await response.json();
                this.renderResults(data.results);
            } catch (e) {
                this.hideResults();
            }
        }, 200);
    }

    renderResults(results) {
        if (!this.hasResultsTarget) return;

        this.selectedIndex = -1;

        if (results.length === 0) {
            this.resultsTarget.innerHTML = '<div class="compare-search-empty">No devices found</div>';
        } else {
            this.resultsTarget.innerHTML = results.map((r, index) => `
                <div class="compare-search-item"
                     data-index="${index}"
                     data-slug="${this.escapeHtml(r.slug)}"
                     data-action="click->compare#selectDevice">
                    <span class="compare-search-item-name">${this.escapeHtml(r.name)}</span>
                    <span class="compare-search-item-vendor">${this.escapeHtml(r.vendor)}</span>
                </div>
            `).join('');
        }
        this.showResults();
    }

    selectDevice(event) {
        const slug = event.currentTarget.dataset.slug;
        this.addDevice(slug);
    }

    addDevice(slug) {
        const currentSlugs = this.slugsValue ? this.slugsValue.split(',').filter(s => s) : [];
        if (currentSlugs.length >= 5 || currentSlugs.includes(slug)) {
            return;
        }

        currentSlugs.push(slug);
        this.navigateToComparison(currentSlugs);
    }

    removeDevice(event) {
        event.stopPropagation(); // Prevent row expansion toggle
        const slug = event.currentTarget.dataset.compareSlugParam || event.params.slug;
        const currentSlugs = this.slugsValue.split(',').filter(s => s && s !== slug);

        if (currentSlugs.length === 0) {
            window.location.href = this.baseUrlValue;
        } else {
            this.navigateToComparison(currentSlugs);
        }
    }

    navigateToComparison(slugs) {
        window.location.href = `${this.baseUrlValue}/${slugs.join(',')}`;
    }

    toggleRow(event) {
        // Don't toggle if clicking on a link or button
        if (event.target.closest('a') || event.target.closest('button')) {
            return;
        }

        const row = event.currentTarget;
        const detailsRow = row.nextElementSibling;
        if (!detailsRow || !detailsRow.classList.contains('capability-details-row')) return;

        const icon = row.querySelector('.expand-icon');
        const isExpanded = detailsRow.style.display !== 'none';

        detailsRow.style.display = isExpanded ? 'none' : 'table-row';
        row.classList.toggle('expanded', !isExpanded);
        if (icon) {
            icon.innerHTML = isExpanded ? '&#9654;' : '&#9660;';
        }
    }

    copyUrl() {
        navigator.clipboard.writeText(window.location.href).then(() => {
            // Simple feedback - could be enhanced with a toast notification
            const button = event.currentTarget;
            const originalText = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => {
                button.textContent = originalText;
            }, 2000);
        }).catch(() => {
            // Fallback for older browsers
            const input = document.createElement('input');
            input.value = window.location.href;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
        });
    }

    onKeydown(event) {
        if (!this.hasResultsTarget) return;

        const items = this.resultsTarget.querySelectorAll('.compare-search-item');

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
            item.classList.toggle('selected', index === this.selectedIndex);
        });
    }

    showResults() {
        if (this.hasResultsTarget) {
            this.resultsTarget.style.display = 'block';
        }
    }

    hideResults() {
        if (this.hasResultsTarget) {
            this.resultsTarget.style.display = 'none';
        }
        this.selectedIndex = -1;
    }

    handleClickOutside(event) {
        // Close results if clicking outside search area
        if (this.hasAddColumnTarget && !this.addColumnTarget.contains(event.target)) {
            this.hideResults();
        }
        // Also check for the large search in empty state
        const searchLarge = this.element.querySelector('.compare-search-large');
        if (searchLarge && !searchLarge.contains(event.target)) {
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
