import { Controller } from '@hotwired/stimulus';
import { Dropzone } from 'dropzone';
import { Sortable } from 'sortablejs';
import 'dropzone/dist/dropzone.css';
import $ from 'jquery';

export default class extends Controller {
    static values = {
        indexUrl: String,
        petUuid: String
    }
    dropzone;
    connect() {
        var documentList = new this.DocumentList(document.querySelector('.js-documents-list'), this.indexUrlValue, this.petUuidValue);
        this.initializeDropzone(documentList);
    }

    /**
     * @param {DocumentList} documentList
     */
    initializeDropzone(documentList) {
        var formElement = document.querySelector('form.dropzone');

        if (!formElement) {
            return;
        }

        this.dropzone = new Dropzone(formElement, {
            paramName: 'document',
            init: function() {
                this.on('success', function (file, data) {
                    documentList.addDocument(data);
                });
                this.on('error', function(file, data) {
                    if (data.detail) {
                        this.emit('error', file, data.detail);
                    }
                });
            }
        });
    }

    add(event) {
        event.preventDefault();
        event.currentTarget.classList.add('d-none');
        const dropzoneForm = event.currentTarget.nextElementSibling;
        dropzoneForm.classList.remove('d-none');
    }

    DocumentList = class DocumentList
        {
            constructor(el, indexUrl, petUuid) {
                this.el = el;
                this.indexUrl = indexUrl;
                this.petUuid = petUuid;
                this.sortable = Sortable.create(el, {
                    handle: '.drag-handle',
                    animation: 150,
                    onEnd: () => {
                        $.ajax({
                            url: this.indexUrl+'reorder/'+this.petUuid,
                            method: 'POST',
                            data: JSON.stringify(this.sortable.toArray())
                        })
                    }
                });
                this.documents = [];
                this.render();

                document.querySelector('.js-documents').addEventListener('click', (event) => {
                    if (event.target.parentNode.classList.contains('js-document-delete')) {
                        // this.handleDocumentDelete(event);
                    } else if (event.target.parentNode.classList.contains('js-edit-filename-btn')) {
                        this.showEditFilenameInput(event);
                    } else if (event.target.parentNode.classList.contains('js-edit-description-btn')) {
                        this.showEditDescriptionInput(event);
                    }
                });
                document.querySelector('.js-documents').addEventListener('blur', (event) => {
                    if (event.target.classList.contains('js-edit-filename')) {
                        this.handleDocumentEditFilename(event);
                    } else if (event.target.classList.contains('js-edit-description')) {
                        this.handleDocumentEditDescription(event);
                    }
                }, true);

                $.ajax({
                    url: this.el.dataset.url
                }).then(data => {
                    this.documents = data;
                    this.render();
                });
            }

            addDocument(doc) {
                this.documents.push(doc);
                this.render();
            }

            // handleDocumentDelete(event) {
            //     const li = event.target.closest('.list-item');
            //     const uuid = li.dataset.uuid;
            //     li.classList.add('disabled');
            //
            //     $.ajax({
            //         url: this.indexUrl+uuid,
            //         method: 'DELETE'
            //     }).then(() => {
            //         this.documents = this.documents.filter(doc => {
            //             return doc.uuid !== uuid
            //         });
            //         this.render();
            //     });
            // }
            //
            handleDocumentEditFilename(event) {
                const li = event.target.closest('.list-item');
                const uuid = li.dataset.uuid;
                const doc = this.documents.find(doc => doc.uuid === uuid);
                doc.originalFilename = event.target.value;

                $.ajax({
                    url: this.indexUrl+'update/'+uuid,
                    method: 'PUT',
                    data: JSON.stringify(doc)
                }).then(data => {
                    this.documents = data;
                    this.render();
                });
            }

            handleDocumentEditDescription(event) {
                const li = event.target.closest('.list-item');
                const uuid = li.dataset.uuid;
                const doc = this.documents.find(doc => doc.uuid === uuid);
                doc.description = event.target.value;

                $.ajax({
                    url: this.indexUrl+'update/'+uuid,
                    method: 'PUT',
                    data: JSON.stringify(doc)
                }).then(data => {
                    this.documents = data;
                    this.render();
                });
            }

            showEditFilenameInput(event) {
                const btn = event.target.parentNode;
                // const span = this.getNextSibling(btn, 'span.js-filename');
                const input = this.getNextSibling(btn, 'input.js-edit-filename');
                btn.classList.add('d-none');
                // span.classList.add('d-none');
                input.classList.remove('d-none');
                input.focus();
            }

            showEditDescriptionInput(event) {
                const btn = event.target.parentNode;
                const span = this.getNextSibling(btn, 'span.js-description');
                const input = this.getNextSibling(btn, 'textarea.js-edit-description');
                btn.classList.add('d-none');
                span.classList.add('d-none');
                input.classList.remove('d-none');
                input.focus();
            }

            render() {
                this.el.innerHTML = '';
                const itemsHtml = this.documents.map(doc => {
                    let htmlString = `
<div class="list-item d-flex justify-content-between border-1" data-id="${doc.id}" data-uuid="${doc.uuid}">
    <div class="col-1 text-nowrap overflow-hidden me-1">
        <span class="drag-handle fa fa-arrows mx-1" style="vertical-align: middle"></span>
        <br />
        <a href="/documents/view/${doc.uuid}" target="_blank"><img src="/documents/view/${doc.uuid}" style="height:50px" alt=""></a>
    </div>
    <div class="col-7 text-nowrap overflow-hidden me-1">
        <button class="js-edit-filename-btn btn btn-link btn-sm"><span class="fa fa-edit" style="vertical-align: middle"></span></button>
        <span class="js-filename">${doc.originalFilename}</span>
        <input type="text" value="${doc.originalFilename}" class="form-control js-edit-filename d-none" style="width: auto;">
        <br />
        <button class="js-edit-description-btn btn btn-link btn-sm"><span class="fa fa-edit" style="vertical-align: middle"></span></button>
        <span class="js-description">${doc.description ?? ''}</span>
        <textarea class="form-control js-edit-description d-none">${doc.description ?? ''}</textarea>
    </div>
    
    <div class="col d-flex justify-content-end">
        <span>
            <a href="${this.indexUrl}download/${doc.uuid}" class="btn btn-link btn-sm"><span class="fa fa-download" style="vertical-align: middle"></span></a>
            <a class="js-document-delete btn btn-link btn-sm" 
                data-action="sweetalert-delete#remove" 
                data-url="/documents/remove/${doc.uuid}"
            ><span class="fa fa-trash"></span></a>
        </span>
    </div>
</div>
`
                    return this.createElementFromHTML(htmlString);
                });

                for (let i=0; i<itemsHtml.length; i++) {
                    this.el.appendChild(itemsHtml[i]);
                }
            }

            createElementFromHTML(htmlString) {
                var div = document.createElement('div');
                div.innerHTML = htmlString.trim();

                return div.firstChild;
            }

            getNextSibling = function (elem, selector) {
                var sibling = elem.nextElementSibling;

                if (!selector) return sibling;

                while (sibling) {
                    if (sibling.matches(selector)) return sibling;
                    sibling = sibling.nextElementSibling
                }
            };
        }
}
