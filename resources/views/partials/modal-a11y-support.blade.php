<script>
window.TnModalA11y = window.TnModalA11y || {
    selector: [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])'
    ].join(','),
    open(root) {
        if (!root) return;
        root.__tnPreviousFocus = document.activeElement instanceof HTMLElement
            ? document.activeElement
            : null;
        window.requestAnimationFrame(() => {
            const first = root.querySelector('[autofocus], ' + this.selector);
            if (first instanceof HTMLElement) first.focus();
        });
    },
    close(root) {
        const previous = root && root.__tnPreviousFocus;
        if (previous instanceof HTMLElement && document.contains(previous)) {
            window.requestAnimationFrame(() => previous.focus());
        }
    },
    trap(event, root) {
        if (!root || event.key !== 'Tab') return;
        const focusable = Array.from(root.querySelectorAll(this.selector))
            .filter(el => el instanceof HTMLElement && el.offsetParent !== null);
        if (!focusable.length) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
};
</script>