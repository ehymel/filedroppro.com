import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    connect() {
        let forms = document.querySelectorAll("form");

        forms[0].addEventListener('keypress', (event) => {
            if (event.code === 'Enter') {
                event.preventDefault();
                return false;
            }
        });
    }
}
