import { Controller } from '@hotwired/stimulus';
import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import AwsS3 from '@uppy/aws-s3';
import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';

/**
 * Secure Drop Controller with Uppy Direct S3 Integration
 * * Leverages Uppy core to manage direct-to-S3 uploading,
 * * Intercepts file addition with an async pre-processor to perform E2EE locally,
 * * Dispatches metadata to Symfony to complete record insertion on success.
 */
export default class extends Controller {
    static targets = [
        'form',
        'senderName',
        'senderEmail',
        'uppyContainer',
        'fileInput',
        'submitBtn',
        'progressContainer',
        'progressBar',
        'progressPercent',
        'reqToken'
    ];

    static values = {
        recipients: Array,
        presignUrl: String,   // Route to generate pre-signed AWS URL
        finalizeUrl: String   // Route to finalize metadata
    };

    connect() {
        this.initializeUppy();
        this.cryptoMetadata = {}; // Temporary lookup for encrypt configurations
    }

    /**
     * Configures Uppy with custom cryptographic pre-processing.
     */
    initializeUppy() {
        this.uppy = new Uppy({
            autoProceed: false,
            restrictions: {
                maxNumberOfFiles: 1,
                maxFileSize: 52428800 // 50MB
            }
        });

        // Mount the Visual Dashboard UI
        this.uppy.use(Dashboard, {
            target: this.uppyContainerTarget,
            inline: true,
            height: 350,
            showProgressDetails: true,
            hideUploadButton: true, // We want the main HTML form button to trigger the upload
            // theme: 'dark', // Matches your slate-900 background beautifully
            proudlyDisplayPoweredByUppy: true
        });

        // Use Uppy's pre-processor step to intercept and locally encrypt file buffers
        this.uppy.addPreProcessor(async (fileIDs) => {
            for (const id of fileIDs) {
                const file = this.uppy.getFile(id);
                this.updateProgress('Encrypting file locally in-browser...', 30);

                const encryptedData = await this.encryptFileLocally(file.data, file.name);

                // Save crypto artifacts mapped specifically to this Uppy file id
                this.cryptoMetadata[id] = {
                    iv: encryptedData.iv,
                    wrappedKeys: encryptedData.wrappedKeys,
                    originalFileName: file.name
                };

                // Replace the raw file with the encrypted blob payload
                this.uppy.setFileState(id, {
                    data: encryptedData.blob,
                    size: encryptedData.blob.size
                });
            }
        });

        // Use standard Uppy S3 direct uploading
        this.uppy.use(AwsS3, {
            getUploadParameters: async (file) => {
                // Request a dynamic AWS pre-signed PUT URL from Symfony
                const response = await fetch(this.presignUrlValue, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ filename: file.name })
                });

                if (!response.ok) {
                    throw new Error('Could not fetch pre-signed S3 parameters.');
                }

                const data = await response.json();

                // Stash the S3 key on the file object metadata so we can access it on success
                this.uppy.setFileMeta(file.id, { s3Key: data.s3Key });

                return {
                    method: 'PUT',
                    url: data.uploadUrl,
                    headers: { 'Content-Type': 'application/octet-stream' }
                };
            }
        });

        // Wire Uppy's progress state into our UI tracker
        this.uppy.on('upload-progress', (file, progress) => {
            const percentage = Math.round((progress.bytesUploaded / progress.bytesTotal) * 100);
            this.updateProgress('Streaming encrypted binary to cloud...', percentage);
        });

        // Handle successful direct S3 transfer
        this.uppy.on('upload-success', async (file, response) => {
            this.updateProgress('Synchronizing security envelopes...', 90);
            const crypto = this.cryptoMetadata[file.id];

            const payload = {
                senderName: this.senderNameTarget.value,
                senderEmail: this.senderEmailTarget.value,
                iv: crypto.iv,
                wrappedKeys: crypto.wrappedKeys,
                s3Key: file.meta.s3Key,
                originalFileName: crypto.originalFileName,
                reqToken: this.hasReqTokenTarget ? this.reqTokenTarget.value : null
            };

            console.log(payload);

            await this.finalizeS3Document(payload);
        });

        this.uppy.on('error', (error) => {
            console.error('Uppy Direct upload collapsed:', error);
            this.updateStatus(`Security transmission failed: ${error.message}`, 'error');
            this.unlockUI();
        });
    }

    /**
     * Executes the browser Web Crypto encryption task.
     */
    async encryptFileLocally(fileBlob, fileName) {
        const fileArrayBuffer = await fileBlob.arrayBuffer();

        // 1. Generate local symmetric session key (K_sym)
        const aesKey = await window.crypto.subtle.generateKey(
            { name: 'AES-GCM', length: 256 },
            true,
            ['encrypt', 'decrypt']
        );

        const rawAesKey = await window.crypto.subtle.exportKey('raw', aesKey);

        // 2. Encrypt the file buffer
        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        const encryptedFileBuffer = await window.crypto.subtle.encrypt(
            { name: 'AES-GCM', iv: iv },
            aesKey,
            fileArrayBuffer
        );

        // 3. Wrap K_sym for each firm recipient
        const wrappedKeys = {};
        for (const recipient of this.recipientsValue) {
            try {
                const rsaPublicKey = await window.crypto.subtle.importKey(
                    'spki',
                    this.convertPemToBinary(recipient.publicKey),
                    { name: 'RSA-OAEP', hash: 'SHA-256' },
                    false,
                    ['encrypt', 'wrapKey']
                );

                const wrappedKeyBuffer = await window.crypto.subtle.encrypt(
                    { name: 'RSA-OAEP' },
                    rsaPublicKey,
                    rawAesKey
                );

                wrappedKeys[recipient.userId] = this.arrayBufferToHex(wrappedKeyBuffer);
            } catch (err) {
                console.warn(`Keywrap failed for staff user: ${recipient.userId}`, err);
            }
        }

        const encryptedBlob = new Blob([encryptedFileBuffer], { type: 'application/octet-stream' });

        return {
            blob: encryptedBlob,
            iv: this.arrayBufferToHex(iv),
            wrappedKeys: wrappedKeys
        };
    }

    /**
     * Relays cryptographic parameters and S3 paths to Symfony to write the database mappings.
     */
    async finalizeS3Document(payload) {
        try {
            const response = await fetch(this.finalizeUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });

            const result = await response.json();

            if (response.ok && result.success) {
                this.updateProgress('Upload complete!', 100);
                this.updateStatus(result.message, 'success');
                this.formTarget.reset();
                this.uppy.clear();
            } else {
                throw new Error(result.error || 'Metadata alignment failed.');
            }
        } catch (err) {
            console.error(err);
            this.updateStatus(`Delivery failed: ${err.message}`, 'error');
        } finally {
            this.unlockUI();
        }
    }

    /**
     * Triggers the direct S3 upload process on submit.
     */
    processUpload(event) {
        event.preventDefault();

        // Instead of reading a hidden file input, we now check the Uppy instance directly
        const files = this.uppy.getFiles();
        if (files.length === 0) {
            this.updateStatus('Please drag and drop a document into the upload zone.', 'error');
            return;
        }

        const name = this.senderNameTarget.value;
        const email = this.senderEmailTarget.value;
        if (!name || !email) {
            this.updateStatus('Please provide your name and email address.', 'error');
            return;
        }

        this.lockUI();

        // Fire the upload sequence, which will automatically trigger the E2EE pre-processor
        this.uppy.upload();
    }

    // --- Cryptographic Helper Methods ---

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

    arrayBufferToHex(buffer) {
        return Array.from(new Uint8Array(buffer))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    // --- UI Helpers ---

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
