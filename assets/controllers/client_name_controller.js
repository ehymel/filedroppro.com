import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['display', 'form', 'input', 'name'];
    static values = { url: String };

    toggle(event) {
        event?.preventDefault();
        this.displayTarget.classList.toggle('d-none');
        this.formTarget.classList.toggle('d-none');
        if (!this.formTarget.classList.contains('d-none')) {
            this.inputTarget.focus();
        }
    }

    async save() {
        const clientName = this.inputTarget.value;
        const response = await fetch(this.urlValue, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ clientName: clientName })
        });

        if (response.ok) {
            const data = await response.json();
            this.nameTarget.textContent = data.clientName;
            this.toggle();
        } else {
            alert('Failed to save new client name.');
        }
    }
}
