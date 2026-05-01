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
                autosave({ orderRef: strOrder });
            }
        });
        disabledSortWhenChangeRef(sortEl);
    }

    // Bootstrap modal initialisation
    let addRefModal = null;
    let importBibModal = null;
    let importS2Modal = null;

    if (document.getElementById('modal-addref')) {
        addRefModal = new Modal(document.getElementById('modal-addref'));
    }
    if (document.getElementById('modal-importbib')) {
        importBibModal = new Modal(document.getElementById('modal-importbib'));
    }
    if (document.getElementById('modal-import-semantic-scholar')) {
        importS2Modal = new Modal(document.getElementById('modal-import-semantic-scholar'));
    }
    if (document.getElementById('closing-info-toast')) {
        new Toast(document.getElementById('closing-info-toast'), { autohide: true, delay: 5000 }).show();
    }

    changeValueFormByToggled();
    changeValueOfReference();
    enableClickToEdit();
    openModalAddBtn(addRefModal);
    acceptAllReference();
    declineAllReference();
    showLoadingScreen();
    topAnchor();
    rextract();
    checkIsDirty();
    manageBibtex(importBibModal);
    manageSemanticScholarImport(importS2Modal);
    removeReference();
});

function changeValueFormByToggled() {
    let toggles = document.querySelectorAll('[id^=toggle-input-]');
    for (let toggle of toggles) {
        toggle.addEventListener('click', () => {
            document.getElementById('accepted-' + toggle.value).value = toggle.checked ? '1' : '0';
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
            document.getElementById('cancelModifyBtn-'+idRef).addEventListener('click', () => sortEl.option('disabled', false));
            document.getElementById('acceptModifyBtn-'+idRef).addEventListener('click', () => sortEl.option('disabled', false));
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
            let modifyReferenceText = document.getElementById('modifyTextArea-'+idRef);
            let modifyReferenceDoi  = document.getElementById('modifyReferenceDoi-'+idRef);
            let editActionBtns      = document.getElementById('editActionBtns-'+idRef);
            let acceptModifyBtn     = document.getElementById('acceptModifyBtn-'+idRef);
            let cancelModifyBtn     = document.getElementById('cancelModifyBtn-'+idRef);
            let containerInfo       = document.getElementById('container-reference-informations-'+idRef);
            let card                = document.querySelector(`[id=container-reference][data-idref="${idRef}"]`);

            document.getElementById('textareaRef-'+idRef).addEventListener('input', () => {
                document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 1;
            });

            modifyReferenceText.classList.remove('d-none');
            modifyReferenceDoi.classList.remove('d-none');
            editActionBtns.classList.remove('d-none');
            modifyReferenceText.classList.add('w-100');
            modifyReferenceDoi.classList.add('w-50');
            btn.classList.add('d-none');
            containerInfo.classList.add('d-none');
            card.classList.add('editing');

            cancelModifyBtn.addEventListener('click', () => {
                modifyReferenceText.classList.remove('w-100');
                modifyReferenceDoi.classList.remove('w-50');
                modifyReferenceText.classList.add('d-none');
                modifyReferenceDoi.classList.add('d-none');
                editActionBtns.classList.add('d-none');
                btn.classList.remove('d-none');
                containerInfo.classList.remove('d-none');
                card.classList.remove('editing');
                document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 0;
            });

            acceptModifyBtn.addEventListener('click', () => {
                containerInfo.classList.remove('d-none');
                modifyReferenceText.classList.remove('w-100');
                modifyReferenceDoi.classList.remove('w-50');
                modifyReferenceText.classList.add('d-none');
                modifyReferenceDoi.classList.add('d-none');
                editActionBtns.classList.add('d-none');
                btn.classList.remove('d-none');
                card.classList.remove('editing');

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
                let referenceValue = {};
                try {
                    referenceValue = JSON.parse(referenceValueForm.value || '{}');
                } catch (error) {
                    referenceValue = {};
                }
                referenceValue.raw_reference = showedText.value;
                if (doiContent !== '') {
                    referenceValue.doi = doiContent;
                } else {
                    delete referenceValue.doi;
                }
                referenceValueForm.value = JSON.stringify(referenceValue);
                const isDirty = document.querySelector(`input[data-dirty-ref="${idRef}"]`).value;
                const accepted = document.getElementById('accepted-'+idRef).value;
                autosave({ refId: idRef, reference: referenceValueForm.value, accepted, isDirty });
            });
        });
    }
}

