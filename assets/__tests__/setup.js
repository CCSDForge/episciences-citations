import '@testing-library/jest-dom';

// Mock Sortable.js globally
jest.mock('sortablejs/modular/sortable.core.esm', () => ({
    Sortable: {
        create: jest.fn(() => ({
            destroy: jest.fn(),
            option: jest.fn(),
        })),
    },
}));

// JSDOM does not implement requestSubmit
if (typeof HTMLFormElement !== 'undefined' && !HTMLFormElement.prototype.requestSubmit) {
    HTMLFormElement.prototype.requestSubmit = function() {
        this.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    };
}

