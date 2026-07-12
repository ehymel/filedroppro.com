import { Controller } from '@hotwired/stimulus';

/**
 * Password Update Controller
 * * Intercepts password change/reset forms to execute Web Crypto ceremonies.
 * * Handles local decryption and re-encryption of private keys, or regeneration of brand-new identities.
 */
export default class extends Controller {
    static targets = [
        'form',
        'currentPasswordInput',
        'newPasswordInput',
        'confirmPasswordInput',
        'submitButton',
        'statusIndicator'
    ];

    static values = {
        encryptedPrivateKey: String, // Stored encrypted identity of the logged-in user
        mode: String // 'change' (logged-in re-encryption) or 'reset' (forgot key generation)
    };

    /**
     * Coordinates the form interception before standard dispatch.
     */
    async executeCeremony(event) {
        event.preventDefault();

        // Check if we have the inputs needed. If it's a Symfony form, they might be nested.
        const newPassword = this.newPasswordInputTarget.value;
        const confirmPassword = this.confirmPasswordInputTarget.value;

        if (newPassword.length < 8) {
            this.updateStatus('Your password must be at least 8 characters long.', 'danger');
            return;
        }

        if (newPassword !== confirmPassword) {
            this.updateStatus('New password and confirmation fields do not match.', 'danger');
            return;
        }

        this.lockUI('Processing cryptographic keys...');

        const mode = this.modeValue || 'change';

        console.log('Password Ceremony Mode:', mode);

        try {
            if (mode === 'change') {
                await this.performReencryptionCeremony(newPassword);
            } else if (mode === 'reset') {
                await this.performRegenerationCeremony(newPassword);
            } else {
                throw new Error(`Invalid security workflow mode: ${mode}`);
            }
        } catch (err) {
            console.error('Password Ceremony Collapsed:', err);
            this.updateStatus(`Security workflow failed: ${err.message}`, 'danger');
            this.unlockUI();
        }
    }

