import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['dropdown', 'button'];

    connect() {
        this.close();
        this.boundHandleClickOutside = this.handleClickOutside.bind(this);
        document.addEventListener('click', this.boundHandleClickOutside);
    }

    disconnect() {
        document.removeEventListener('click', this.boundHandleClickOutside);
    }

    toggle(event) {
        event.stopPropagation();
        this.dropdownTarget.classList.toggle('hidden');
        const isExpanded = !this.dropdownTarget.classList.contains('hidden');
        this.buttonTarget.setAttribute('aria-expanded', isExpanded);
    }

    close() {
        this.dropdownTarget.classList.add('hidden');
        if (this.hasButtonTarget) {
            this.buttonTarget.setAttribute('aria-expanded', 'false');
        }
    }

    handleClickOutside(event) {
        if (!this.element.contains(event.target)) {
            this.close();
        }
    }

    handleKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
            this.buttonTarget.focus();
        }
    }
}
