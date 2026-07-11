import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';
import Swal from 'sweetalert2';

/**
 * Escrow Sync Stimulus Controller
 * * Coordinates the Pattern 2 (Institutional Key Escrow) recovery ceremony.
 * * Decrypts the Tenant Escrow Private Key using the Admin's Personal Private Key.
 * * Decrypts symmetric document keys and re-wraps them under the resetting user's public key.
 */
export default class extends Controller {
    static targets = ['passwordModal', 'passwordInput', 'statusMessage', 'submitButton'];
    static values = {
        adminEncryptedPrivateKey: String, // Holds the logged-in admin's private key envelope
        tenantWrappedPrivateKey: String // Holds the admin's tenant private key envelope
    };

    connect() {
        this.unlockedAdminPrivateKey = null;
        this.unlockedAdminPrivateKeyBuffer = null; // Store raw unlocked private key buffer for legacy fallbacks
        this.activePendingUserId = null;
        this.activeSyncDataUrl = null;
        this.activeSubmitUrl = null;
    }

    /**
     * Initializes the approval sequence, prompting the admin for their master password.
     */
    startSync(event) {
        if (!this.tenantWrappedPrivateKeyValue) {
            this.showMissingTenantPrivateKeyNotice();
            return;
        }

        const button = event.currentTarget;
        this.activePendingUserId = button.getAttribute('data-user-id');
        this.activeSyncDataUrl = button.getAttribute('data-sync-data-url');
        this.activeSubmitUrl = button.getAttribute('data-submit-url');

        if (this.unlockedAdminPrivateKey) {
            this.executeEscrowCeremony();
            return;
        }

        if (!this.modal) {
            if (this.passwordModalTarget) {
                this.modal = new Modal(this.passwordModalTarget);
            } else {
                console.warn('Missing target element "cryptoModal" for "escrow-sync" controller');
                return;
            }
        }

        // Show the Bootstrap modal to capture password context
        this.modal.show();
    }

    /**
     * Unlocks the Admin's RSA private key.
     */
    async unlockAdminPrivateKey() {
        const password = this.passwordInputTarget.value;
        if (!password) return;

        try {
            const privateKeyEnvelope = JSON.parse(this.adminEncryptedPrivateKeyValue);
            const enc = new TextEncoder();

            // 1. Re-derive Admin's master key (K_master_admin) using the stored salt parameters
            const pbkdf2BaseKey = await window.crypto.subtle.importKey(
                'raw',
                enc.encode(password),
                'PBKDF2',
                false,
                ['deriveKey']
            );

            const masterKey = await window.crypto.subtle.deriveKey(
                {
                    name: 'PBKDF2',
                    salt: this.hexToUint8Array(privateKeyEnvelope.salt),
                    iterations: 100000,
                    hash: 'SHA-256'
                },
                pbkdf2BaseKey,
                { name: 'AES-GCM', length: 256 },
                false,
                ['decrypt']
            );

            // 2. Decrypt the Admin's raw private key buffer
            this.unlockedAdminPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: this.hexToUint8Array(privateKeyEnvelope.iv)
                },
                masterKey,
                this.base64ToArrayBuffer(privateKeyEnvelope.ciphertext)
            );

            // 3. Import the private key object with standard multi-use capabilities
            this.unlockedAdminPrivateKey = await window.crypto.subtle.importKey(
                'pkcs8',
                this.unlockedAdminPrivateKeyBuffer,
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                false,
                ['decrypt', 'unwrapKey']
            );

            this.unlockUI();

