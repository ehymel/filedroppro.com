import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['content'];
    static values = {
        url: String,
    }

    async refreshContent() {
        this.contentTarget.style.opacity = .5;
        const response = await fetch(this.urlValue);

        this.contentTarget.innerHTML = await response.text();
        this.contentTarget.style.opacity = 1;
    }
}
