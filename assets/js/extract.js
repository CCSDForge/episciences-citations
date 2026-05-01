import { Sortable } from 'sortablejs/modular/sortable.core.esm';
import { Modal, Toast } from 'bootstrap';

console.log('DOI Enrichment: extract.js loaded');

const extractDoi = (input) => {
    if (!input) return null;
    const doiRegex = /(10\.\d{4,}(?:\.\d+)*\/(?:(?!["&\'\s])\S)+)/;
    const match = input.match(doiRegex);
    return match ? match[1] : null;
};

const setContent = (element, content) => {
    if (!element) return;
    element.value = content;
};

document.addEventListener('DOMContentLoaded', () => {
    console.log('DOI Enrichment: DOMContentLoaded triggered');
    let sortEl = null;
    if (document.getElementById('sortref')){
        let initialOrder = '';
        sortEl = Sortable.create(document.getElementById('sortref'),{
            easing: 'cubic-bezier(0.11, 0, 0.5, 0)',
            animation: 150,
            ghostClass: 'highlighted',
            filter: '.filtered',
            touchStartThreshold: 5,
            onStart(){
                initialOrder = Array.from(document.querySelectorAll('.container-reference'))
                    .map(el => el.dataset.idref).join(';');
            },
            onEnd(){
                let arrayOrder = [];
                for (let el of document.querySelectorAll('.container-reference')){
                    arrayOrder.push(el.dataset.idref);
                }
                let strOrder = arrayOrder.join(';');

                // Only save if order actually changed
                if (strOrder !== initialOrder) {
                    let hiddenRefNode = document.getElementById('document_orderRef');
                    if (hiddenRefNode) hiddenRefNode.value = strOrder;
                    autosave({ orderRef: strOrder });
                }
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
    console.log('DOI Enrichment: Calling manageDoiEnrichment()');
    manageDoiEnrichment();
    removeReference();
});

function manageDoiEnrichment() {
    const form = document.getElementById('form-extraction');
    if (!form) {
        console.warn('DOI Enrichment: form-extraction not found');
        return;
    }

    const addRefDoiInput = document.getElementById('document_addReferenceDoi');
    const addRefTextArea = document.getElementById('document_addReference');
    const referenceLoadingOverlay = document.getElementById('reference-loading-overlay');
    const confirmAddingBtn = document.getElementById('confirm-adding');
    const loadingIndicator = document.getElementById('doi-loading-indicator');
    const errorMsg = document.getElementById('doi-error-msg');

    if (addRefDoiInput) {
        const handleDoiChange = async () => {
            const val = addRefDoiInput.value.trim();
            if (!val) return;

            // Check if reference textarea is empty before fetching
            let currentContent = addRefTextArea ? addRefTextArea.value.trim() : '';

            if (currentContent !== '') {
                console.log('DOI Enrichment: Reference field not empty, skipping auto-fetch.');
                return;
            }

            const doi = extractDoi(val);
            if (!doi) {
                console.log('DOI Enrichment: No valid DOI found in string:', val);
                return;
            }

            console.log('DOI Enrichment: Fetching metadata for:', doi);
            loadingIndicator?.classList.remove('d-none');
            referenceLoadingOverlay?.classList.remove('d-none');
            errorMsg?.classList.add('d-none');
            if (confirmAddingBtn) confirmAddingBtn.disabled = true;

            try {
                const response = await fetch(`/doi/enrich?doi=${encodeURIComponent(doi)}`);

                if (response.status === 404) {
                    if (errorMsg) {
                        errorMsg.textContent = 'The DOI was not found';
                        errorMsg.classList.remove('d-none');
                    }
                    return;
                }

                const data = await response.json();

                if (data.success) {
                    console.log('DOI Enrichment: Success, citation received.');
                    setContent(addRefTextArea, data.citation);
                } else {
                    console.warn('DOI Enrichment: Server returned failure:', data.error);
                    if (errorMsg) {
                        errorMsg.textContent = data.error || 'Failed to fetch DOI metadata';
                        errorMsg.classList.remove('d-none');
                    }
                }
            } catch (error) {
            console.error('DOI Enrichment: Fetch or parsing error:', error);
            if (errorMsg) {
                errorMsg.textContent = 'Could not reach the server or invalid response';
                errorMsg.classList.remove('d-none');
            }
        } finally {
                loadingIndicator?.classList.add('d-none');
                referenceLoadingOverlay?.classList.add('d-none');
                if (confirmAddingBtn) confirmAddingBtn.disabled = false;
            }
        };

        addRefDoiInput.addEventListener('blur', handleDoiChange);
        addRefDoiInput.addEventListener('change', handleDoiChange);
    }

    // Delegate enrichment events
    document.addEventListener('click', async (e) => {
        const enrichBtn = e.target.closest('.enrich-doi-btn');
        if (enrichBtn) {
            await handleEnrichment(enrichBtn);
        }
    });

}

async function handleEnrichment(btn) {
    const idRef = btn.dataset.idref;
    const doi = btn.dataset.doi;
    const textRef = document.getElementById('textReference-' + idRef);
    const textareaRef = document.getElementById('textareaRef-' + idRef);
    const spinner = btn.querySelector('.spinner-border');
    const icon = btn.querySelector('i');
    const enrichText = btn.querySelector('.enrich-text');

    console.log('DOI Enrichment: Enriching existing reference:', idRef, doi);
    btn.disabled = true;
    spinner?.classList.remove('d-none');
    icon?.classList.add('d-none');
    if (enrichText) enrichText.classList.add('d-none');

    try {
        // Use relative path to avoid protocol/host mismatch
        const response = await fetch(`/doi/enrich?doi=${encodeURIComponent(doi)}`);

        if (response.status === 404) {
            showImportToast('danger', 'The DOI was not found');
            return;
        }

        // For other errors or success, parse JSON
        const data = await response.json();

        if (data.success) {
            console.log('DOI Enrichment: Enrichment success for ref', idRef);
            if (textRef) textRef.textContent = data.citation;
            setContent(textareaRef, data.citation);

            const referenceValueForm = document.getElementById('reference-' + idRef);
            if (referenceValueForm) {
                let referenceValue = {};
                try {
                    referenceValue = JSON.parse(referenceValueForm.value || '{}');
                } catch (err) {
                    console.error('DOI Enrichment: JSON parse error for reference', idRef, err);
                    referenceValue = {};
                }
                referenceValue.raw_reference = data.citation;
                if (data.csl) {
                    referenceValue.csl = data.csl;
                }
                referenceValueForm.value = JSON.stringify(referenceValue);

                const dirtyInput = document.querySelector(`input[data-dirty-ref="${idRef}"]`);
                if (dirtyInput) dirtyInput.value = 1;

                autosaveReference(idRef, '1');
            }

            showImportToast('success', 'Reference enriched successfully');
        } else {
            console.warn('DOI Enrichment: Enrichment failed for ref', idRef, data.error);
            showImportToast('danger', data.error || 'Enrichment failed');
        }
    } catch (error) {
        console.error('DOI Enrichment: Fetch or parsing error for ref', idRef, error);
        showImportToast('danger', 'A network error occurred or the server returned an invalid response');
    } finally {
        console.log('DOI Enrichment: Task finished for ref', idRef);
        btn.disabled = false;
        spinner?.classList.add('d-none');
        icon?.classList.remove('d-none');
        if (enrichText) enrichText.classList.remove('d-none');
    }
}

function changeValueFormByToggled() {
    let toggles = document.querySelectorAll('[id^=toggle-input-]');
    for (let toggle of toggles) {
        toggle.addEventListener('click', () => {
            const idRef = toggle.value;
            const acceptedInput = document.getElementById('accepted-' + idRef);
            if (acceptedInput) {
                acceptedInput.value = toggle.checked ? '1' : '0';
                autosaveReference(idRef);
            }
            const containerBox = document.querySelector(`.container-reference[data-idref="${idRef}"]`);
            if (containerBox) {
                classWhenConfirmDecline(containerBox, toggle.checked);
            }
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
    // 1. Initial listener for Edit buttons (the pen icon)
    const btnModifys = document.querySelectorAll('[id^=modifyBtn-]');
    btnModifys.forEach(btn => {
        btn.addEventListener('click', (event) => {
            const idRef = event.currentTarget.dataset.idref;
            enterEditMode(idRef);
        });
    });

    // 2. Initial listener for Cancel buttons
    const cancelBtns = document.querySelectorAll('[id^=cancelModifyBtn-]');
    cancelBtns.forEach(btn => {
        btn.addEventListener('click', (event) => {
            const idRef = event.currentTarget.id.replace('cancelModifyBtn-', '');
            exitEditMode(idRef);
            document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 0;
        });
    });

    // 3. Initial listener for Confirm (Accept) buttons
    const acceptBtns = document.querySelectorAll('[id^=acceptModifyBtn-]');
    acceptBtns.forEach(btn => {
        btn.addEventListener('click', (event) => {
            const idRef = event.currentTarget.id.replace('acceptModifyBtn-', '');
            confirmEdit(idRef);
        });
    });

    // 4. Initial listener for Textarea input
    const textareas = document.querySelectorAll('[id^=textareaRef-]');
    textareas.forEach(area => {
        area.addEventListener('input', (event) => {
            const idRef = event.currentTarget.id.replace('textareaRef-', '');
            document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 1;
        });
    });
}

function enterEditMode(idRef) {
    const btnModify = document.getElementById('modifyBtn-' + idRef);
    const modifyReferenceText = document.getElementById('modifyTextArea-' + idRef);
    const modifyReferenceDoi = document.getElementById('modifyReferenceDoi-' + idRef);
    const editActionBtns = document.getElementById('editActionBtns-' + idRef);
    const containerInfo = document.getElementById('container-reference-informations-' + idRef);
    const card = document.querySelector(`.container-reference[data-idref="${idRef}"]`);

    modifyReferenceText.classList.remove('d-none');
    modifyReferenceDoi.classList.remove('d-none');
    editActionBtns.classList.remove('d-none');
    modifyReferenceText.classList.add('w-100');
    modifyReferenceDoi.classList.add('w-50');
    btnModify.classList.add('d-none');
    containerInfo.classList.add('d-none');
    card.classList.add('editing');
}

function exitEditMode(idRef) {
    const btnModify = document.getElementById('modifyBtn-' + idRef);
    const modifyReferenceText = document.getElementById('modifyTextArea-' + idRef);
    const modifyReferenceDoi = document.getElementById('modifyReferenceDoi-' + idRef);
    const editActionBtns = document.getElementById('editActionBtns-' + idRef);
    const containerInfo = document.getElementById('container-reference-informations-' + idRef);
    const card = document.querySelector(`.container-reference[data-idref="${idRef}"]`);

    modifyReferenceText.classList.remove('w-100');
    modifyReferenceDoi.classList.remove('w-50');
    modifyReferenceText.classList.add('d-none');
    modifyReferenceDoi.classList.add('d-none');
    editActionBtns.classList.add('d-none');
    btnModify.classList.remove('d-none');
    containerInfo.classList.remove('d-none');
    card.classList.remove('editing');
}

function confirmEdit(idRef) {
    const btnModify = document.getElementById('modifyBtn-' + idRef);
    const modifyReferenceText = document.getElementById('modifyTextArea-' + idRef);
    const modifyReferenceDoi = document.getElementById('modifyReferenceDoi-' + idRef);
    const editActionBtns = document.getElementById('editActionBtns-' + idRef);
    const containerInfo = document.getElementById('container-reference-informations-' + idRef);
    const card = document.querySelector(`.container-reference[data-idref="${idRef}"]`);

    containerInfo.classList.remove('d-none');
    modifyReferenceText.classList.remove('w-100');
    modifyReferenceDoi.classList.remove('w-50');
    modifyReferenceText.classList.add('d-none');
    modifyReferenceDoi.classList.add('d-none');
    editActionBtns.classList.add('d-none');
    btnModify.classList.remove('d-none');
    card.classList.remove('editing');

    let referenceWished = document.getElementById('textareaRef-' + idRef);
    let showedText = document.getElementById('textReference-' + idRef);

    showedText.textContent = referenceWished.value;
    showedText.value = referenceWished.value;

    let referenceDoiWished = document.getElementById('textDoiRef-' + idRef);
    let linkDoiTag = document.getElementById('linkDoiRef-' + idRef);
    let doiContent = '';

    if (linkDoiTag === null && referenceDoiWished.value !== '') {
        let newNode = document.createElement('a');
        newNode.id = 'linkDoiRef-' + idRef;
        newNode.className = 'link-primary text-decoration-underline';
        showedText.after(newNode);
        linkDoiTag = document.getElementById('linkDoiRef-' + idRef);
    }

    if (referenceDoiWished.value !== '') {
        const sanitizedDoi = referenceDoiWished.value.trim();
        if (/^(javascript:|data:|vbscript:)/i.test(sanitizedDoi)) {
            // eslint-disable-next-line no-console
            console.error('Invalid DOI value detected');
            return;
        }
        linkDoiTag.href = 'https://doi.org/' + encodeURIComponent(sanitizedDoi);
        linkDoiTag.textContent = sanitizedDoi;
        doiContent = sanitizedDoi;
    } else if (referenceDoiWished.value === '' && linkDoiTag !== null) {
        linkDoiTag.remove();
    }

    acceptRefModificationsDone(idRef);
    let referenceValueForm = document.getElementById('reference-' + idRef);
    let referenceValue = {};
    try {
        referenceValue = JSON.parse(referenceValueForm.value || '{}');
    } catch (error) {
        referenceValue = {};
    }
    referenceValue.raw_reference = showedText.value;
    // If user manually edited, we remove CSL data to prevent automatic overwrite on next reload
    if (document.querySelector(`input[data-dirty-ref="${idRef}"]`).value === '1') {
        delete referenceValue.csl;
    }
    if (doiContent !== '') {
        referenceValue.doi = doiContent;
    } else {
        delete referenceValue.doi;
    }
    referenceValueForm.value = JSON.stringify(referenceValue);
    autosaveReference(idRef, '1');
}

function enableClickToEdit() {
    let references = document.querySelectorAll('[id^=container-reference-informations-]');
    for (let reference of references) {
        reference.addEventListener('dblclick', (event) => {
            const idRef = event.currentTarget.id.replace('container-reference-informations-', '');
            const modifyBtn = document.getElementById('modifyBtn-' + idRef);
            if (modifyBtn && !modifyBtn.classList.contains('d-none')) {
                modifyBtn.click();
            }
        });
    }
}

function openModalAddBtn(addRefModal) {
    if (!addRefModal) return;
    let addBtn = document.getElementById('btn-modal-addref');
    if (addBtn){
        addBtn.addEventListener('click', () => {
            addRefModal.show();
        });
    }
}

function acceptAllReference() {
    const btn = document.getElementById('accept-all');
    if (!btn) return;
    btn.addEventListener('click', () => {
        document.querySelectorAll('[id^=toggle-input-]').forEach(toggle => {
            if (!toggle.checked) {
                toggle.checked = true;
                const acceptedInput = document.getElementById('accepted-' + toggle.value);
                if (acceptedInput) acceptedInput.value = '1';
                const container = document.querySelector(`.container-reference[data-idref="${toggle.value}"]`);
                if (container) classWhenConfirmDecline(container, true);
                autosaveReference(toggle.value);
            }
        });
    });
}

function declineAllReference() {
    const btn = document.getElementById('decline-all');
    if (!btn) return;
    btn.addEventListener('click', () => {
        document.querySelectorAll('[id^=toggle-input-]').forEach(toggle => {
            if (toggle.checked) {
                toggle.checked = false;
                const acceptedInput = document.getElementById('accepted-' + toggle.value);
                if (acceptedInput) acceptedInput.value = '0';
                const container = document.querySelector(`.container-reference[data-idref="${toggle.value}"]`);
                if (container) classWhenConfirmDecline(container, false);
                autosaveReference(toggle.value);
            }
        });
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
        el.classList.add('acceptedRef');
    } else {
        el.classList.remove('acceptedRef');
        el.classList.add('declinedRef', 'filtered');
    }

    // Red border ALWAYS takes precedence if detectors are present
    if (el.dataset.hasDetectors === '1') {
        el.classList.add('border-danger-important');
    } else {
        el.classList.remove('border-danger-important');
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
        .then(async r => {
            const json = await r.json();
            if (r.ok && json.success) {
                showAutosaveToast();
                if (json.reference && data.refId) {
                    updateReferenceUI(data.refId, json.reference);
                }
            } else {
                console.error('Autosave failed:', json.error || r.statusText);
                showAutosaveToast(true, json.error || 'Failed to save changes');
            }
        })
        .catch(err => {
            console.error('Autosave network error:', err);
            showAutosaveToast(true, 'Network error while saving');
        });
}

function updateReferenceUI(idRef, referenceData) {
    const container = document.querySelector(`.container-reference[data-idref="${idRef}"]`);
    if (!container) return;

    // Update badges container
    const badgeContainer = container.querySelector('.ms-auto.d-flex.align-items-center.gap-1.flex-wrap');
    if (badgeContainer) {
        // Keep Annulled badge and PubPeer/Source badges, but update detectors
        const detectors = referenceData.detectors || [];
        const detectorList = Array.isArray(detectors) ? detectors : [detectors];

        // Remove old detector badges (they have bg-danger and ⚠️)
        badgeContainer.querySelectorAll('.badge.bg-danger').forEach(badge => badge.remove());

        // Add new detector badges
        detectorList.forEach(detector => {
            if (detector) {
                const span = document.createElement('span');
                span.className = 'badge bg-danger';
                span.innerHTML = '⚠️ ' + (window.translations?.[detector] || detector);
                // Append before the source badge (usually the last child)
                const sourceBadge = badgeContainer.querySelector('[class^="badge source-color-"]');
                if (sourceBadge) {
                    badgeContainer.insertBefore(span, sourceBadge);
                } else {
                    badgeContainer.appendChild(span);
                }
            }
        });
    }

    // Update form input with enriched data
    const referenceInput = document.getElementById('reference-' + idRef);
    if (referenceInput) {
        referenceInput.value = JSON.stringify(referenceData);
    }
}

function autosaveReference(idRef, isDirtyOverride = null) {
    const referenceInput = document.getElementById('reference-' + idRef);
    const acceptedInput = document.getElementById('accepted-' + idRef);
    const dirtyInput = document.querySelector(`input[data-dirty-ref="${idRef}"]`);

    if (!referenceInput || !acceptedInput) return;

    autosave({
        refId: idRef,
        reference: referenceInput.value || '{}',
        accepted: acceptedInput.value,
        isDirty: isDirtyOverride ?? dirtyInput?.value ?? '0',
    });
}

function showAutosaveToast(isError = false, errorMessage = null) {
    const form = document.getElementById('form-extraction');
    let container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1100';
        document.body.appendChild(container);
    }

    const icon = document.createElement('i');
    icon.className = 'fas ' + (isError ? 'fa-circle-xmark' : 'fa-cloud-arrow-up') + ' flex-shrink-0';
    icon.setAttribute('aria-hidden', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-center gap-2';
    body.appendChild(icon);
    body.appendChild(document.createTextNode(errorMessage || form.dataset.autosaveLabel));

    const row = document.createElement('div');
    row.className = 'd-flex';
    row.appendChild(body);

    const progress = document.createElement('div');
    progress.className = 'toast-progress';

    const el = document.createElement('div');
    el.className = 'toast align-items-center ' + (isError ? 'text-bg-danger' : 'text-bg-success') + ' border-0';
    el.appendChild(row);
    el.appendChild(progress);

    container.appendChild(el);
    const toast = new Toast(el, { autohide: true, delay: isError ? 5000 : 2000 });
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

    const bgClass = type === 'success' ? 'text-bg-success' : 'text-bg-danger';
    const iconClass = type === 'success' ? 'fas fa-circle-check' : 'fas fa-circle-xmark';

    const icon = document.createElement('i');
    icon.className = iconClass + ' flex-shrink-0';
    icon.setAttribute('aria-hidden', 'true');

    const body = document.createElement('div');
    body.className = 'toast-body d-flex align-items-center gap-2';
    body.appendChild(icon);
    body.appendChild(document.createTextNode(message));

    const row = document.createElement('div');
    row.className = 'd-flex';
    row.appendChild(body);

    const progress = document.createElement('div');
    progress.className = 'toast-progress';

    const el = document.createElement('div');
    el.className = `toast align-items-center ${bgClass} border-0`;
    el.appendChild(row);
    el.appendChild(progress);

    container.appendChild(el);
    const toast = new Toast(el, { autohide: true, delay: 5000 });
    toast.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
}

function acceptRefModificationsDone(idRef) {
    document.querySelector(`input[data-dirty-ref="${idRef}"]`).value = 1;
}
