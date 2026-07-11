import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

/**
 * Document Viewer Controller
 * * Intercepts document requests, derives local private credentials via PBKDF2,
 * unwraps unique symmetric envelopes, and triggers zero-server client-side file decryption.
 */
export default class extends Controller {
    static targets = ['cryptoModal', 'masterPasswordInput'];
    static values = {
        encryptedPrivateKey: String // Holds the JSON string containing private key ciphertext, salt, and iv
    };

    connect() {
        // Clear runtime transient variables from local memory
        this.unlockedPrivateKey = null;
    }

    /**
     * Prompts the user to input their master password to decrypt their private key into memory.
     */
    ensureCryptoSession(pendingAction) {
        if (this.unlockedPrivateKey) {
            pendingAction();
            return;
        }

        if (!this.modal) {
            if (this.hasCryptoModalTarget) {
                this.modal = new Modal(this.cryptoModalTarget);
            } else {
                console.warn('Missing target element "cryptoModal" for "document-viewer" controller');
                return;
            }
        }

        // Show the Bootstrap modal to capture password context
        this.modal.show();
        this.cachedAction = pendingAction;
    }

    /**
     * Orchestrates the local PBKDF2 derivation and unlocks the User's RSA Private Key inside the browser.
     */
    async initializeCryptoSession(event) {
        if (event) event.preventDefault();

        if (!this.hasMasterPasswordInputTarget) {
            console.error('Missing master password input target.');
            return;
        }

        const password = this.masterPasswordInputTarget.value;
        if (!password) return;

        try {
            const privateKeyEnvelope = JSON.parse(this.encryptedPrivateKeyValue);

            // 1. Re-derive K_master from password using the stored salt parameters
            const enc = new TextEncoder();
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

            // 2. Decrypt the Private Key payload (PKCS#8 binary buffer block)
            const decryptedPrivateKeyBuffer = await window.crypto.subtle.decrypt(
                {
                    name: 'AES-GCM',
                    iv: this.hexToUint8Array(privateKeyEnvelope.iv)
                },
                masterKey,
                this.base64ToArrayBuffer(privateKeyEnvelope.ciphertext)
            );

            // 3. Import the raw unlocked Private Key object into active session memory
            this.unlockedPrivateKey = await window.crypto.subtle.importKey(
                'pkcs8',
                decryptedPrivateKeyBuffer,
                { name: 'RSA-OAEP', hash: 'SHA-256' },
                false,
                ['decrypt']
            );

            // Clean up UI state and execute the cached download action
            if (this.hasMasterPasswordInputTarget) {
                this.masterPasswordInputTarget.value = '';
            }
            if (this.modal) {
                this.modal.hide();
            }

            if (this.cachedAction) {
                this.cachedAction();
                this.cachedAction = null;
            }

        } catch (error) {
            console.error('Cryptographic Workspace initialization failed:', error);
            alert('Invalid Master Password. Security workspace could not be unlocked.');
        }
    }

    /**
     * Core execution loop coordinating encrypted asset stream acquisition and local unsealing ceremonies.
     */
    decryptAndDownload(event) {
        const button = event.currentTarget;
        const metadataUrl = button.getAttribute('data-metadata-url');
        const downloadUrl = button.getAttribute('data-download-url');

        this.ensureCryptoSession(async () => {
            const originalButtonText = button.textContent;
            button.disabled = true;
            button.textContent = "Decrypting...";

            try {
                // Step A: Fetch Cryptographic Key Envelopes and Metadata from Symfony
                const metadataResponse = await fetch(metadataUrl);
                const metadata = await metadataResponse.json();

                if (metadata.error) {
                    console.error('Error fetching metadata:', metadata.error);
                    throw new Error(metadata.error);
                }

                // Step B: Unwrap the Document's Symmetric AES Key using the User's unlocked Private Key
                const wrappedKeyBytes = this.hexToUint8Array(metadata.wrappedKeyHex);
                const rawSymmetricKeyBuffer = await window.crypto.subtle.decrypt(
                    { name: 'RSA-OAEP', hash: 'SHA-256' },
                    this.unlockedPrivateKey,
                    wrappedKeyBytes
                );

                const aesKey = await window.crypto.subtle.importKey(
                    'raw',
                    rawSymmetricKeyBuffer,
                    { name: 'AES-GCM', length: 256 },
                    false,
                    ['decrypt']
                );

                // Step C: Fetch the raw encrypted document binary payload
                const fileResponse = await fetch(downloadUrl);
                const encryptedFileBuffer = await fileResponse.arrayBuffer();

                // Step D: Decrypt the raw binary file block locally inside the browser
                const decryptedFileBuffer = await window.crypto.subtle.decrypt(
                    {
                        name: 'AES-GCM',
                        iv: this.hexToUint8Array(metadata.iv)
                    },
                    aesKey,
                    encryptedFileBuffer
                );

                // Step E: Convert decrypted buffer data to a browser Blob download link trigger
                const clearBlob = new Blob([decryptedFileBuffer], { type: 'application/octet-stream' });
                const blobUrl = window.URL.createObjectURL(clearBlob);

                const trigger = document.createElement('a');
                trigger.href = blobUrl;

                // Determine original file name and apply robust fallback resolution
                // Supports camelCase (originalFileName) and snake_case (original_file_name) from Symfony serialization,
                // as well as custom button data attributes (data-original-file-name / data-original-filename)
                const originalFileName = metadata.originalFileName
                    || metadata.original_file_name
                    || metadata.filename
                    || button.getAttribute('data-original-file-name')
                    || button.dataset.originalFileName
                    || button.dataset.originalFilename;

                const originalExtension = metadata.originalExtension
                    || metadata.original_extension
                    || 'bin';

                let downloadName = originalFileName || `unsealed_document_${Date.now()}`;

                // Ensure an extension is appended if the filename doesn't contain any dot sequence
                if (downloadName && !downloadName.includes('.')) {
                    downloadName = `${downloadName}.${originalExtension}`;
                }

                trigger.download = downloadName;

                document.body.appendChild(trigger);
                trigger.click();

                // Cleanup
                document.body.removeChild(trigger);
                window.URL.revokeObjectURL(blobUrl);

            } catch (err) {
                console.error('Decryption workflow collapsed:', err);
                alert(`Decryption failure: ${err.message}`);
            } finally {
                button.disabled = false;
                button.textContent = originalButtonText;
            }
        });
    }

    // --- Data Parsing Utility Helpers ---

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
}