            await this.executeEscrowCeremony();

        } catch (error) {
            console.error('Failed to unlock admin credentials:', error);
            this.unlockUI();
            Swal.fire({
                title: 'Unlock Failed',
                text: 'Invalid Master Password. Administrative workspace could not be unlocked.',
                icon: 'error',
                confirmButtonColor: '#0d6efd'
            });
        }
    }

    /**
     * Runs the cryptographic escrow decryption and re-wrapping loop.
     */
    async executeEscrowCeremony() {
        this.lockUI('Connecting to secure key vault...');

        try {
            // Step A: Fetch Escrow Metadata & User's new Public Key from Symfony
            const response = await fetch(this.activeSyncDataUrl);
            if (!response.ok) throw new Error('Could not retrieve tenant escrow metadata.');

            const data = await response.json();
            if (data.error) throw new Error(data.error);

            this.updateStatus('Decrypting Master Tenant Escrow key...', 'info');

            // Step B: Decrypt the Tenant Escrow Private Key using the Admin's Private Key
            if (!data.wrappedTenantPrivateKey) {
                this.showMissingTenantPrivateKeyNotice();
                return;
            }

            const tenantEnvelope = JSON.parse(data.wrappedTenantPrivateKey);
            let decryptedTenantPrivateKeyBuffer;

            if (tenantEnvelope.wrappedKey) {
                // Hybrid Encryption path
                const wrappedAesKeyBytes = this.base64ToArrayBuffer(tenantEnvelope.wrappedKey);
                let aesKey;

                try {
                    // Try unwrap using standard SHA-256 admin keys
                    aesKey = await window.crypto.subtle.unwrapKey(
                        'raw',
                        wrappedAesKeyBytes,
                        this.unlockedAdminPrivateKey,
                        { name: 'RSA-OAEP' },
                        { name: 'AES-GCM', length: 256 },
                        true,
                        ['decrypt']
                    );
                } catch (unwrapError) {
                    console.warn("Hybrid key unwrap failed under SHA-256. Attempting legacy SHA-1 fallback...");
                    try {
                        const legacyAdminPrivateKey = await window.crypto.subtle.importKey(
                            'pkcs8',
                            this.unlockedAdminPrivateKeyBuffer,
                            { name: 'RSA-OAEP', hash: 'SHA-1' },
                            false,
                            ['decrypt', 'unwrapKey']
                        );
                        aesKey = await window.crypto.subtle.unwrapKey(
                            'raw',
                            wrappedAesKeyBytes,
                            legacyAdminPrivateKey,
                            { name: 'RSA-OAEP' },
                            { name: 'AES-GCM', length: 256 },
                            true,
                            ['decrypt']
                        );
                    } catch (sha1UnwrapError) {
                        throw new Error(`Hybrid key decryption failed under both hashes: ${sha1UnwrapError.message}`);
                    }
                }

                decryptedTenantPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                    {
                        name: 'AES-GCM',
                        iv: this.hexToUint8Array(tenantEnvelope.iv)
                    },
                    aesKey,
                    this.base64ToArrayBuffer(tenantEnvelope.ciphertext)
                );
            } else {
                // Legacy path: Direct RSA encryption
                const wrappedTenantPrivateKeyBytes = this.base64ToArrayBuffer(tenantEnvelope.ciphertext);
                try {
                    decryptedTenantPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                        { name: 'RSA-OAEP' },
                        this.unlockedAdminPrivateKey,
                        wrappedTenantPrivateKeyBytes
                    );
                } catch (sha256DirectError) {
                    console.warn("Direct RSA decryption failed under SHA-256. Attempting legacy SHA-1 fallback...");
                    try {
                        const legacyAdminPrivateKey = await window.crypto.subtle.importKey(
                            'pkcs8',
                            this.unlockedAdminPrivateKeyBuffer,
                            { name: 'RSA-OAEP', hash: 'SHA-1' },
                            false,
                            ['decrypt', 'unwrapKey']
                        );
                        decryptedTenantPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                            { name: 'RSA-OAEP' },
                            legacyAdminPrivateKey,
                            wrappedTenantPrivateKeyBytes
                        );
                    } catch (sha1DirectError) {
                        throw new Error(`Asymmetric decryption failed under both standard and legacy hash configurations: ${sha1DirectError.message}`);
                    }
                }
            }

            // Import the Escrow Private Key
            const tenantEscrowPrivateKey = await window.crypto.subtle.importKey(
                'pkcs8',
                decryptedTenantPrivateKeyBuffer,
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                false,
                ['decrypt', 'unwrapKey']
            );

            // Step C: Import the resetting user's newly generated Public Key
            this.updateStatus('Importing target user\'s new public identity...', 'info');
            const userPublicKey = await window.crypto.subtle.importKey(
                'spki',
                this.convertPemToBinary(data.pendingUserPublicKey),
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                false,
                ['encrypt', 'wrapKey']
            );

            // Step D: Decrypt symmetric keys using Escrow and re-wrap for the target user
            const reKeyedMap = {};
            const totalEnvelopes = data.escrowEnvelopes.length;

            for (let i = 0; i < totalEnvelopes; i++) {
                const env = data.escrowEnvelopes[i];
                this.updateStatus(`Decrypting & Re-encrypting envelope ${i + 1} of ${totalEnvelopes}...`, 'info');

                try {
                    const wrappedSymmetricBytes = this.hexToUint8Array(env.wrappedEscrowKeyHex);
                    let rawSymmetricKeyBuffer;

                    // Support fallback logic directly within the document key decryption loop
                    try {
                        rawSymmetricKeyBuffer = await window.crypto.subtle.decrypt(
                            { name: 'RSA-OAEP' },
                            tenantEscrowPrivateKey,
                            wrappedSymmetricBytes
                        );
                    } catch (escrowSha256Error) {
                        console.warn(`Symmetric key decryption failed under SHA-256 for envelope ${env.documentId}. Trying legacy SHA-1 escrow fallback...`);
                        const legacyEscrowPrivateKey = await window.crypto.subtle.importKey(
                            'pkcs8',
                            decryptedTenantPrivateKeyBuffer,
                            { name: 'RSA-OAEP', hash: 'SHA-1' },
                            false,
                            ['decrypt', 'unwrapKey']
                        );
                        rawSymmetricKeyBuffer = await window.crypto.subtle.decrypt(
                            { name: 'RSA-OAEP' },
                            legacyEscrowPrivateKey,
                            wrappedSymmetricBytes
                        );
                    }

                    // 2. Wrap symmetric key (K_sym) with User's new Public Key
                    const wrappedUserKeyBuffer = await window.crypto.subtle.encrypt(
                        { name: 'RSA-OAEP' },
                        userPublicKey,
                        rawSymmetricKeyBuffer
                    );

                    reKeyedMap[env.documentId] = this.arrayBufferToHex(wrappedUserKeyBuffer);
                } catch (err) {
                    console.warn(`Escrow unwrap failed for document: ${env.documentId}. Skipping.`, err);
                }
            }

            // Step E: POST the re-wrapped key blocks back to Symfony
            this.updateStatus('Submitting re-keyed parameters to server...', 'info');
            const submitResponse = await fetch(this.activeSubmitUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ reKeyedMap: reKeyedMap })
            });

            if (!submitResponse.ok) throw new Error('Key synchronization submission rejected by the server.');

            this.updateStatus('Synchronization complete! Reloading page...', 'success');
            window.location.reload();

        } catch (err) {
            console.error('Escrow Sync Ceremony Collapsed:', err);
            Swal.fire({
                title: 'Escrow Sync Ceremony Failure',
                text: err.message,
                icon: 'error',
                confirmButtonColor: '#0d6efd'
            });
            this.statusMessageTarget.classList.add('d-none');
            this.unlockUI();
        }
    }

    // --- Parsing Utility Helpers ---

    showMissingTenantPrivateKeyNotice() {
        Swal.fire({
            title: 'Missing Escrowed Private Key',
            html: 'There is no escrowed private key set for this tenant.<br>You cannot unlock the session until you escrow a private key for this tenant.',
            icon: 'warning',
            confirmButtonColor: '#0d6efd'
        });
    }

    convertPemToBinary(pem) {
        const lines = pem.split('\n');
        let base64 = '';
        for (let line of lines) {
            if (line.includes('PUBLIC KEY') || line.trim() === '') continue;
            base64 += line.trim();
        }
        const binaryStr = window.atob(base64);
        const len = binaryStr.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) {
            bytes[i] = binaryStr.charCodeAt(i);
        }
        return bytes.buffer;
    }

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

    arrayBufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    // --- UI Helpers ---

    lockUI(statusText) {
        this.updateStatus(statusText, 'info');
    }

    unlockUI() {
        this.passwordInputTarget.value = '';
        if (this.modal) {
            this.modal.hide();
        }
    }

    updateStatus(message, type) {
        this.statusMessageTarget.classList.remove('d-none');
        this.statusMessageTarget.className = `alert alert-${type}`;
        this.statusMessageTarget.textContent = message;
    }
}
