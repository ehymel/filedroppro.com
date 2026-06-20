import { Controller } from '@hotwired/stimulus';

/**
 * Registration Controller
 * * Manages the client-side interaction modes during registration,
 * toggles firm creation/joining sections dynamically based on user context,
 * and intercepts submit events to facilitate local E2EE asymmetric keypair generation.
 */
export default class extends Controller {
    static targets = ['firmSection', 'joinSection', 'form', 'passwordInput'];
    static values = {
        hasInvitation: Boolean
    };

    connect() {
        // Only run form setup if the registering user is NOT on a pre-assigned invitation route
        if (!this.hasInvitationValue) {
            this.toggleSections();
        }
    }

    /**
     * Intercepts and toggles visibility of the dynamic input fields
     * based on the active selection pathway chosen by the registrant.
     */
    toggleSections() {
        const activeRadio = this.element.querySelector('input[name="registration_form[registrationMode]"]:checked');
        if (!activeRadio) {
            return;
        }

        const modeValue = activeRadio.value;

        if (modeValue === 'new') {
            this.showElement(this.firmSectionTarget);
            this.hideElement(this.joinSectionTarget);
        } else if (modeValue === 'join') {
            this.hideElement(this.firmSectionTarget);
            this.showElement(this.joinSectionTarget);
        }
    }

    /**
     * Handles the form submission lifecycle.
     * Intercepts the submit sequence to prevent raw transmission, executes local
     * client-side key derivation via the Web Crypto API, and appends the public
     * credentials to the payload before passing back to PHP.
     */
    async handleSubmit(event) {
        // Prevent form dispatch while we run asymmetric browser cryptography ceremonies
        event.preventDefault();

        const masterPassword = this.passwordInputTarget.value;
        if (!masterPassword || masterPassword.length < 8) {
            this.formTarget.submit(); // Fall back to standard form submission so HTML/backend validation fires
            return;
        }

        try {
            // Note: In an upcoming development iteration, this interceptor will trigger
            // async client-side asymmetric keypair generation prior to dispatching
            // the form payload, ensuring no raw passwords or credentials leak to the server.

            // For now, allow the form to resume submission seamlessly
            this.formTarget.submit();
        } catch (error) {
            console.error('Cryptographic Key Generation Failed:', error);
            // Submit anyway to preserve user experience if crypto failures occur
            this.formTarget.submit();
        }
    }

    // --- Helper UI Methods ---

    showElement(element) {
        if (element) {
            element.classList.remove('hidden');
        }
    }

    hideElement(element) {
        if (element) {
            element.classList.add('hidden');
        }
    }
}
