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
        const params = new URLSearchParams({ ajax: 1});
        this.modal.show();

        let response = await fetch(`${this.formUrlValue}?${params.toString()}`);
        this.modalBodyTarget.innerHTML = await response.text();
        if (this.hideFullPageOpenButtonValue) {
            let fullPageBtn = document.querySelector('button#js-modal-fullpage-btn');
            fullPageBtn.classList.add('d-none');
        }
    }

    async submitForm(event) {
        event.preventDefault();
        const form = this.modalBodyTarget.getElementsByTagName('form')[0];
        let params = new FormData(form);
        params.append('ajax', 1);

        // for (let param in params.values()) {
        //     console.log(param);
        // }

        let response = await fetch(this.formUrlValue, {
            method: 'POST',
            body: params,
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
