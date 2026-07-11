import { Controller } from '@hotwired/stimulus';

/**
 * Password Reset Controller
 * * Intercepts unauthenticated forgotten password forms.
 * * Generates a brand-new asymmetric RSA identity key pair locally in-browser.
 * * Encrypts the private key with the new password, then injects elements into submission metadata.
 */
export default class extends Controller {
    static targets = [
        'form',
        'newPasswordInput',
        'confirmPasswordInput',
        'submitButton',
        'statusIndicator'
    ];

    /**
     * Executes the Web Crypto identity regeneration ceremony on form submission.
     */
    async executeCeremony(event) {
        event.preventDefault();

        const newPassword = this.newPasswordInputTarget.value;
        const confirmPassword = this.confirmPasswordInputTarget.value;

        if (newPassword.length < 8) {
            this.updateStatus('Your new password must be at least 8 characters long.', 'danger');
            return;
        }

        if (newPassword !== confirmPassword) {
            this.updateStatus('New password and password confirmation fields do not match.', 'danger');
            return;
        }

        this.lockUI('Generating new cryptographic identity keys...');

        try {
            // 1. Generate fresh asymmetric identity key pair (RSA-OAEP)
            this.updateStatus('Generating asymmetric RSA key pair...', 'info');
            const keyPair = await window.crypto.subtle.generateKey(
                {
                    name: 'RSA-OAEP',
                    modulusLength: 2048,
                    publicExponent: new Uint8Array([0x01, 0x00, 0x01]), // 65537
                    hash: 'SHA-256'
                },
                true, // Key must be extractable so we can export it
                ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
            );

            // 2. Export Public Key to PEM format
            const exportedPublicKey = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
            const publicKeyBase64 = this.arrayBufferToBase64(exportedPublicKey);
            const publicKeyPem = `-----BEGIN PUBLIC KEY-----\n${this.chunkString(publicKeyBase64, 64)}-----END PUBLIC KEY-----`;

            // 3. Derive Master Key (K_master) from new password using PBKDF2
            this.updateStatus('Deriving PBKDF2 Master Key parameters...', 'info');
            const enc = new TextEncoder();
            const pbkdf2BaseKey = await window.crypto.subtle.importKey(
                'raw',
                enc.encode(newPassword),
                'PBKDF2',
                false,
                ['deriveKey']
            );

            const newSalt = window.crypto.getRandomValues(new Uint8Array(16));
            const newMasterKey = await window.crypto.subtle.deriveKey(
                {
                    name: 'PBKDF2',
                    salt: newSalt,
                    iterations: 100000,
                    hash: 'SHA-256'
                },
                pbkdf2BaseKey,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt']
            );

            // 4. Export & Encrypt the newly minted Private Key (PKCS#8)
            this.updateStatus('Encrypting private identity key...', 'info');
            const exportedPrivateKey = await window.crypto.subtle.exportKey('pkcs8', keyPair.privateKey);
            const newIv = window.crypto.getRandomValues(new Uint8Array(12));
            const newEncryptedPrivateKeyBuffer = await window.crypto.subtle.encrypt(
                {
                    name: 'AES-GCM',
                    iv: newIv
                },
                newMasterKey,
                exportedPrivateKey
            );

            const newEncryptedEnvelope = JSON.stringify({
                ciphertext: this.arrayBufferToBase64(newEncryptedPrivateKeyBuffer),
                salt: this.arrayToHex(newSalt),
                iv: this.arrayToHex(newIv)
            });

            // 5. Inject parameters as hidden form fields and dispatch
            this.injectHiddenField('new_public_key', publicKeyPem);
            this.injectHiddenField('new_encrypted_private_key', newEncryptedEnvelope);

            this.updateStatus('Security updates prepared. Submitting to vault...', 'info');
            this.formTarget.submit();

        } catch (err) {
            console.error('Password reset ceremony collapsed:', err);
            this.updateStatus(`Security key generation failed: ${err.message}`, 'error');
            this.unlockUI();
        }
    }

    // --- Parsing Utility Helpers ---

    arrayBufferToBase64(buffer) {
        let binary = '';
        const bytes = new Uint8Array(buffer);
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    arrayToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    chunkString(str, length) {
        const numChunks = Math.ceil(str.length / length);
        const chunks = new Array(numChunks);
        for (let i = 0, o = 0; i < numChunks; ++i, o += length) {
            chunks[i] = str.substr(o, length);
        }
        return chunks.join('\n') + '\n';
    }

    injectHiddenField(name, value) {
        let input = this.formTarget.querySelector(`input[name="${name}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            this.formTarget.appendChild(input);
        }
        input.value = value;
    }

    // --- UI Helpers ---

    lockUI(statusText) {
        this.submitButtonTarget.disabled = true;
        this.updateStatus(statusText, 'info');
    }

    unlockUI() {
        this.submitButtonTarget.disabled = false;
    }

    updateStatus(message, type) {
        this.statusIndicatorTarget.classList.remove('d-none');
        this.statusIndicatorTarget.className = `status-alert alert-${type}`;
        this.statusIndicatorTarget.textContent = message;
    }
}
