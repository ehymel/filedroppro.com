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
            title: this.titleValue || null,
            text: 'This action is not reversible.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545', // Matches standard Bootstrap btn-danger red
            cancelButtonColor: '#6c757d', // Matches standard Bootstrap secondary gray
            confirmButtonText: 'Yes, delete it!',
            showLoaderOnConfirm: true,
            preConfirm: () => {
                this.delete(ct);
            }
        });
    }

    async delete(ct) {
        // if clicked element provides a different delete URL, use it
        if (ct && ct.dataset.url) {
            this.deleteUrlValue = ct.dataset.url;
        }

        if (!this.submitAsyncValue) {
            window.location.replace(this.deleteUrlValue);
            return;
        }

        // otherwise, submit async
        const response = await fetch(this.deleteUrlValue, {
            method: 'DELETE',
        });

        this.dispatch('async:deleted', {
            detail: { response },   // this option not needed here, but could be useful to have in different scenario
        });

        if (response.status === 200 || response.status === 204) {
            window.location.replace(this.listUrlValue)
        }
    }

    cancel() {
        window.location.replace(this.listUrlValue);
    }
}
