import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content'];
    static values = {
        url: String,
    }

    async refreshContent(event) {
        if (event && event.detail && event.detail.content) {
            this.contentTarget.innerHTML = event.detail.content;
            return;
        }

        const url = new URL(this.urlValue, window.location.origin);
        url.searchParams.set('ajax', '1');
        const response = await fetch(url.toString());
        this.contentTarget.innerHTML = await response.text();
    }
}
