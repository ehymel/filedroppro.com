import { Controller } from '@hotwired/stimulus';

/**
 * Registration Controller with Institutional Key Escrow (Pattern 2)
 * * Handles local E2EE asymmetric keypair generation for the registering user.
 * * If creating a new firm, generates the Tenant Master Escrow Keypair,
 * wrapping the Tenant Private Key using the Admin's Public Key.
 */
export default class extends Controller {
    static targets = [
        'firmSection', 'joinSection', 'form', 'passwordInput',
        'recoveryModal', 'recoveryCodeOutput', 'recoveryContinue', 'recoveryConfirm'
    ];
    static values = {
        hasInvitation: Boolean
    };

    connect() {
        // Only run form setup if the registering user is NOT on a pre-assigned invitation route
        if (!this.hasInvitationValue) {
            this.toggleSections();
        }
    }

    toggleSections() {
        const activeRadio = this.element.querySelector('input[name="registration_form[registrationMode]"]:checked');
        if (!activeRadio) return;

        const modeValue = activeRadio.value;

        if (modeValue === 'new') {
            this.showElement(this.firmSectionTarget);
            this.hideElement(this.joinSectionTarget);
        } else if (modeValue === 'join') {
            this.hideElement(this.firmSectionTarget);
            this.showElement(this.joinSectionTarget);
        }
    }