function enableClickToEdit() {
    document.querySelectorAll('[id^=modifyBtn-]').forEach(btn => {
        let idRef = btn.dataset.idref;
        let containerInfo = document.getElementById('container-reference-informations-' + idRef);
        containerInfo.addEventListener('click', () => {
            const sortref = document.getElementById('sortref');
            if (!sortref || !sortref.classList.contains('delete-mode')) {
                btn.click();
            }
        });
    });
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
        document.getElementById('loading-screen').classList.remove('d-none');
        location.href = '/extract?url='+this.dataset.urlFromEpi+'&rextract';
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
    let deleteBtn    = document.getElementById('select-delete-ref');
    let cancelBtn    = document.getElementById('cancel-delete-ref');
    let toggleAllBtn = document.getElementById('toggle-select-all-ref');
    if (!deleteBtn || !cancelBtn) return;

    const sortref = document.getElementById('sortref');

    deleteBtn.addEventListener('click', () => {
        document.getElementById('alert-remove').classList.remove('d-none');
        if (sortref) sortref.classList.add('delete-mode');
        deleteBtn.classList.add('d-none');
        cancelBtn.classList.remove('d-none');
        if (toggleAllBtn) toggleAllBtn.classList.remove('d-none');
    });

    cancelBtn.addEventListener('click', () => {
        document.getElementById('alert-remove').classList.add('d-none');
        if (sortref) sortref.classList.remove('delete-mode');
        deleteBtn.classList.remove('d-none');
        cancelBtn.classList.add('d-none');
        if (toggleAllBtn) {
            toggleAllBtn.classList.add('d-none');
            resetToggleAllBtn(toggleAllBtn);
        }
        document.querySelectorAll('.ref-delete-check').forEach(node => { node.checked = false; });
    });

    if (toggleAllBtn) {
        toggleAllBtn.addEventListener('click', () => {
            const allSelected = toggleAllBtn.dataset.allSelected !== 'true';
            toggleAllBtn.dataset.allSelected = allSelected ? 'true' : 'false';
            document.querySelectorAll('.ref-delete-check').forEach(node => { node.checked = allSelected; });
            setToggleAllBtnState(toggleAllBtn, allSelected);
        });
    }
}

function setToggleAllBtnState(btn, allSelected) {
    btn.querySelector('i').className = 'fas ' + (allSelected ? 'fa-square' : 'fa-check-double') + ' me-1';
    btn.querySelector('span').textContent = allSelected ? btn.dataset.deselectLabel : btn.dataset.selectLabel;
}

function resetToggleAllBtn(btn) {
    btn.dataset.allSelected = 'false';
    setToggleAllBtnState(btn, false);
}

