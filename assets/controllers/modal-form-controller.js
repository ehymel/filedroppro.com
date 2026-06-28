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

    async open() {
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
        const form = this.modalBodyTarget.getElementsByTagName('form')[0];
        let formData = new FormData(form);

        const url = new URL(this.formUrlValue, window.location.origin);
        url.searchParams.set('ajax', '1');

        let response = await fetch(url.toString(), {
            method: 'POST',
            body: formData,
        });

        if (response.status !== 422) {
            this.modal.hide();
            this.dispatch('success');
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
