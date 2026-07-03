import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['display', 'form', 'input', 'output'];
    static values = { url: String, field: String, empty: String };

    toggle(event) {
        event?.preventDefault();
        this.displayTarget.classList.toggle('d-none');
        this.formTarget.classList.toggle('d-none');
        if (!this.formTarget.classList.contains('d-none')) {
            this.inputTarget.focus();
        }
    }

    async save() {
        const response = await fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ [this.fieldValue]: this.inputTarget.value })
        });

        if (response.ok) {
            const data = await response.json();
            this.outputTarget.textContent = data[this.fieldValue] || this.emptyValue;
            this.toggle();
        } else {
            alert('Failed to save changes.');
        }
    }
}
