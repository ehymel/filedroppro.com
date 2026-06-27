import { Controller } from '@hotwired/stimulus';
import Swal from "sweetalert2";

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static values = {
        title: String,
        deleteUrl: String,
        listUrl: String,
        submitAsync: Boolean,
    };

    remove(event) {
        event.preventDefault();
        const ct = event.currentTarget;

        Swal.fire({
            title: this.titleValue || 'Are you sure?',
            text: 'This action is not reversible.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545', // Matches standard Bootstrap btn-danger red
            cancelButtonColor: '#6c757d', // Matches standard Bootstrap secondary gray
            confirmButtonText: 'Yes, delete it!',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                return this.delete(ct);
            },
            allowOutsideClick: () => !Swal.isLoading()
        });
    }

    async delete(ct) {
        // if clicked element provides a different delete URL, use it
        let url = this.deleteUrlValue;
        if (ct && ct.dataset.url) {
            url = ct.dataset.url;
        }

        if (!url) {
            console.error('No delete URL provided');
            return;
        }

        if (!this.submitAsyncValue) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = url;
            document.body.appendChild(form);
            form.submit();
            return;
        }

        // otherwise, submit async
        try {
            const response = await fetch(url, {
                method: 'DELETE',
            });

            this.dispatch('async:deleted', {
                detail: { response },
            });

            if (response.ok) {
                if (this.listUrlValue) {
                    window.location.replace(this.listUrlValue);
                } else {
                    window.location.reload();
                }
            } else {
                Swal.showValidationMessage(
                    `Request failed: ${response.statusText}`
                );
            }
        } catch (error) {
            Swal.showValidationMessage(
                `Request failed: ${error}`
            );
        }
    }

    cancel() {
        window.location.replace(this.listUrlValue);
    }
}
