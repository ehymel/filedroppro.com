import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['display', 'form', 'input'];
    static values = { url: String };

    toggle() {
        this.displayTarget.classList.toggle('d-none');
        this.formTarget.classList.toggle('d-none');
        if (!this.formTarget.classList.contains('d-none')) {
            this.inputTarget.focus();
        }
    }

    async save() {
        const note = this.inputTarget.value;
        const response = await fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ note: note })
        });

        if (response.ok) {
            const data = await response.json();
            this.displayTarget.textContent = data.note || 'No note added.';
            this.toggle();
        } else {
            alert('Failed to save note.');
        }
    }
}