function autosave(data) {
    const form = document.getElementById('form-extraction');
    const body = new URLSearchParams({ ...data, _token: form.dataset.csrfToken });
    fetch(form.dataset.autosaveUrl, {
        method: 'POST',
        body,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
        .then(r => r.json())
        .then(json => { if (json.success) showAutosaveToast(); })
        .catch(() => {});
}

function showAutosaveToast() {
    const form = document.getElementById('form-extraction');
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }

    const icon = document.createElement('i');
    icon.className = 'fas fa-cloud-arrow-up flex-shrink-0';
    icon.setAttribute('aria-hidden', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-center gap-2';
    body.appendChild(icon);
    body.appendChild(document.createTextNode(form.dataset.autosaveLabel));

    const row = document.createElement('div');
    row.className = 'd-flex';
    row.appendChild(body);

    const progress = document.createElement('div');
    progress.className = 'toast-progress';

    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-success border-0';
    el.appendChild(row);
    el.appendChild(progress);

    container.appendChild(el);
    const toast = new Toast(el, { autohide: true, delay: 2000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

function manageSemanticScholarImport(modal) {
    const triggerBtn = document.getElementById('btn-import-semantic-scholar');
    const importBtn  = document.getElementById('s2-import-btn');
    if (!triggerBtn || !importBtn || !modal) return;

    const modalEl   = document.getElementById('modal-import-semantic-scholar');
    const inputEl   = document.getElementById('s2-paper-id-input');
    const errorDiv  = document.getElementById('s2-error-msg');
    const errorText = document.getElementById('s2-error-text');

    modalEl.addEventListener('show.bs.modal', () => {
        inputEl.value = '';
        errorDiv.classList.add('d-none');
        importBtn.disabled = false;
        const icon = document.createElement('i');
        icon.className = 'fas fa-download me-2';
        icon.setAttribute('aria-hidden', 'true');
        importBtn.textContent = '';
        importBtn.appendChild(icon);
        importBtn.appendChild(document.createTextNode(importBtn.dataset.labelImport));
    });

    triggerBtn.addEventListener('click', () => modal.show());

    importBtn.addEventListener('click', async () => {
        const paperId = inputEl.value.trim();
        errorDiv.classList.add('d-none');

        if (!paperId) {
            errorText.textContent = 'Please enter a paper ID.';
            errorDiv.classList.remove('d-none');
            return;
        }

        importBtn.disabled = true;
        const spinner = document.createElement('span');
        spinner.className = 'spinner-border spinner-border-sm me-2';
        spinner.setAttribute('role', 'status');
        importBtn.textContent = '';
        importBtn.appendChild(spinner);
        importBtn.appendChild(document.createTextNode(importBtn.dataset.labelImporting));

        try {
            const body = new URLSearchParams({ paperId, _token: importBtn.dataset.csrf });
            const res  = await fetch(importBtn.dataset.url, {
                method: 'POST',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const json = await res.json();

            if (json.success) {
                modal.hide();
                showImportToast('success', json.message);
                setTimeout(() => window.location.reload(), 1500);
            } else {
                errorText.textContent = json.error ?? 'Import failed.';
                errorDiv.classList.remove('d-none');
                restoreImportBtn(importBtn);
            }
        } catch {
            errorText.textContent = 'A network error occurred.';
            errorDiv.classList.remove('d-none');
            restoreImportBtn(importBtn);
        }
    });
}

function restoreImportBtn(btn) {
    btn.disabled = false;
    const icon = document.createElement('i');
    icon.className = 'fas fa-download me-2';
    icon.setAttribute('aria-hidden', 'true');
    btn.textContent = '';
    btn.appendChild(icon);
    btn.appendChild(document.createTextNode(btn.dataset.labelImport));
}

function showImportToast(type, message) {
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }

    const bgClass   = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
    const iconClass = type === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-xmark';

    const icon = document.createElement('i');
    icon.className = iconClass + ' flex-shrink-0';
    icon.setAttribute('aria-hidden', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-center gap-2';
    body.appendChild(icon);
    body.appendChild(document.createTextNode(message));

    const closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
    closeBtn.setAttribute('data-bs-dismiss', 'toast');

    const row = document.createElement('div');
    row.className = 'd-flex';
    row.appendChild(body);
    row.appendChild(closeBtn);

    const progress = document.createElement('div');
    progress.className = 'toast-progress';

    const el = document.createElement('div');
    el.className = `toast align-items-center ${bgClass} border-0`;
    el.appendChild(row);
    el.appendChild(progress);

    container.appendChild(el);
    new Toast(el, { autohide: true, delay: 5000 }).show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}
