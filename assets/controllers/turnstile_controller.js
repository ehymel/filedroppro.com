import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['widget', 'submit']
    static values = {
        sitekey: String,
        action: { type: String, default: 'turnstile-spin-v2' },
        // Opt-in for pages where a broken/blocked widget must not cost the user
        // the whole form. The submit button is still gated normally; it just
        // can't stay disabled forever. Verification is enforced server-side
        // regardless, so re-enabling early is a UX call, not a security one.
        failOpen: { type: Boolean, default: false },
        failOpenDelay: { type: Number, default: 8000 }
    }

    connect() {
        this.disableSubmit();
        this.render();
    }

    disconnect() {
        this.clearFailOpen();
        if (this.checkInterval) {
            clearInterval(this.checkInterval);
        }
    }

    render() {
        const widgetElement = this.hasWidgetTarget ? this.widgetTarget : this.element;

        if (typeof window.turnstile !== 'undefined') {
            if (!this.widgetId) {
                this.widgetId = window.turnstile.render(widgetElement, {
                    sitekey: this.sitekeyValue,
                    action: this.actionValue,
                    callback: () => this.enableSubmit(),
                    'expired-callback': () => this.disableSubmit(),
                    'error-callback': () => this.disableSubmit(),
                });
            }
        } else {
            // Load the script if not already present
            if (!document.querySelector('script[src*="challenges.cloudflare.com"]')) {
                const script = document.createElement('script');
                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                script.async = true;
                script.defer = true;
                script.onload = () => this.render();
                document.head.appendChild(script);
            } else {
                // Script is present but window.turnstile is not yet available
                this.checkInterval = setInterval(() => {
                    if (typeof window.turnstile !== 'undefined') {
                        clearInterval(this.checkInterval);
                        this.render();
                    }
                }, 100);

                // Safety timeout
                setTimeout(() => clearInterval(this.checkInterval), 5000);
            }
        }
    }

    enableSubmit() {
        this.clearFailOpen();
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = false;
        }
    }

    disableSubmit() {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = true;
        }
        // Every path that disables the button re-arms the watchdog, so a widget
        // that never loads, errors out, or expires without refreshing can't
        // leave the form permanently unsubmittable.
        this.armFailOpen();
    }

    armFailOpen() {
        if (!this.failOpenValue || !this.hasSubmitTarget) {
            return;
        }

        this.clearFailOpen();
        this.failOpenTimer = setTimeout(() => {
            this.failOpenTimer = null;
            this.submitTarget.disabled = false;
        }, this.failOpenDelayValue);
    }

    clearFailOpen() {
        if (this.failOpenTimer) {
            clearTimeout(this.failOpenTimer);
            this.failOpenTimer = null;
        }
    }
}
