import '@testing-library/jest-dom';

// Mock Sortable.js globally
jest.mock('sortablejs/modular/sortable.core.esm', () => ({
  Sortable: {
    create: jest.fn(() => ({
      destroy: jest.fn(),
    })),
  },
}));
