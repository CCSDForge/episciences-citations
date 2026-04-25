import { Sortable } from 'sortablejs/modular/sortable.core.esm';
import { Modal, Toast } from 'bootstrap';

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('sortref')){
        let sortEl = Sortable.create(document.getElementById('sortref'),{
            easing: 'cubic-bezier(0.11, 0, 0.5, 0)',
            animation: 150,
            ghostClass: 'highlighted',
            filter: '.filtered',
            onEnd(){
                let arrayOrder = [];
                for (let el of document.querySelectorAll('[id=container-reference]')){
                    arrayOrder.push(el.dataset.idref);
                }
                let strOrder = arrayOrder.join(';');
                let hiddenRefNode = document.getElementById('document_orderRef');
                hiddenRefNode.value = strOrder;
            }
        });
        disabledSortWhenChangeRef(sortEl);
    }

    // Bootstrap modal initialisation
    let addRefModal = null;
    let importBibModal = null;

    if (document.getElementById('modal-addref')) {
        addRefModal = new Modal(document.getElementById('modal-addref'));
    }
    if (document.getElementById('modal-importbib')) {
        importBibModal = new Modal(document.getElementById('modal-importbib'));
    }
    if (document.getElementById('closing-info-toast')) {
        new Toast(document.getElementById('closing-info-toast'), { autohide: false }).show();
    }

    changeValueFormByToggled();
    changeValueOfReference();
    openModalAddBtn(addRefModal);
    acceptAllReference();
    declineAllReference();
    showLoadingScreen();
    topAnchor();
    rextract();
    checkIsDirty();
    manageBibtex(importBibModal);
    removeReference();
});

function changeValueFormByToggled() {
    let toggles = document.querySelectorAll('[id^=toggle-input-]');
    for (let toggle of toggles) {
        toggle.addEventListener('click', () => {
            let radiosBtns = document.querySelector('#radio-group-choice-'+toggle.value).getElementsByTagName('input');
            for (let radioBtn of radiosBtns){
                radioBtn.checked = Number(radioBtn.value) === Number(toggle.checked);
            }
            let idRef = toggle.value;
            let containerBox = document.querySelector(`[id=container-reference][data-idref="${idRef}"]`);
            classWhenConfirmDecline(containerBox, toggle.checked);
        });
    }
}

function disabledSortWhenChangeRef(sortEl) {
    let btnModifys = document.querySelectorAll('[id^=modifyBtn-]');
    for (let btnModify of btnModifys) {
        btnModify.addEventListener('click', (event) => {
            sortEl.option('disabled', true);
            let idRef = event.currentTarget.dataset.idref;
            let acceptModifyBtn = document.querySelector('#acceptModifyBtn-'+idRef);
            let cancelModifyBtn = document.querySelector('#cancelModifyBtn-'+idRef);
            cancelModifyBtn.addEventListener('click', () => sortEl.option('disabled', false));
            acceptModifyBtn.addEventListener('click', () => sortEl.option('disabled', false));
        });
    }
}

function changeValueOfReference() {
    let btnModifys = document.querySelectorAll('[id^=modifyBtn-]');
    for (let btnModify of btnModifys) {
        btnModify.addEventListener('click', (event) => {
            // Capture currentTarget immediately — it becomes null after dispatch
            const btn = event.currentTarget;
            let idRef = btn.dataset.idref;
            let modifyReferenceText = document.querySelector('#modifyTextArea-'+idRef);
            let modifyReferenceDoi  = document.querySelector('#modifyReferenceDoi-'+idRef);
            let acceptModifyBtn     = document.querySelector('#acceptModifyBtn-'+idRef);
            let cancelModifyBtn     = document.querySelector('#cancelModifyBtn-'+idRef);
            let containerInfo       = document.querySelector('#container-reference-informations-'+idRef);

            document.querySelector('#textareaRef-'+idRef).addEventListener('input', () => {
                document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 1;
            });

            modifyReferenceText.classList.remove('d-none');
            modifyReferenceDoi.classList.remove('d-none');
            acceptModifyBtn.classList.remove('d-none');
            cancelModifyBtn.classList.remove('d-none');
            modifyReferenceText.classList.add('w-100');
            modifyReferenceDoi.classList.add('w-50');
            btn.classList.add('d-none');
            containerInfo.classList.add('d-none');

            cancelModifyBtn.addEventListener('click', () => {
                modifyReferenceText.classList.remove('w-100');
                modifyReferenceDoi.classList.remove('w-50');
                modifyReferenceText.classList.add('d-none');
                modifyReferenceDoi.classList.add('d-none');
                acceptModifyBtn.classList.add('d-none');
                cancelModifyBtn.classList.add('d-none');
                btn.classList.remove('d-none');
                containerInfo.classList.remove('d-none');
                document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 0;
            });

            acceptModifyBtn.addEventListener('click', () => {
                containerInfo.classList.remove('d-none');
                modifyReferenceText.classList.remove('w-100');
                modifyReferenceDoi.classList.remove('w-50');
                modifyReferenceText.classList.add('d-none');
                modifyReferenceDoi.classList.add('d-none');
                acceptModifyBtn.classList.add('d-none');
                cancelModifyBtn.classList.add('d-none');
                btn.classList.remove('d-none');

                let referenceWished    = document.getElementById('textareaRef-'+idRef);
                let showedText         = document.getElementById('textReference-'+idRef);
                showedText.textContent = referenceWished.value;
                showedText.value       = referenceWished.value;

                let referenceDoiWished = document.getElementById('textDoiRef-'+idRef);
                let linkDoiTag         = document.getElementById('linkDoiRef-'+idRef);
                let doiContent         = '';

                if (linkDoiTag === null && referenceDoiWished.value !== '') {
                    let newNode = document.createElement('a');
                    newNode.id        = 'linkDoiRef-'+idRef;
                    newNode.className = 'link-primary text-decoration-underline';
                    showedText.after(newNode);
                    linkDoiTag = document.getElementById('linkDoiRef-'+idRef);
                }

                if (referenceDoiWished.value !== '') {
                    const sanitizedDoi = referenceDoiWished.value.trim();
                    if (/^(javascript:|data:|vbscript:)/i.test(sanitizedDoi)) {
                        // eslint-disable-next-line no-console
                        console.error('Invalid DOI value detected');
                        return;
                    }
                    linkDoiTag.href        = 'https://doi.org/' + encodeURIComponent(sanitizedDoi);
                    linkDoiTag.textContent = sanitizedDoi;
                    doiContent             = sanitizedDoi;
                } else if (referenceDoiWished.value === '' && linkDoiTag !== null) {
                    linkDoiTag.remove();
                }

                acceptRefModificationsDone(idRef);
                let referenceValueForm   = document.getElementById('reference-'+idRef);
                referenceValueForm.value = JSON.stringify({'raw_reference': showedText.value, 'doi': doiContent});
            });
        });
    }
}

