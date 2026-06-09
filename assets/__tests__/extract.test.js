import { fireEvent, waitFor } from '@testing-library/dom';

// Mock bootstrap
jest.mock('bootstrap', () => ({
    Modal: jest.fn().mockImplementation(() => ({
        show: jest.fn(),
        hide: jest.fn(),
    })),
    Toast: jest.fn().mockImplementation(() => ({
        show: jest.fn(),
    })),
}));

describe('extract.js', () => {
    let doiInput, textArea;
    let documentListeners = [];
    let windowListeners = [];

    const originalDocAdd = document.addEventListener;
    const originalWinAdd = window.addEventListener;

    beforeAll(() => {
        document.addEventListener = function (type, listener, options) {
            documentListeners.push({ type, listener, options });
            originalDocAdd.call(document, type, listener, options);
        };
        window.addEventListener = function (type, listener, options) {
            windowListeners.push({ type, listener, options });
            originalWinAdd.call(window, type, listener, options);
        };
    });

    afterAll(() => {
        document.addEventListener = originalDocAdd;
        window.addEventListener = originalWinAdd;
    });

    afterEach(() => {
        for (const { type, listener, options } of documentListeners) {
            document.removeEventListener(type, listener, options);
        }
        documentListeners = [];

        for (const { type, listener, options } of windowListeners) {
            window.removeEventListener(type, listener, options);
        }
        windowListeners = [];
    });

    beforeEach(() => {
        document.body.innerHTML = `
            <form id="form-extraction" data-csrf-token="test-token" data-autosave-url="/autosave" data-autosave-label="Saved">
                <input id="document_addReferenceDoi" value="">
                <textarea id="document_addReference"></textarea>
                <div id="doi-loading-indicator" class="d-none"></div>
                <div id="reference-loading-overlay" class="d-none"></div>
                <button id="confirm-adding" type="button"></button>
                <div id="doi-error-msg" class="d-none"></div>
                
                <div id="sortref">
                    <div class="container-reference" data-idref="1">
                        <div id="container-reference-informations-1">
                            <div id="textReference-1">Original Text</div>
                            <a id="linkDoiRef-1" href="https://doi.org/10.1000/old">10.1000/old</a>
                        </div>
                        <div id="modifyBtn-1" data-idref="1">Edit</div>
                        <textarea id="modifyTextArea-1" class="d-none"></textarea>
                        <textarea id="textareaRef-1" class="d-none">New Text</textarea>
                        <div id="modifyReferenceDoi-1" class="d-none">
                            <input id="textDoiRef-1" value="10.1000/new">
                        </div>
                        <div id="editActionBtns-1" class="d-none">
                            <button type="button" id="acceptModifyBtn-1">Confirm</button>
                            <button type="button" id="cancelModifyBtn-1">Cancel</button>
                        </div>
                        <input id="reference-1" value='{}'>
                        <input id="accepted-1" value="0">
                        <input data-dirty-ref="1" value="0">
                        <input type="checkbox" id="toggle-input-1" value="1">
                        <div class="ms-auto d-flex align-items-center gap-1 flex-wrap">
                             <span class="badge source-color-1">Source</span>
                        </div>
                    </div>
                </div>
                <input id="document_save" type="button">
                <div id="loading-screen" class="d-none"></div>
                <button id="extract-all" type="button" data-url-from-epi="test-url"></button>
                <input id="is-dirty" value="0">
                
                <div class="enrich-doi-btn" data-idref="1" data-doi="10.1002/test">
                    <span class="spinner-border d-none"></span>
                    <i class="fas fa-magic"></i>
                </div>
                
                <button id="accept-all" type="button"></button>
                <button id="decline-all" type="button"></button>
                <button id="btn-modal-addref" type="button"></button>
                <div id="modal-addref"></div>
                <div id="modal-importbib"></div>
                
                <button id="btn-import-semantic-scholar" type="button"></button>
                <button id="s2-import-btn" type="button" data-label-import="Import" data-label-importing="Importing..." data-csrf="token" data-url="/import"></button>
                <input id="s2-paper-id-input" value="">
                <div id="s2-error-msg" class="d-none"></div>
                <div id="s2-error-text"></div>
                <div id="modal-import-semantic-scholar"></div>
                
                <button id="select-delete-ref" type="button"></button>
                <button id="cancel-delete-ref" type="button" class="d-none"></button>
                <button id="toggle-select-all-ref" type="button" class="d-none" data-select-label="Select all" data-deselect-label="Deselect all"><i></i><span></span></button>
                <div id="alert-remove" class="d-none"></div>
                <input type="checkbox" class="ref-delete-check">
                
                <div id="closing-info-toast"></div>
            </form>
        `;

        doiInput = document.getElementById('document_addReferenceDoi');
        textArea = document.getElementById('document_addReference');

        // Reset modules to re-run DOMContentLoaded listener
        jest.resetModules();
        require('../js/extract.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));

        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({ success: true, citation: 'Test Citation', reference: { detectors: ['bug'] } }),
            }),
        );
    });

    test('should extract DOI on blur if reference is empty', async () => {
        doiInput.value = '10.1001/test';
        fireEvent.blur(doiInput);

        await waitFor(() => expect(textArea.value).toBe('Test Citation'));
        expect(global.fetch).toHaveBeenCalledWith(expect.stringMatching(/10.1001%2Ftest/));
    });

    test('should enrich existing reference on click', async () => {
        const enrichBtn = document.querySelector('.enrich-doi-btn');
        fireEvent.click(enrichBtn);

        await waitFor(() => expect(document.getElementById('textReference-1').textContent).toBe('Test Citation'));
    });

    test('should enter and exit edit mode', () => {
        const editBtn = document.getElementById('modifyBtn-1');
        fireEvent.click(editBtn);

        expect(document.getElementById('modifyTextArea-1')).not.toHaveClass('d-none');
        expect(editBtn).toHaveClass('d-none');

        const cancelBtn = document.getElementById('cancelModifyBtn-1');
        fireEvent.click(cancelBtn);

        expect(document.getElementById('modifyTextArea-1')).toHaveClass('d-none');
        expect(editBtn).not.toHaveClass('d-none');
    });

    test('should confirm edit and trigger autosave', async () => {
        const editBtn = document.getElementById('modifyBtn-1');
        fireEvent.click(editBtn);

        const confirmBtn = document.getElementById('acceptModifyBtn-1');
        fireEvent.click(confirmBtn);

        expect(document.getElementById('textReference-1').textContent).toBe('New Text');
        expect(document.getElementById('linkDoiRef-1').textContent).toBe('10.1000/new');

        // Wait for autosave fetch
        await waitFor(() => expect(global.fetch).toHaveBeenCalledWith('/autosave', expect.any(Object)));
    });

    test('should handle detector badges update in UI', async () => {
        // Trigger a fake autosave response with detectors
        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({
                        success: true,
                        reference: {
                            detectors: ['clayFeet'],
                        },
                        refId: '1',
                    }),
            }),
        );

        // Manually call autosave via an event that triggers it
        const editBtn = document.getElementById('modifyBtn-1');
        fireEvent.click(editBtn);
        const confirmBtn = document.getElementById('acceptModifyBtn-1');
        fireEvent.click(confirmBtn);

        await waitFor(() => {
            const badges = document.querySelectorAll('.badge.bg-danger');
            expect(badges.length).toBe(1);
            expect(badges[0].textContent).toContain('clayFeet');
        });
    });

    test('should handle toggle input click', async () => {
        const toggle = document.getElementById('toggle-input-1');
        const accepted = document.getElementById('accepted-1');
        const container = document.querySelector('.container-reference');

        expect(accepted.value).toBe('0');
        expect(toggle.checked).toBe(false);

        // Click to check it
        fireEvent.click(toggle);

        expect(toggle.checked).toBe(true);
        expect(accepted.value).toBe('1');
        expect(container).toHaveClass('acceptedRef');
        expect(container).not.toHaveClass('declinedRef');

        // Click to uncheck it
        fireEvent.click(toggle);

        expect(toggle.checked).toBe(false);
        expect(accepted.value).toBe('0');
        expect(container).toHaveClass('declinedRef');
        expect(container).toHaveClass('filtered');
    });

    test('should accept all references', () => {
        const toggle = document.getElementById('toggle-input-1');
        const accepted = document.getElementById('accepted-1');
        const container = document.querySelector('.container-reference');
        const btn = document.getElementById('accept-all');

        toggle.checked = false;
        fireEvent.click(btn);

        expect(toggle.checked).toBe(true);
        expect(accepted.value).toBe('1');
        expect(container).toHaveClass('acceptedRef');
    });

    test('should decline all references', () => {
        const toggle = document.getElementById('toggle-input-1');
        const accepted = document.getElementById('accepted-1');
        const container = document.querySelector('.container-reference');
        const btn = document.getElementById('decline-all');

        toggle.checked = true;
        fireEvent.click(btn);

        expect(toggle.checked).toBe(false);
        expect(accepted.value).toBe('0');
        expect(container).toHaveClass('declinedRef');
    });

    test('should scroll to top when saving', () => {
        const docSave = document.getElementById('document_save');
        const confirmAdding = document.getElementById('confirm-adding');
        window.scrollTo = jest.fn();

        fireEvent.click(docSave);
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });

        window.scrollTo.mockClear();
        fireEvent.click(confirmAdding);
        expect(window.scrollTo).toHaveBeenCalledWith({ top: 0, behavior: 'smooth' });
    });

    test('should handle DOI extraction error on blur', async () => {
        global.fetch = jest.fn(() => Promise.reject(new Error('Network Error')));
        
        doiInput.value = '10.1001/test';
        fireEvent.blur(doiInput);

        const errorMsg = document.getElementById('doi-error-msg');
        await waitFor(() => {
            expect(errorMsg.textContent).toBe('Could not reach the server or invalid response');
            expect(errorMsg).not.toHaveClass('d-none');
        });
    });

    test('should import semantic scholar metadata successfully', async () => {
        const triggerBtn = document.getElementById('btn-import-semantic-scholar');
        const importBtn = document.getElementById('s2-import-btn');
        const inputEl = document.getElementById('s2-paper-id-input');
        const modalEl = document.getElementById('modal-import-semantic-scholar');

        modalEl.dispatchEvent(new Event('show.bs.modal'));
        fireEvent.click(triggerBtn);

        inputEl.value = '12345';

        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ success: true, message: 'Success' }),
            }),
        );

        fireEvent.click(importBtn);

        await waitFor(() => expect(global.fetch).toHaveBeenCalledWith('/import', expect.any(Object)));
    });

    test('should handle semantic scholar import error', async () => {
        const importBtn = document.getElementById('s2-import-btn');
        const inputEl = document.getElementById('s2-paper-id-input');
        const errorDiv = document.getElementById('s2-error-msg');
        const errorText = document.getElementById('s2-error-text');

        inputEl.value = '12345';

        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ success: false, error: 'Import failed error' }),
            }),
        );

        fireEvent.click(importBtn);

        await waitFor(() => {
            expect(errorText.textContent).toBe('Import failed error');
            expect(errorDiv).not.toHaveClass('d-none');
        });
    });

    test('should handle beforeunload warning when dirty', () => {
        const isDirty = document.getElementById('is-dirty');
        isDirty.value = '1';

        const event = new Event('beforeunload');
        let val = true;
        Object.defineProperty(event, 'returnValue', {
            get: () => val,
            set: (v) => { val = v; }
        });
        window.dispatchEvent(event);

        expect(val).toBe(null);
    });

    test('should show error when paper ID is empty', () => {
        const importBtn = document.getElementById('s2-import-btn');
        const inputEl = document.getElementById('s2-paper-id-input');
        const errorDiv = document.getElementById('s2-error-msg');
        const errorText = document.getElementById('s2-error-text');

        inputEl.value = '';

        fireEvent.click(importBtn);

        expect(errorText.textContent).toBe('Please enter a paper ID.');
        expect(errorDiv).not.toHaveClass('d-none');
    });

    test('should handle network error in semantic scholar import', async () => {
        const importBtn = document.getElementById('s2-import-btn');
        const inputEl = document.getElementById('s2-paper-id-input');
        const errorDiv = document.getElementById('s2-error-msg');
        const errorText = document.getElementById('s2-error-text');

        inputEl.value = '12345';
        global.fetch = jest.fn(() => Promise.reject(new Error('Network error')));

        fireEvent.click(importBtn);

        await waitFor(() => {
            expect(errorText.textContent).toBe('A network error occurred.');
            expect(errorDiv).not.toHaveClass('d-none');
        });
    });

    test('should handle remove reference interactions', () => {
        const deleteBtn = document.getElementById('select-delete-ref');
        const cancelBtn = document.getElementById('cancel-delete-ref');
        const toggleAllBtn = document.getElementById('toggle-select-all-ref');
        const alertRemove = document.getElementById('alert-remove');
        const deleteCheck = document.querySelector('.ref-delete-check');

        fireEvent.click(deleteBtn);
        expect(alertRemove).not.toHaveClass('d-none');
        expect(deleteBtn).toHaveClass('d-none');
        expect(cancelBtn).not.toHaveClass('d-none');
        expect(toggleAllBtn).not.toHaveClass('d-none');

        fireEvent.click(toggleAllBtn);
        expect(deleteCheck.checked).toBe(true);

        fireEvent.click(cancelBtn);
        expect(alertRemove).toHaveClass('d-none');
        expect(deleteBtn).not.toHaveClass('d-none');
        expect(cancelBtn).toHaveClass('d-none');
        expect(toggleAllBtn).toHaveClass('d-none');
        expect(deleteCheck.checked).toBe(false);
    });
});
