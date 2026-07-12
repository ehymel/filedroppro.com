import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['link'];
    static values = {
        duration: { type: Number, default: 60 },
        label: { type: String, default: 'Resend' },
        storageKey: String,
        autoStart: { type: Boolean, default: false }
    };

    connect() {
        if (!this.hasStorageKeyValue) {
            this.storageKeyValue = `2fa_resend_countdown_${window.location.pathname}`;
        }

        if (this.autoStartValue && !sessionStorage.getItem(this.storageKeyValue)) {
            this.doStartCountdown();
        }

        this.updateCountdown();
    }

    startCountdown(event) {
        if (this.linkTarget.classList.contains('disabled')) {
            event.preventDefault();
            return;
        }

        this.doStartCountdown();
        // The link will navigate to the resend URL, and on reload, connect() will start the countdown.
    }

    doStartCountdown() {
        const expiry = Math.floor(Date.now() / 1000) + this.durationValue;
        sessionStorage.setItem(this.storageKeyValue, expiry.toString());
    }

    updateCountdown() {
        const now = Math.floor(Date.now() / 1000);
        const expiry = parseInt(sessionStorage.getItem(this.storageKeyValue));

        if (expiry && expiry > now) {
            const remaining = expiry - now;
            this.linkTarget.innerText = `${this.labelValue} (${remaining}s)`;
            this.linkTarget.classList.add('disabled');
            this.linkTarget.style.pointerEvents = 'none';

            setTimeout(() => this.updateCountdown(), 1000);
        } else {
            this.linkTarget.innerText = this.labelValue;
            this.linkTarget.classList.remove('disabled');
            this.linkTarget.style.pointerEvents = 'auto';
            sessionStorage.removeItem(this.storageKeyValue);
        }
    }
}
