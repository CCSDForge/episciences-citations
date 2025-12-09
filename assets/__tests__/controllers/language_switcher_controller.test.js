import { Application } from '@hotwired/stimulus';
import LanguageSwitcherController from '../../controllers/language_switcher_controller';

describe('LanguageSwitcherController', () => {
  let application;
  let element;

  beforeEach(() => {
    // Setup DOM
    document.body.innerHTML = `
      <div data-controller="language-switcher">
        <button data-language-switcher-target="button"
                data-action="click->language-switcher#toggle keydown->language-switcher#handleKeydown">
          Toggle
        </button>
        <div data-language-switcher-target="dropdown" class="hidden">
          Dropdown content
        </div>
      </div>
    `;

    element = document.querySelector('[data-controller="language-switcher"]');
    application = Application.start();
    application.register('language-switcher', LanguageSwitcherController);
  });

  afterEach(() => {
    application.stop();
    document.body.innerHTML = '';
  });

  test('should toggle dropdown on button click', () => {
    const button = document.querySelector('[data-language-switcher-target="button"]');
    const dropdown = document.querySelector('[data-language-switcher-target="dropdown"]');

    expect(dropdown).toHaveClass('hidden');

    button.click();

    expect(dropdown).not.toHaveClass('hidden');
    expect(button).toHaveAttribute('aria-expanded', 'true');
  });

  test('should close dropdown on outside click', () => {
    const button = document.querySelector('[data-language-switcher-target="button"]');
    const dropdown = document.querySelector('[data-language-switcher-target="dropdown"]');

    // Open dropdown
    button.click();
    expect(dropdown).not.toHaveClass('hidden');

    // Click outside
    document.body.click();

    expect(dropdown).toHaveClass('hidden');
  });

  test('should close dropdown on Escape key', () => {
    const button = document.querySelector('[data-language-switcher-target="button"]');
    const dropdown = document.querySelector('[data-language-switcher-target="dropdown"]');

    // Open dropdown
    button.click();
    expect(dropdown).not.toHaveClass('hidden');

    // Press Escape
    const event = new KeyboardEvent('keydown', { key: 'Escape' });
    button.dispatchEvent(event);

    expect(dropdown).toHaveClass('hidden');
  });
});
