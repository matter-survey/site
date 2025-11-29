import { Controller } from '@hotwired/stimulus';

/**
 * Autocomplete controller for search inputs with API-powered suggestions.
 * Usage:
 *   <div data-controller="autocomplete" data-autocomplete-url-value="/api/search">
 *     <input data-autocomplete-target="input" data-action="input->autocomplete#search focus->autocomplete#search">
 *     <div data-autocomplete-target="results" class="autocomplete-results"></div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['input', 'results'];
    static values = {
        url: String,
        minLength: { type: Number, default: 2 }
    };

    connect() {
        this.timeout = null;
        this.selectedIndex = -1;
        this.inputTarget.addEventListener('keydown', this.handleKeydown.bind(this));
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
            this.hide();
            return;
        }

        this.timeout = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?q=${encodeURIComponent(query)}`);
                const data = await response.json();
                this.render(data.results);
            } catch (e) {
                this.hide();
            }
        }, 200);
    }

    render(results) {
        this.selectedIndex = -1;

        if (results.length === 0) {
            this.resultsTarget.innerHTML = '<div class="autocomplete-empty">No results found</div>';
        } else {
            this.resultsTarget.innerHTML = results.map((r, index) => `
                <a href="${r.url}" class="autocomplete-item" data-index="${index}">
                    <span class="autocomplete-name">${this.escapeHtml(r.name)}</span>
                    <span class="autocomplete-vendor">${this.escapeHtml(r.vendor)}</span>
                </a>
            `).join('');
        }
        this.show();
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    show() {
        this.resultsTarget.style.display = 'block';
    }

    hide() {
        this.resultsTarget.style.display = 'none';
        this.selectedIndex = -1;
    }

    handleKeydown(event) {
        const items = this.resultsTarget.querySelectorAll('.autocomplete-item');

        switch (event.key) {
            case 'Escape':
                this.hide();
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
                if (this.selectedIndex >= 0 && items[this.selectedIndex]) {
                    event.preventDefault();
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

    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.hide();
        }
    }
}
