const LABELS = {
    en: { show: 'Show password', hide: 'Hide password' },
    rur: { show: 'Password dikhayein', hide: 'Password chupaen' },
    ur: { show: 'پاس ورڈ دکھائیں', hide: 'پاس ورڈ چھپائیں' },
};

const EYE_OPEN = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.46 12C3.73 7.94 7.52 5 12 5s8.27 2.94 9.54 7c-1.27 4.06-5.06 7-9.54 7S3.73 16.06 2.46 12Z"/><circle cx="12" cy="12" r="3" stroke-width="2"/></svg>';
const EYE_CLOSED = '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m3 3 18 18M10.6 10.6A2 2 0 0 0 13.4 13.4M9.9 5.2A10.6 10.6 0 0 1 12 5c4.48 0 8.27 2.94 9.54 7a9.8 9.8 0 0 1-2 3.5M6.2 6.2A10.2 10.2 0 0 0 2.46 12C3.73 16.06 7.52 19 12 19c1.4 0 2.72-.29 3.9-.8"/></svg>';
const STYLE_ID = 'tn-password-visibility-styles';

function labels() {
    const language = (document.documentElement.lang || 'en').toLowerCase().replace('_', '-');
    const key = language === 'ur' ? 'ur' : (language === 'rur' || language.startsWith('rur-') ? 'rur' : 'en');
    return LABELS[key];
}

function setButtonState(button, visible) {
    const copy = labels();
    button.innerHTML = visible ? EYE_CLOSED : EYE_OPEN;
    button.setAttribute('aria-label', visible ? copy.hide : copy.show);
    button.setAttribute('title', visible ? copy.hide : copy.show);
    button.setAttribute('aria-pressed', visible ? 'true' : 'false');
}

function ensureStyles() {
    if (document.getElementById(STYLE_ID)) {
        return;
    }

    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
        .tn-password-field{position:relative;display:block;width:100%;min-width:0}
        .tn-password-field>input{padding-inline-end:3rem!important}
        .tn-password-toggle{position:absolute;inset-inline-end:.25rem;top:50%;z-index:2;display:inline-flex;width:2.5rem;height:2.5rem;transform:translateY(-50%);align-items:center;justify-content:center;border:0;border-radius:.5rem;background:transparent;color:currentColor;opacity:.62;cursor:pointer;touch-action:manipulation}
        .tn-password-toggle:hover{opacity:.9}
        .tn-password-toggle:focus-visible,.tn-password-group-toggle:focus-visible{opacity:1;outline:2px solid currentColor;outline-offset:2px}
        .tn-password-toggle svg,.tn-password-group-toggle svg{width:1.25rem;height:1.25rem;pointer-events:none}
        .tn-password-field:has(>input[style*="display: none"]){display:none}
        .tn-password-group-toggle{display:inline-flex;min-width:2.75rem;min-height:2.75rem;align-items:center;justify-content:center;border-radius:.5rem;touch-action:manipulation}
    `;
    document.head.appendChild(style);
}

export function enhancePasswordInput(input) {
    if (
        !(input instanceof HTMLInputElement)
        || input.type !== 'password'
        || input.dataset.passwordToggleReady === 'true'
        || input.dataset.passwordToggleExempt === 'true'
        || input.hasAttribute(':type')
        || input.hasAttribute('x-bind:type')
        || input.hidden
        || input.classList.contains('hidden')
    ) {
        return;
    }

    input.dataset.passwordToggleReady = 'true';
    const wrapper = document.createElement('span');
    wrapper.className = 'tn-password-field';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'tn-password-toggle';
    button.dataset.passwordToggle = 'true';
    button.setAttribute('aria-controls', input.id || '');
    setButtonState(button, false);

    // Prevent pointer taps from stealing the caret on phones. Click remains
    // available to keyboards and assistive technology.
    button.addEventListener('pointerdown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
        const start = input.selectionStart;
        const end = input.selectionEnd;
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        setButtonState(button, visible);
        input.focus({ preventScroll: true });
        try {
            input.setSelectionRange(start, end);
        } catch (_) {
            // Some readonly browser controls do not expose a selection range.
        }
    });

    wrapper.appendChild(button);
}

export function enhancePasswordInputs(root = document) {
    if (root instanceof HTMLInputElement) {
        enhancePasswordInput(root);
    }
    root.querySelectorAll?.('input[type="password"]').forEach(enhancePasswordInput);
}

function startPasswordVisibility() {
    ensureStyles();
    enhancePasswordInputs();
    document.querySelectorAll('[data-password-group-toggle]').forEach((button) => setButtonState(button, false));
    document.addEventListener('pointerdown', (event) => {
        if (event.target.closest?.('[data-password-group-toggle]')) {
            event.preventDefault();
        }
    });
    document.addEventListener('click', (event) => {
        const button = event.target.closest?.('[data-password-group-toggle]');
        if (!button) {
            return;
        }

        const fields = Array.from(document.querySelectorAll(button.dataset.passwordGroupToggle));
        const visible = fields.some((field) => field.type === 'password');
        const focused = fields.includes(document.activeElement) ? document.activeElement : fields[0];
        const start = focused?.selectionStart;
        const end = focused?.selectionEnd;
        fields.forEach((field) => { field.type = visible ? 'text' : 'password'; });
        setButtonState(button, visible);
        focused?.focus({ preventScroll: true });
        try {
            focused?.setSelectionRange(start, end);
        } catch (_) {
            // Keep visibility available if a browser does not expose selection.
        }
    });
    new MutationObserver((mutations) => {
        mutations.forEach(({ addedNodes }) => {
            addedNodes.forEach((node) => {
                if (node instanceof HTMLElement) {
                    enhancePasswordInputs(node);
                }
            });
        });
    }).observe(document.documentElement, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', startPasswordVisibility, { once: true });
} else {
    startPasswordVisibility();
}