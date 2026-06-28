import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['formContainer'];

    open(event) {
        event.preventDefault();

        const form = this.formContainerTarget.querySelector('form');
        if (!form) return;

        Swal.fire({
            title: 'Invite External Client',
            html: '<div id="modal-form-placeholder"></div>',
            showConfirmButton: false,
            width: '600px',
            didOpen: () => {
                const placeholder = document.getElementById('modal-form-placeholder');
                placeholder.appendChild(form);

                const firstInput = form.querySelector('input');
                if (firstInput) firstInput.focus();

                form.addEventListener('submit', this.showLoading);

                const cancelBtn = form.querySelector('button[data-action="click->invite-form#close"]');
                if (cancelBtn) {
                    cancelBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.close();
                    });
                }
            },
            willClose: () => {
                form.removeEventListener('submit', this.showLoading);
                this.formContainerTarget.appendChild(form);
            }
        });
    }

    showLoading() {
        Swal.showLoading();
    }

    close() {
        Swal.close();
    }
}
