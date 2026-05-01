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
    let doiInput, textArea, confirmAddingBtn;

    beforeEach(() => {
        document.body.innerHTML = `
            <form id="form-extraction" data-csrf-token="test-token" data-autosave-url="/autosave" data-autosave-label="Saved">
                <input id="document_addReferenceDoi" value="">
                <textarea id="document_addReference"></textarea>
                <div id="doi-loading-indicator" class="d-none"></div>
                <div id="reference-loading-overlay" class="d-none"></div>
                <button id="confirm-adding"></button>
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
                            <button id="acceptModifyBtn-1">Confirm</button>
                            <button id="cancelModifyBtn-1">Cancel</button>
                        </div>
                        <input id="reference-1" value='{}'>
                        <input id="accepted-1" value="0">
                        <input data-dirty-ref="1" value="0">
                        <div class="ms-auto d-flex align-items-center gap-1 flex-wrap">
                             <span class="badge source-color-1">Source</span>
                        </div>
                    </div>
                </div>
                <input id="document_save" type="button">
                <div id="loading-screen" class="d-none"></div>
                <button id="extract-all" data-url-from-epi="test-url"></button>
                <input id="is-dirty" value="0">
                
                <div class="enrich-doi-btn" data-idref="1" data-doi="10.1002/test">
                    <span class="spinner-border d-none"></span>
                    <i class="fas fa-magic"></i>
                </div>
            </form>
        `;
        
        doiInput = document.getElementById('document_addReferenceDoi');
        textArea = document.getElementById('document_addReference');
        confirmAddingBtn = document.getElementById('confirm-adding');

        // Reset modules to re-run DOMContentLoaded listener
        jest.resetModules();
        require('../js/extract.js');
        document.dispatchEvent(new Event('DOMContentLoaded'));
        
        global.fetch = jest.fn(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ success: true, citation: 'Test Citation', reference: { detectors: ['bug'] } }),
            })
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
                json: () => Promise.resolve({ 
                    success: true, 
                    reference: { 
                        detectors: ['clayFeet'] 
                    },
                    refId: '1'
                }),
            })
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
});
