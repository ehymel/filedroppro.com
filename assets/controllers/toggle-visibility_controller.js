import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['element'];

    toggle(event) {
        this.elementTarget.classList.toggle('d-none', !event.target.checked);
    }
}
