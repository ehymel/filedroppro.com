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

        // Find the submit button to display visual cryptographic progress
        const submitButton = this.formTarget.querySelector('button[type="submit"]');
        const originalButtonText = submitButton ? submitButton.innerHTML : 'Submit';

        try {
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = `
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Generating Secure Keys...
                `;
            }

            // --- 1. Generate Asymmetric RSA-OAEP Key Pair ---
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

            // --- 2. Derive Master Symmetric Key (K_master) from Password using PBKDF2 ---
            const enc = new TextEncoder();
            const passwordBytes = enc.encode(masterPassword);

            // Generate a random 16-byte salt for key derivation
            const salt = window.crypto.getRandomValues(new Uint8Array(16));

            const pbkdf2BaseKey = await window.crypto.subtle.importKey(
                'raw',
                passwordBytes,
                'PBKDF2',
                false,
                ['deriveBits', 'deriveKey']
            );

            const derivedKey = await window.crypto.subtle.deriveKey(
                {
                    name: 'PBKDF2',
                    salt: salt,
                    iterations: 100000,
                    hash: 'SHA-256'
                },
                pbkdf2BaseKey,
                { name: 'AES-GCM', length: 256 },
                false, // Keep derived key locked in memory
                ['encrypt', 'decrypt']
            );

            // --- 3. Export Public Key to standard PEM format ---
            const exportedPublicKeyBuffer = await window.crypto.subtle.exportKey('spki', keyPair.publicKey);
            const publicKeyBase64 = this.arrayBufferToBase64(exportedPublicKeyBuffer);
            const publicKeyPem = `-----BEGIN PUBLIC KEY-----\n${this.chunkString(publicKeyBase64, 64)}-----END PUBLIC KEY-----`;

            // --- 4. Export & Encrypt Private Key (PKCS#8) using derived key (K_master) ---
            const exportedPrivateKeyBuffer = await window.crypto.subtle.exportKey('pkcs8', keyPair.privateKey);

            // Generate a random 12-byte initialization vector for AES-GCM
            const iv = window.crypto.getRandomValues(new Uint8Array(12));

            const encryptedPrivateKeyBuffer = await window.crypto.subtle.encrypt(
                {
                    name: 'AES-GCM',
                    iv: iv
                },
                derivedKey,
                exportedPrivateKeyBuffer
            );

            // Package ciphertext, salt, and iv into a unified JSON transport payload
            const encryptedPrivateKeyPayload = JSON.stringify({
                ciphertext: this.arrayBufferToBase64(encryptedPrivateKeyBuffer),
                salt: this.arrayToHex(salt),
                iv: this.arrayToHex(iv)
            });

            // --- 5. Dynamically Append Hidden Fields to the Form ---
            this.injectHiddenField('registration_form[publicKey]', publicKeyPem);
            this.injectHiddenField('registration_form[encryptedPrivateKey]', encryptedPrivateKeyPayload);

            // Resubmit the form cleanly with all secure cryptographic data mapped
            this.formTarget.submit();
        } catch (error) {
            console.error('Cryptographic Ceremony Failed:', error);

            // Re-enable button state on error
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            }

            // Prevent silent hangs: fall back to backend validation pipeline
            this.formTarget.submit();
        }
    }

    // --- Cryptographic Utility Helper Functions ---

    /**
     * Converts a binary ArrayBuffer into a safe Base64 string.
     */
    arrayBufferToBase64(buffer) {
        let binary = '';
        const bytes = new Uint8Array(buffer);
        const len = bytes.byteLength;
        for (let i = 0; i < len; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary);
    }

    /**
     * Converts an unsigned integer array to a clean Hexadecimal string.
     */
    arrayToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    /**
     * Slices a long base64 block into 64-character formatted lines for PEM standard structure.
     */
    chunkString(str, length) {
        const numChunks = Math.ceil(str.length / length);
        const chunks = new Array(numChunks);
        for (let i = 0, o = 0; i < numChunks; ++i, o += length) {
            chunks[i] = str.substr(o, length);
        }
        return chunks.join('\n') + '\n';
    }

    /**
     * Injects a hidden input value into the target form.
     */
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

    // --- Helper UI Methods ---
    showElement(element) {
        if (element) {
            element.classList.remove('d-none');
        }
    }

    hideElement(element) {
        if (element) {
            element.classList.add('d-none');
        }
    }
}