function acceptRefModificationsDone(idRef) {
    let toggle = document.querySelector('#toggle-input-'+idRef);
    if (!toggle.checked) toggle.click();
    let containerBox = document.querySelector(`[id=container-reference][data-idref="${idRef}"]`);
    classWhenConfirmDecline(containerBox, true);
}

function openModalAddBtn(addRefModal) {
    if (!addRefModal || !document.getElementById('btn-modal-addref')) return;
    document.getElementById('btn-modal-addref').addEventListener('click', () => addRefModal.show());
    document.getElementById('confirm-adding').addEventListener('click', () => {
        addRefModal.hide();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

function acceptAllReference() {
    document.querySelector('#accept-all').addEventListener('click', (event) => {
        event.preventDefault();
        document.querySelectorAll('[id^=toggle-input-]').forEach(toggle => {
            if (!toggle.checked) toggle.click();
        });
        document.querySelectorAll('.declinedRef').forEach(el => classWhenConfirmDecline(el, true));
    });
}

function declineAllReference() {
    document.querySelector('#decline-all').addEventListener('click', (event) => {
        event.preventDefault();
        document.querySelectorAll('[id^=toggle-input-]').forEach(toggle => {
            if (toggle.checked) toggle.click();
        });
        document.querySelectorAll('[id=container-reference]').forEach(el => classWhenConfirmDecline(el, false));
    });
}

function showLoadingScreen() {
    document.getElementById('form-extraction').addEventListener('submit', () => {
        document.getElementById('loading-screen').classList.remove('d-none');
    });
}

function classWhenConfirmDecline(el, confirm = true) {
    if (confirm) {
        el.classList.remove('declinedRef', 'filtered');
    } else {
        el.classList.add('declinedRef', 'filtered');
    }
}

function topAnchor() {
    document.getElementById('document_save').addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
    const confirmAdding = document.getElementById('confirm-adding');
    if (confirmAdding) {
        confirmAdding.addEventListener('click', () => window.scrollTo({ top: 0, behavior: 'smooth' }));
    }
}

function rextract() {
    document.getElementById('extract-all').onclick = function(event) {
        event.preventDefault();
        location.href = '/extract?url='+this.dataset.urlFromEpi+'&rextract';
        document.getElementById('loading-screen').classList.remove('d-none');
    };
}

function checkIsDirty() {
    let isDirty = document.getElementById('is-dirty');
    let formSubmitting = false;
    document.getElementById('form-extraction').addEventListener('change', () => { isDirty.value = '1'; });
    document.getElementById('form-extraction').addEventListener('submit', () => { formSubmitting = true; });
    window.addEventListener('beforeunload', function(event) {
        if (isDirty.value === '1' && formSubmitting === false) {
            event.returnValue = null;
        }
    });
}

function manageBibtex(importBibModal) {
    if (!importBibModal || !document.querySelector('#btn-modal-importbibtex')) return;
    document.querySelector('#btn-modal-importbibtex').addEventListener('click', () => importBibModal.show());
}

function removeReference() {
    let deleteBtn = document.getElementById('select-delete-ref');
    let cancelBtn = document.getElementById('cancel-delete-ref');
    if (!deleteBtn || !cancelBtn) return;

    deleteBtn.addEventListener('click', (event) => {
        event.preventDefault();
        document.getElementById('alert-remove').classList.remove('d-none');
        document.querySelectorAll('[id=selection-references]').forEach(node => node.classList.add('d-none'));
        document.querySelectorAll('[id^=ref-to-delete-]').forEach(node => node.classList.remove('d-none'));
        deleteBtn.classList.add('d-none');
        cancelBtn.classList.remove('d-none');
    });

    cancelBtn.addEventListener('click', (event) => {
        event.preventDefault();
        document.getElementById('alert-remove').classList.add('d-none');
        document.querySelectorAll('[id^=ref-to-delete-]').forEach(node => { node.value = '0'; });
        deleteBtn.classList.remove('d-none');
        cancelBtn.classList.add('d-none');
        document.querySelectorAll('[id=selection-references]').forEach(node => node.classList.remove('d-none'));
        document.querySelectorAll('[id^=ref-to-delete-]').forEach(node => {
            node.classList.add('d-none');
            node.checked = false;
        });
    });
}
