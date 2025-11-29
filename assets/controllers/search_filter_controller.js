import { Controller } from '@hotwired/stimulus';

/**
 * Search filter controller for client-side filtering of lists.
 * Usage:
 *   <div data-controller="search-filter">
 *     <input data-search-filter-target="input" data-action="input->search-filter#filter">
 *     <span data-search-filter-target="count">10</span>
 *     <div data-search-filter-target="item" data-name="item name">...</div>
 *     <div data-search-filter-target="empty" style="display: none;">No results</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'item', 'count', 'empty'];
    static values = {
        attribute: { type: String, default: 'name' }
    };

    filter() {
        const query = this.inputTarget.value.toLowerCase().trim();
        let visible = 0;

        this.itemTargets.forEach(item => {
            const value = item.dataset[this.attributeValue] || '';
            const matches = query === '' || value.includes(query);
            item.classList.toggle('hidden', !matches);
            if (matches) visible++;
        });

        if (this.hasCountTarget) {
            this.countTarget.textContent = visible;
        }

        if (this.hasEmptyTarget) {
            this.emptyTarget.style.display = visible === 0 && query !== '' ? 'block' : 'none';
        }
    }
}