    /**
     * Ceremony A: Safe Re-encryption for Active Users.
     * Unlocks the current private key and wraps it with the new master password.
     */
    async performReencryptionCeremony(newPassword) {
        let currentPassword = '';
        if (this.hasCurrentPasswordInputTarget) {
            currentPassword = this.currentPasswordInputTarget.value;
        }

        if (!currentPassword) {
            throw new Error('You must enter your current password to unlock your existing key envelope.');
        }

        // 1. Verify and parse existing encrypted envelope data
        if (!this.encryptedPrivateKeyValue || this.encryptedPrivateKeyValue.trim() === '') {
            throw new Error('Your browser is missing the E2EE key envelope. Please verify you are logged in with active security keys.');
        }

        let envelope;
        try {
            envelope = JSON.parse(this.encryptedPrivateKeyValue);
        } catch (parseError) {
            throw new Error('The E2EE private key envelope is corrupted or improperly formatted. JSON decoding failed.');
        }

        // 2. Derive Old K_master from Current Password
        this.updateStatus('Deriving current workspace key...', 'info');
        const enc = new TextEncoder();
        const oldPbkdf2BaseKey = await window.crypto.subtle.importKey(
            'raw',
            enc.encode(currentPassword),
            'PBKDF2',
            false,
            ['deriveKey']
        );

        const oldMasterKey = await window.crypto.subtle.deriveKey(
            {
                name: 'PBKDF2',
                salt: this.hexToUint8Array(envelope.salt),
                iterations: 100000,
                hash: 'SHA-256'
            },
            oldPbkdf2BaseKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['decrypt']
        );

        // 3. Decrypt the Private Key buffer locally in memory
        this.updateStatus('Unlocking private identity credentials...', 'info');
        let rawPrivateKeyBuffer;
        try {
            rawPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: this.hexToUint8Array(envelope.iv)
                },
                oldMasterKey,
                this.base64ToArrayBuffer(envelope.ciphertext)
            );
        } catch (decryptionError) {
            // An incorrect current password results in an AES-GCM authenticated decryption tag mismatch,
            // which rejects the promise. Catch it cleanly and provide explicit feedback.
            throw new Error('Your current password is incorrect. Unable to unlock your security workspace.');
        }

        // 4. Derive New K_master from New Password
        this.updateStatus('Deriving new security credentials...', 'info');
        const newPbkdf2BaseKey = await window.crypto.subtle.importKey(
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
            newPbkdf2BaseKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt']
        );

        // 5. Re-encrypt the raw private key buffer under new master password
        this.updateStatus('Encrypting keys under new password...', 'info');
        const newIv = window.crypto.getRandomValues(new Uint8Array(12));
        const newEncryptedBuffer = await window.crypto.subtle.encrypt(
            {
                name: 'AES-GCM',
                iv: newIv
            },
            newMasterKey,
            rawPrivateKeyBuffer
        );

        // 6. Assemble the payload envelope and submit
        const updatedEnvelope = JSON.stringify({
            ciphertext: this.arrayBufferToBase64(newEncryptedBuffer),
            salt: this.arrayToHex(newSalt),
            iv: this.arrayToHex(newIv)
        });

        this.injectHiddenField('new_encrypted_private_key', updatedEnvelope);
        this.formTarget.submit();
    }

    /**
     * Ceremony B: Forgotten Password Reset.
     * Generates a completely new RSA identity keypair since the old key envelope is lost.
     */
    async performRegenerationCeremony(newPassword) {
        this.updateStatus('Generating a brand-new cryptographic identity...', 'info');

        // 1. Generate new Asymmetric Identity Key Pair (RSA-OAEP)
        const keyPair = await window.crypto.subtle.generateKey(
            {
                name: 'RSA-OAEP',
                modulusLength: 2048,
                publicExponent: new Uint8Array([0x01, 0x00, 0x01]), // 65537
                hash: 'SHA-256'
            },
            true, // Keys must be extractable so we can export/serialize them
            ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
        );

        // 2. Export new Public Key to PEM format
        const exportedPublicKey = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
        const publicKeyBase64 = this.arrayBufferToBase64(exportedPublicKey);
        const publicKeyPem = `-----BEGIN PUBLIC KEY-----\n${this.chunkString(publicKeyBase64, 64)}-----END PUBLIC KEY-----`;

        // 3. Derive New K_master from New Password
        this.updateStatus('Deriving master key parameters...', 'info');
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
        this.updateStatus('Encrypting brand-new credentials...', 'info');
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

        // 5. Inject parameters and submit
        this.injectHiddenField('new_public_key', publicKeyPem);
        this.injectHiddenField('new_encrypted_private_key', newEncryptedEnvelope);
        this.formTarget.submit();
    }

    // --- Parsing Utility Helpers ---

    hexToUint8Array(hexString) {
        return new Uint8Array(hexString.match(/.{1,2}/g).map(byte => parseInt(byte, 16)));
    }

    base64ToArrayBuffer(base64) {
        const binaryString = window.atob(base64);
        const len = binaryString.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = binaryString.charCodeAt(i);
        }
        return bytes.buffer;
    }

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
        // Attempt to find existing field, including those that might be nested in a Symfony form
        let input = this.formTarget.querySelector(`input[name="${name}"], input[name$="[${name}]"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            this.formTarget.appendChild(input);
        }
        input.value = value;
    }

    // --- UI State Helpers ---

    lockUI(statusText) {
        this.submitButtonTarget.disabled = true;
        this.updateStatus(statusText, 'info');
    }

    unlockUI() {
        this.submitButtonTarget.disabled = false;
    }

    updateStatus(message, type) {
        this.statusIndicatorTarget.classList.remove('d-none');
        this.statusIndicatorTarget.className = `alert alert-${type}`;
        this.statusIndicatorTarget.textContent = message;
    }
}
