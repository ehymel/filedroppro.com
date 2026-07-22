import { Controller } from '@hotwired/stimulus';
import Swal from 'sweetalert2';
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
        'reqToken',
        'uploadStatusAlert',
        'uploadedFilesContainer',
        'uploadedFilesList'
    ];

    static values = {
        recipients: Array,
        presignUrl: String,   // Route to generate pre-signed AWS URL
        finalizeUrl: String,  // Route to finalize metadata
        renameUrlTemplate: String,
        deleteUrlTemplate: String
    };

    connect() {
        this.initializeUppy();
        this.cryptoMetadata = {}; // Temporary lookup for encrypt configurations
        this.uploadedFiles = []; // Track files uploaded in current session
    }

    /**
     * Configures Uppy with custom cryptographic pre-processing.
     */
    initializeUppy() {
        this.uppy = new Uppy({
            autoProceed: false,
            restrictions: {
                maxNumberOfFiles: 50,
                maxFileSize: 52428800 // 50MB
            }
        });

        // Mount the Visual Dashboard UI
        this.uppy.use(Dashboard, {
            target: this.uppyContainerTarget,
            inline: true,
            height: 350,
            // width: 600,
            showProgressDetails: true,
            hideUploadButton: false, // We want the main HTML form button to trigger the upload
            // theme: 'dark', // Matches your slate-900 background beautifully
            proudlyDisplayPoweredByUppy: false,
            // showRemoveButtonAfterComplete: true
        });

        // Use Uppy's pre-processor step to intercept and locally encrypt file buffers
        this.uppy.addPreProcessor(async (fileIDs) => {
            let encryptedCount = 0;
            for (const id of fileIDs) {
                const file = this.uppy.getFile(id);
                encryptedCount++;
                this.setStatusLabel(
                    `Encrypting file ${encryptedCount} of ${fileIDs.length} locally in-browser...`
                );

                const encryptedData = await this.encryptFileLocally(file.data);

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

        // Wire Uppy's aggregate progress (across all files) into our UI tracker.
        // Ignored once the batch is finalized, so the reset that uppy.clear() triggers
        // can't stomp the "Upload complete!" state with a stray 0% progress event.
        this.uppy.on('progress', (percentage) => {
            if (!this.uploadActive) {
                return;
            }
            this.updateProgress('Streaming encrypted binaries to cloud...', percentage);
        });

        // Handle successful direct S3 transfer (fires once per file)
        this.uppy.on('upload-success', async (file, response) => {
            this.setStatusLabel('Synchronizing security envelopes...');
            const crypto = this.cryptoMetadata[file.id];

            const payload = {
                senderName: this.senderNameTarget.value,
                senderEmail: this.senderEmailTarget.value,
                iv: crypto.iv,
                wrappedKeys: crypto.wrappedKeys,
                s3Key: file.meta.s3Key,
                originalFileName: crypto.originalFileName,
                fileSize: file.size,
                reqToken: this.hasReqTokenTarget ? this.reqTokenTarget.value : null
            };

            await this.finalizeS3Document(payload);
        });

        this.uppy.on('error', (error) => {
            console.error('Uppy Direct upload collapsed:', error);
            this.uploadActive = false;
            this.updateStatus(`Security transmission failed: ${error.message}`, 'error');
            this.unlockUI();
        });
    }

    /**
     * Executes the browser Web Crypto encryption task.
     */
    async encryptFileLocally(fileBlob) {
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

        // --- Pattern 2: Institutional Escrow Wrapping ---
        // We also wrap the symmetric key with the Tenant's Master Escrow Public Key.
        // This ensures the firm can recover the file if the staff user is unavailable or resets their password.
        const tenantPublicKeyPem = this.element.getAttribute('data-secure-drop-tenant-public-key-value');
        if (tenantPublicKeyPem) {
            try {
                const escrowPublicKey = await window.crypto.subtle.importKey(
                    'spki',
                    this.convertPemToBinary(tenantPublicKeyPem),
                    { name: 'RSA-OAEP', hash: 'SHA-256' },
                    false,
                    ['encrypt', 'wrapKey']
                );

                const wrappedEscrowBuffer = await window.crypto.subtle.encrypt(
                    { name: 'RSA-OAEP' },
                    escrowPublicKey,
                    rawAesKey
                );

                wrappedKeys['tenant_escrow'] = this.arrayBufferToHex(wrappedEscrowBuffer);
            } catch (err) {
                console.error('Institutional Escrow keywrap failed:', err);
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
                this.batchSucceeded++;
                this.addUploadedFile(payload.originalFileName, result.documentId);
            } else {
                throw new Error(result.error || 'Metadata alignment failed.');
            }
        } catch (err) {
            this.batchFailed++;
            console.error(`Finalization failed for ${payload.originalFileName}:`, err);
        } finally {
            this.completeIfBatchDone();
        }
    }

    /**
     * Once every file in the batch has been finalized (success or failure),
     * report the outcome and reset the UI a single time.
     */
    completeIfBatchDone() {
        if (this.batchSucceeded + this.batchFailed < this.batchTotal) {
            return;
        }

        // Batch is done: stop reacting to Uppy progress events so the terminal
        // status below survives the reset triggered by uppy.clear().
        this.uploadActive = false;

        if (this.batchFailed === 0) {
            this.updateProgress('Upload complete!', 100);
            this.updateStatus(
                `Successfully delivered ${this.batchSucceeded} file(s).`,
                'success'
            );
            this.formTarget.reset();
            this.uppy.clear();
        } else if (this.batchSucceeded === 0) {
            this.updateStatus(
                `Delivery failed for all ${this.batchFailed} file(s). Please try again.`,
                'error'
            );
        } else {
            this.updateStatus(
                `Delivered ${this.batchSucceeded} file(s); ${this.batchFailed} failed. Please retry the remaining file(s).`,
                'error'
            );
        }

        this.unlockUI();
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

        // Initialize batch tracking so we only finalize the UI once every file completes
        this.batchTotal = files.length;
        this.batchSucceeded = 0;
        this.batchFailed = 0;
        this.uploadActive = true;

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
        this.setStatusLabel(statusText);
        this.progressBarTarget.value = percentage;
        this.progressPercentTarget.textContent = `${percentage}%`;
    }

    setStatusLabel(statusText) {
        const label = this.element.querySelector('#progress-label');
        if (label) {
            label.textContent = `Status: ${statusText}`;
        }
    }

    updateStatus(message, type) {
        const alertBox = this.uploadStatusAlertTarget;
        if (alertBox) {
            this.showElement(alertBox);
            alertBox.classList.remove('alert-danger');
            alertBox.classList.add(`alert-info`);
            alertBox.setAttribute('data-type', type);
            alertBox.textContent = message;
        }
    }

    showElement(element) {
        if (element) {
            element.classList.remove('d-none');
        }
    }

    addUploadedFile(fileName, documentId) {
        this.uploadedFiles.push({ fileName, documentId });
        this.renderUploadedFiles();
    }

    renderUploadedFiles() {
        if (this.uploadedFiles.length > 0) {
            this.showElement(this.uploadedFilesContainerTarget);
        }

        this.uploadedFilesListTarget.innerHTML = '';
        this.uploadedFiles.forEach(file => {
            const renameUrl = this.renameUrlTemplateValue.replace('DOCUMENT_ID', file.documentId);
            const deleteUrl = this.deleteUrlTemplateValue.replace('DOCUMENT_ID', file.documentId);

            const li = document.createElement('li');
            li.className = 'list-group-item';
            li.innerHTML = `
                <div data-controller="inline-edit"
                     data-inline-edit-url-value="${renameUrl}"
                     data-inline-edit-field-value="originalFileName">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="d-flex align-items-center flex-grow-1">
                            <div data-inline-edit-target="display">
                                <a href="#" data-action="click->inline-edit#toggle" class="ms-1 text-decoration-none">
                                    <span data-inline-edit-target="output">${file.fileName}</span>
                                </a>
                            </div>
                            <div data-inline-edit-target="form" class="d-none flex-grow-1 me-2">
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control" data-inline-edit-target="input" value="${file.fileName}">
                                    <button class="btn btn-success" data-action="click->inline-edit#save">Save</button>
                                    <button class="btn btn-outline-secondary" data-action="click->inline-edit#toggle">Cancel</button>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex align-items-center">
<!--                            <span class="badge bg-success rounded-pill me-2">Uploaded</span>-->
                            <button class="badge bg-danger rounded-pill me-2" style="border: none;"
                                    data-action="click->secure-drop#deleteFile"
                                    data-document-id="${file.documentId}"
                                    data-delete-url="${deleteUrl}">
                                Delete
                            </button>
                        </div>
                    </div>
                </div>
            `;

            this.uploadedFilesListTarget.appendChild(li);
        });
    }

    async deleteFile(event) {
        const btn = event.currentTarget;
        const documentId = btn.dataset.documentId;
        const deleteUrl = btn.dataset.deleteUrl;

        const result = await Swal.fire({
            title: 'Are you sure?',
            text: 'Are you sure you want to delete this file?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete it!',
            showLoaderOnConfirm: true,
            preConfirm: async () => {
                try {
                    const response = await fetch(deleteUrl, {
                        method: 'DELETE',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (!response.ok) {
                        const data = await response.json();
                        throw new Error(data.error || 'Unknown error');
                    }

                    return response;
                } catch (error) {
                    Swal.showValidationMessage(`Delete failed: ${error.message}`);
                }
            },
            allowOutsideClick: () => !Swal.isLoading()
        });

        if (result.isConfirmed) {
            this.uploadedFiles = this.uploadedFiles.filter(f => f.documentId !== documentId);
            this.renderUploadedFiles();
            if (this.uploadedFiles.length === 0) {
                this.uploadedFilesContainerTarget.classList.add('d-none');
            }
        }
    }
}
