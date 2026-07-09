// assets/controllers/file_upload_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ["input", "preview"];

    connect() {
        // Add visual listener states for drag-and-drop actions
        ['dragenter', 'dragover'].forEach(eventName => {
            this.element.addEventListener(eventName, (e) => this.highlight(e), false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            this.element.addEventListener(eventName, (e) => this.unhighlight(e), false);
        });

        this.element.addEventListener('drop', (e) => this.handleDrop(e), false);
    }

    highlight(e) {
        e.preventDefault();
        this.element.classList.add('border-primary', 'bg-body');
    }

    unhighlight(e) {
        e.preventDefault();
        this.element.classList.remove('border-primary', 'bg-body');
    }

    onFileSelect(event) {
        const files = event.target.files;
        this.processFiles(files);
    }

    handleDrop(e) {
        e.preventDefault();
        const files = e.dataTransfer.files;
        if (this.hasInputTarget) {
            this.inputTarget.files = files;
        }
        this.processFiles(files);
    }

    processFiles(files) {
        // Process file validation/uploads or hand off to a dropzone bundle
        console.log(`Processing ${files.length} file(s)...`);
    }
}
