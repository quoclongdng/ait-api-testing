import './bootstrap';
import Alpine from 'alpinejs';
import modalFocus from './modal-focus';

// Confirmation prompts without inline event handlers (the CSP forbids onclick/onsubmit, ADR-0007):
// a submit button or form carrying data-confirm="..." asks before submitting. Capture phase, so it
// runs before Alpine @submit.prevent handlers and can stop them.
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;
    const message = event.submitter?.dataset?.confirm ?? form.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);

window.Alpine = Alpine;
Alpine.plugin(modalFocus);
Alpine.start();
