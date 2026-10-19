// x-modal-focus="<open expression>" on a role="dialog" element: when the expression becomes true the
// focus moves into the dialog (first invalid field, then [autofocus], then the first form control),
// Tab/Shift+Tab stay inside it, and when it becomes false the focus goes back to the control that
// opened it. data-modal-trigger="#id" names the control to return to when the dialog was already
// open on page load (a server-side validation error reopened it).
const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const isVisible = (node) => node.getClientRects().length > 0;

export default function modalFocus(Alpine) {
    Alpine.directive('modal-focus', (el, { expression }, { evaluateLater, effect, cleanup }) => {
        const readOpen = evaluateLater(expression);
        let wasOpen = false;
        let returnTo = null;

        const focusables = () => [...el.querySelectorAll(FOCUSABLE)].filter(isVisible);

        const focusFirst = () => {
            const target = el.querySelector('[aria-invalid="true"]')
                ?? el.querySelector('[autofocus]')
                ?? el.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])')
                ?? focusables()[0];
            if (target && isVisible(target)) {
                target.focus();
            } else if (!el.contains(document.activeElement)) {
                el.setAttribute('tabindex', '-1');
                el.focus();
            }
        };

        const trapTab = (event) => {
            if (event.key !== 'Tab') return;
            const items = focusables();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            const active = document.activeElement;
            if (event.shiftKey && (active === first || !el.contains(active))) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (active === last || !el.contains(active))) {
                event.preventDefault();
                first.focus();
            }
        };

        effect(() => {
            readOpen((value) => {
                const open = Boolean(value);
                if (open && !wasOpen) {
                    const active = document.activeElement;
                    returnTo = active && active !== document.body && !el.contains(active)
                        ? active
                        : (el.dataset.modalTrigger ? document.querySelector(el.dataset.modalTrigger) : null);
                    document.addEventListener('keydown', trapTab, true);
                    // x-show reveals the dialog in the same tick; wait one frame so it is focusable.
                    Alpine.nextTick(() => requestAnimationFrame(focusFirst));
                } else if (!open && wasOpen) {
                    document.removeEventListener('keydown', trapTab, true);
                    if (returnTo && document.contains(returnTo)) {
                        returnTo.focus();
                    }
                    returnTo = null;
                }
                wasOpen = open;
            });
        });

        cleanup(() => document.removeEventListener('keydown', trapTab, true));
    });
}