    async handleSubmit(event) {
        event.preventDefault();

        const masterPassword = this.passwordInputTarget.value;
        if (!masterPassword || masterPassword.length < 8) {
            this.formTarget.submit(); // Fall back to standard form submission so HTML/backend validation fires
            return;
        }

        const submitButton = this.formTarget.querySelector('button[type="submit"]');
        const originalButtonText = submitButton ? submitButton.innerHTML : 'Submit';

        // Holds the one-time recovery code to display before submission (new firm only).
        let recoveryDisplayCode = null;

        try {
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = `
                    <svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Generating Secure Workspace...
                `;
            }

            const activeRadio = this.element.querySelector('input[name="registration_form[registrationMode]"]:checked');
            const isNewTenant = !this.hasInvitationValue && activeRadio && activeRadio.value === 'new';

            // --- 1. Generate Admin Personal Key Pair (RSA-OAEP) ---
            const adminKeyPair = await window.crypto.subtle.generateKey(
                {
                    name: 'RSA-OAEP',
                    modulusLength: 2048,
                    publicExponent: new Uint8Array([0x01, 0x00, 0x01]), // 65537
                    hash: 'SHA-256'
                },
                true, // Keys must be extractable so we can export/serialize them
                ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
            );

            // Export Admin Public Key to PEM format
            const exportedAdminPublicKey = await window.crypto.subtle.exportKey('spki', adminKeyPair.publicKey);
            const adminPublicKeyBase64 = this.arrayBufferToBase64(exportedAdminPublicKey);
            const adminPublicKeyPem = `-----BEGIN PUBLIC KEY-----\n${this.chunkString(adminPublicKeyBase64, 64)}-----END PUBLIC KEY-----`;

            // --- 2. Derive Admin Master Symmetric Key (K_master) via PBKDF2 ---
            const enc = new TextEncoder();
            const passwordBytes = enc.encode(masterPassword);
            const salt = window.crypto.getRandomValues(new Uint8Array(16));

            const pbkdf2BaseKey = await window.crypto.subtle.importKey(
                'raw',
                passwordBytes,
                'PBKDF2',
                false,
                ['deriveKey']
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
                false,
                ['encrypt', 'decrypt']
            );

            // Export & Encrypt Admin Private Key using derived key (K_master)
            const exportedAdminPrivateKey = await window.crypto.subtle.exportKey('pkcs8', adminKeyPair.privateKey);
            const iv = window.crypto.getRandomValues(new Uint8Array(12));

            const encryptedAdminPrivateKey = await window.crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: iv },
                derivedKey,
                exportedAdminPrivateKey
            );

            const adminPrivateKeyPayload = JSON.stringify({
                ciphertext: this.arrayBufferToBase64(encryptedAdminPrivateKey),
                salt: this.arrayToHex(salt),
                iv: this.arrayToHex(iv)
            });

            // --- 3. Generate Tenant Master Escrow Keypair (If registering a new organization) ---
            if (isNewTenant) {
                console.log('Generating Tenant Master Escrow Keypair...');

                const tenantKeyPair = await window.crypto.subtle.generateKey(
                    {
                        name: 'RSA-OAEP',
                        modulusLength: 2048,
                        publicExponent: new Uint8Array([0x01, 0x00, 0x01]),
                        hash: 'SHA-256'
                    },
                    true,
                    ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
                );

                // Export Tenant Public Key to PEM format
                const exportedTenantPublicKey = await window.crypto.subtle.exportKey('spki', tenantKeyPair.publicKey);
                const tenantPublicKeyBase64 = this.arrayBufferToBase64(exportedTenantPublicKey);
                const tenantPublicKeyPem = `-----BEGIN PUBLIC KEY-----\n${this.chunkString(tenantPublicKeyBase64, 64)}-----END PUBLIC KEY-----`;

                // Export & Wrap Tenant Private Key using the Admin's Public Key (Asymmetric Escrow Envelope)
                const exportedTenantPrivateKey = await window.crypto.subtle.exportKey('pkcs8', tenantKeyPair.privateKey);

                // RSA-OAEP 2048 cannot encrypt the full private key directly due to size limits.
                // We use a hybrid approach: encrypt the private key with AES-GCM, then wrap the AES key with RSA-OAEP.
                const aesKey = await window.crypto.subtle.generateKey(
                    { name: 'AES-GCM', length: 256 },
                    true,
                    ['encrypt', 'decrypt', 'wrapKey', 'unwrapKey']
                );

                const tenantIv = window.crypto.getRandomValues(new Uint8Array(12));
                const encryptedTenantPrivateKey = await window.crypto.subtle.encrypt(
                    { name: 'AES-GCM', iv: tenantIv },
                    aesKey,
                    exportedTenantPrivateKey
                );

                const wrappedAesKey = await window.crypto.subtle.wrapKey(
                    'raw',
                    aesKey,
                    adminKeyPair.publicKey,
                    { name: 'RSA-OAEP' }
                );

                const wrappedTenantPrivateKeyPayload = JSON.stringify({
                    ciphertext: this.arrayBufferToBase64(encryptedTenantPrivateKey),
                    wrappedKey: this.arrayBufferToBase64(wrappedAesKey),
                    iv: this.arrayToHex(tenantIv)
                });

                // Append Tenant Escrow credentials to the form post transaction
                this.injectHiddenField('registration_form[tenantPublicKey]', tenantPublicKeyPem);
                this.injectHiddenField('registration_form[wrappedTenantPrivateKey]', wrappedTenantPrivateKeyPayload);

                // --- 4. Build the recovery-code custody envelope ---
                // A second, admin-key-independent copy of the tenant private key,
                // encrypted under a key derived from a one-time recovery code. This
                // is what lets an admin who forgot their password recover escrow.
                const recovery = await this.buildRecoveryEnvelope(exportedTenantPrivateKey);
                recoveryDisplayCode = recovery.displayCode;
                this.injectHiddenField('registration_form[recoveryWrappedPrivateKey]', recovery.envelope);
            }

            // Append Admin Personal credentials to the form post transaction
            this.injectHiddenField('registration_form[publicKey]', adminPublicKeyPem);
            this.injectHiddenField('registration_form[encryptedPrivateKey]', adminPrivateKeyPayload);

            if (isNewTenant && recoveryDisplayCode) {
                // Pause and force the admin to save their one-time recovery code
                // (never transmitted) before the registration actually submits.
                this.presentRecoveryCode(recoveryDisplayCode);
            } else {
                this.formTarget.submit();
            }

        } catch (error) {
            console.error('Cryptographic Escrow Ceremony Failed:', error);
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalButtonText;
            }
            this.formTarget.submit();
        }
    }

    // --- Recovery-code custody -------------------------------------------

    /**
     * Encrypts the exported tenant private key under a key derived from a fresh
     * one-time recovery code. Returns the JSON envelope (to store) and the
     * human-readable code (to show the admin — never sent to the server).
     */
    async buildRecoveryEnvelope(exportedTenantPrivateKey) {
        const codeBytes = window.crypto.getRandomValues(new Uint8Array(20)); // 160 bits
        const displayCode = this.bytesToBase32(codeBytes);

        const enc = new TextEncoder();
        const salt = window.crypto.getRandomValues(new Uint8Array(16));
        const baseKey = await window.crypto.subtle.importKey(
            'raw', enc.encode(this.normalizeRecoveryCode(displayCode)), 'PBKDF2', false, ['deriveKey']
        );
        const recoveryKey = await window.crypto.subtle.deriveKey(
            { name: 'PBKDF2', salt: salt, iterations: 100000, hash: 'SHA-256' },
            baseKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt']
        );

        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        const ciphertext = await window.crypto.subtle.encrypt(
            { name: 'AES-GCM', iv: iv }, recoveryKey, exportedTenantPrivateKey
        );

        const envelope = JSON.stringify({
            ciphertext: this.arrayBufferToBase64(ciphertext),
            salt: this.arrayToHex(salt),
            iv: this.arrayToHex(iv)
        });

        return { envelope, displayCode };
    }

    /** RFC 4648 Base32, grouped into 4-char blocks for readability. */
    bytesToBase32(bytes) {
        const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        let bits = 0, value = 0, output = '';
        for (let i = 0; i < bytes.length; i++) {
            value = (value << 8) | bytes[i];
            bits += 8;
            while (bits >= 5) {
                output += alphabet[(value >>> (bits - 5)) & 31];
                bits -= 5;
            }
        }
        if (bits > 0) {
            output += alphabet[(value << (5 - bits)) & 31];
        }
        return output.match(/.{1,4}/g).join('-');
    }

    /** Strip formatting so entry with/without dashes/spaces derives the same key. */
    normalizeRecoveryCode(code) {
        return code.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    }

    presentRecoveryCode(displayCode) {
        if (!this.hasRecoveryModalTarget) {
            // No modal available for some reason — fail safe by submitting.
            this.formTarget.submit();
            return;
        }
        this.recoveryCodeOutputTarget.textContent = displayCode;
        this.showElement(this.recoveryModalTarget);
    }

    copyRecoveryCode() {
        const code = this.recoveryCodeOutputTarget.textContent;
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code);
        }
    }

    toggleRecoveryConfirm() {
        if (this.hasRecoveryContinueTarget && this.hasRecoveryConfirmTarget) {
            this.recoveryContinueTarget.disabled = !this.recoveryConfirmTarget.checked;
        }
    }

    confirmRecoverySave() {
        this.formTarget.submit();
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
