import { Controller } from '@hotwired/stimulus';

/**
 * Secure Drop Controller
 * * Executes local client-side cryptographic encryption using the Web Crypto API,
 * maps encrypted keys into an envelope payload for multiple recipients,
 * and streams secure binaries directly to Symfony.
 */
export default class extends Controller {
    static targets = [
        'form',
        'senderName',
        'senderEmail',
        'fileInput',
        'submitBtn',
        'progressContainer',
        'progressBar',
        'progressPercent'
    ];

    static values = {
        recipients: Array, // Holds an array of objects: { userId, publicKey }
        uploadUrl: String
    };

    /**
     * Intercepts the public submission workflow to perform client-side encryption.
     */
    async processUpload(event) {
        event.preventDefault();

        const file = this.fileInputTarget.files[0];
        const name = this.senderNameTarget.value;
        const email = this.senderEmailTarget.value;

        if (!file || !name || !email) {
            this.updateStatus('Please fill in all fields and select a file to transmit.', 'error');
            return;
        }

        this.lockUI();

        try {
            // --- Step 1: Generate Symmetric Key (K_sym) ---
            this.updateProgress('Generating local symmetric key...', 10);
            const aesKey = await window.crypto.subtle.generateKey(
                { name: 'AES-GCM', length: 256 },
                true,
                ['encrypt', 'decrypt']
            );

            // Export symmetric key to raw bytes so we can encrypt/wrap it for recipients
            const rawAesKey = await window.crypto.subtle.exportKey('raw', aesKey);

            // --- Step 2: Encrypt File Binary Locally ---
            this.updateProgress('Encrypting file contents in-memory...', 30);
            const fileArrayBuffer = await file.arrayBuffer();
            const iv = window.crypto.getRandomValues(new Uint8Array(12)); // 12-byte initialization vector

            const encryptedFileBuffer = await window.crypto.subtle.encrypt(
                { name: 'AES-GCM', iv: iv },
                aesKey,
                fileArrayBuffer
            );

            // --- Step 3: Wrap Symmetric Key for each staff recipient ---
            this.updateProgress('Wrapping secure credentials for staff keys...', 60);
            const wrappedKeys = {};

            for (const recipient of this.recipientsValue) {
                try {
                    // Import standard PEM RSA-OAEP public key
                    const rsaPublicKey = await window.crypto.subtle.importKey(
                        'spki',
                        this.convertPemToBinary(recipient.publicKey),
                        { name: 'RSA-OAEP', hash: 'SHA-256' },
                        false,
                        ['encrypt', 'wrapKey']
                    );

                    // Encrypt K_sym using recipient's Public Key
                    const wrappedKeyBuffer = await window.crypto.subtle.encrypt(
                        { name: 'RSA-OAEP' },
                        rsaPublicKey,
                        rawAesKey
                    );

                    wrappedKeys[recipient.userId] = this.arrayBufferToHex(wrappedKeyBuffer);
                } catch (err) {
                    console.warn(`Could not wrap session keys for user: ${recipient.userId}`, err);
                }
            }

            if (Object.keys(wrappedKeys).length === 0) {
                throw new Error('Key wrapping failed. No valid recipient paths could be configured.');
            }

            // --- Step 4: Construct Secure Multipart Payload and Stream ---
            this.updateProgress('Uploading encrypted payloads...', 80);
            const formData = new FormData();
            formData.append('senderName', name);
            formData.append('senderEmail', email);
            formData.append('iv', this.arrayBufferToHex(iv));
            formData.append('wrappedKeys', JSON.stringify(wrappedKeys));

            // Append raw binary encrypted blob
            const encryptedBlob = new Blob([encryptedFileBuffer], { type: 'application/octet-stream' });
            formData.append('encryptedFile', encryptedBlob, file.name + '.enc');

            await this.uploadPayload(formData);

        } catch (error) {
            console.error('Secure local encryption failed:', error);
            this.updateStatus(`Security handshake failed: ${error.message}`, 'error');
            this.unlockUI();
        }
    }

    /**
     * Executes the asynchronous transport streaming the FormData payload.
     */
    async uploadPayload(formData) {
        try {
            const response = await fetch(this.uploadUrlValue, {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (response.ok && result.success) {
                this.updateProgress('Upload complete!', 100);
                this.updateStatus(result.message, 'success');
                this.formTarget.reset();
            } else {
                throw new Error(result.error || 'Unknown transport error occurred.');
            }
        } catch (err) {
            this.updateStatus(`Delivery failed: ${err.message}`, 'error');
        } finally {
            this.unlockUI();
        }
    }

    // --- Cryptographic Helper Methods ---

    /**
     * Extracts raw binary from an asymmetric PEM public key string block.
     */
    convertPemToBinary(pem) {
        const lines = pem.split('\n');
        let base64 = '';
        for (let line of lines) {
            if (line.includes('PUBLIC KEY') || line.trim() === '') {
                continue;
            }
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

    /**
     * Translates a binary ArrayBuffer block into a clean hex string.
     */
    arrayBufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    // --- UI State Management Methods ---

    lockUI() {
        this.submitBtnTarget.disabled = true;
        this.showElement(this.progressContainerTarget);
    }

    unlockUI() {
        this.submitBtnTarget.disabled = false;
    }

    updateProgress(statusText, percentage) {
        const label = this.element.querySelector('#progress-label');
        if (label) {
            label.textContent = `Status: ${statusText}`;
        }
        this.progressBarTarget.value = percentage;
        this.progressPercentTarget.textContent = `${percentage}%`;
    }

    updateStatus(message, type) {
        const alertBox = this.element.querySelector('#upload-status-alert');
        if (alertBox) {
            alertBox.style.display = 'block';
            alertBox.setAttribute('data-type', type);
            alertBox.textContent = message;
        }
    }

    showElement(element) {
        if (element) {
            element.style.display = 'block';
        }
    }
}
