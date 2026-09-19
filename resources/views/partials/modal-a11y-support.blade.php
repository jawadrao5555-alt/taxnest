<script>
window.TnModalA11y = window.TnModalA11y || {
    stack: [],
    selector: [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(','),
    isVisible(el) {
        if (!(el instanceof HTMLElement) || el.hidden || el.inert || el.getAttribute('aria-hidden') === 'true') return false;
        const style = window.getComputedStyle(el);
        return style.display !== 'none' && style.visibility !== 'hidden' && el.getClientRects().length > 0;
    },
    controls(root) {
        if (!root) return [];
        const nodes = Array.from(root.querySelectorAll(this.selector));
        if (root.id) {
            const owner = window.CSS && CSS.escape ? CSS.escape(root.id) : root.id.replace(/["\\]/g, '\\$&');
            document.querySelectorAll(`[data-tn-modal-owner="${owner}"] ${this.selector}, [data-tn-modal-owner="${owner}"]${this.selector}`)
                .forEach(el => nodes.push(el));
        }
        return Array.from(new Set(nodes)).filter(el =>
            this.isVisible(el) &&
            !el.matches(':disabled') &&
            el.getAttribute('aria-disabled') !== 'true' &&
            el.tabIndex >= 0
        );
    },
    active() {
        while (this.stack.length && !this.isVisible(this.stack[this.stack.length - 1])) this.stack.pop();
        return this.stack[this.stack.length - 1] || null;
    },
    focusFirst(root) {
        const first = root && root.querySelector('[autofocus]');
        const target = this.isVisible(first) ? first : this.controls(root)[0];
        if (target instanceof HTMLElement) target.focus({ preventScroll: true });
        else if (root instanceof HTMLElement) {
            if (!root.hasAttribute('tabindex')) root.setAttribute('tabindex', '-1');
            root.focus({ preventScroll: true });
        }
    },
    open(root) {
        if (!root) return;
        if (!root.__tnModalOpen) {
            root.__tnPreviousFocus = document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
            root.__tnModalOpen = true;
        }
        this.stack = this.stack.filter(item => item !== root);
        this.stack.push(root);
        window.requestAnimationFrame(() => {
            if (this.active() === root) this.focusFirst(root);
        });
    },
    close(root) {
        this.stack = this.stack.filter(item => item !== root);
        if (root) root.__tnModalOpen = false;
        const previous = root && root.__tnPreviousFocus;
        const active = this.active();
        if (active) {
            const owner = active.id && previous instanceof HTMLElement
                ? previous.closest(`[data-tn-modal-owner="${active.id}"]`)
                : null;
            if (previous instanceof HTMLElement && previous.isConnected && this.isVisible(previous) &&
                (active.contains(previous) || owner)) {
                window.requestAnimationFrame(() => previous.focus({ preventScroll: true }));
            } else {
                window.requestAnimationFrame(() => this.focusFirst(active));
            }
            return;
        }
        if (previous instanceof HTMLElement && previous.isConnected && this.isVisible(previous)) {
            window.requestAnimationFrame(() => previous.focus({ preventScroll: true }));
        }
    },
    trap(event, root) {
        if (!root || event.key !== 'Tab') return;
        const active = this.active();
        if (active && active !== root) {
            event.preventDefault();
            this.focusFirst(active);
            return;
        }
        const focusable = this.controls(root);
        if (!focusable.length) {
            event.preventDefault();
            this.focusFirst(root);
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const current = focusable.indexOf(document.activeElement);
        if (event.shiftKey && current <= 0) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (current === -1 || document.activeElement === last)) {
            event.preventDefault();
            first.focus();
        }
    },
    escape(event, root, dismiss) {
        if (!root || event.key !== 'Escape' || this.active() !== root) return;
        event.preventDefault();
        event.stopPropagation();
        if (root.getAttribute('data-tn-dismissible') !== 'true') {
            this.focusFirst(root);
            return;
        }
        if (typeof dismiss === 'function') dismiss();
    }
};
if (!window.__tnModalFocusGuard) {
    window.__tnModalFocusGuard = true;
    document.addEventListener('focusin', event => {
        const modal = window.TnModalA11y.active();
        if (modal && !modal.contains(event.target) && !event.target.closest?.(`[data-tn-modal-owner="${modal.id}"]`)) {
            window.TnModalA11y.focusFirst(modal);
        }
    }, true);
}
</script>