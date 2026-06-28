import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ["modal", "modalBody"];
    static values = {
        formUrl: String,
        hideFullPageOpenButton: { type: Boolean, default: false },
    }
    modal = null;

    async open(event) {
        event.preventDefault();
        this.modalBodyTarget.innerHTML = 'Loading...';
        this.modal = new Modal(this.modalTarget);
        this.modal.show();

        const url = new URL(this.formUrlValue, window.location.origin);
        url.searchParams.set('ajax', '1');

        let response = await fetch(url.toString());
        this.modalBodyTarget.innerHTML = await response.text();
        if (this.hideFullPageOpenButtonValue) {
            let fullPageBtn = document.querySelector('button#js-modal-fullpage-btn');
            fullPageBtn.classList.add('d-none');
        }
    }

    async submitForm(event) {
        event.preventDefault();
        const form = event.target.closest('form') || this.modalBodyTarget.getElementsByTagName('form')[0];
        let formData = new FormData(form);
        formData.append('ajax', 1);

        const url = new URL(this.formUrlValue, window.location.origin);
        // url.searchParams.set('ajax', '1');

        let response = await fetch(url.toString(), {
            method: 'POST',
            body: formData,
        });

        if (response.status !== 422) {
            const body = await response.text();
            if (body.length > 0) {
                this.dispatch('success', { detail: { content: body } });
            } else {
                this.dispatch('success');
            }
            this.modal.hide();
        } else {
            this.modalBodyTarget.innerHTML = await response.text();
        }
    }

    fullPage(event) {
        event.preventDefault();
        this.modal.hide();
        document.location.href = this.formUrlValue;
    }
}
