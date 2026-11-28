(() => {
    const reel = document.querySelector('[data-client-reel]');
    if (!reel) return;

    const storageKey = 'taxnest.client-reel.dismissed';
    try {
        if (sessionStorage.getItem(storageKey) === '1') return;
    } catch {
        // The controls still work when the browser blocks session storage.
    }

    const closeButton = reel.querySelector('[data-client-reel-close]');
    const pauseButton = reel.querySelector('[data-client-reel-pause]');
    let paused = false;

    const dismiss = (restoreFocus) => {
        if (reel.hidden) return;
        const hadFocus = reel.contains(document.activeElement);
        reel.hidden = true;
        try {
            sessionStorage.setItem(storageKey, '1');
        } catch {
            // Dismissal still applies to the current page.
        }
        document.removeEventListener('click', onOutside, true);
        document.removeEventListener('keydown', onEscape);
        if (restoreFocus && hadFocus) {
            document.querySelector('[data-client-directory-link]')?.focus({ preventScroll: true });
        }
    };
    const onOutside = (event) => {
        if (!reel.contains(event.target)) dismiss(false);
    };
    const onEscape = (event) => {
        if (event.key === 'Escape') dismiss(true);
    };

    closeButton.addEventListener('click', () => dismiss(true));
    pauseButton?.addEventListener('click', () => {
        paused = !paused;
        reel.dataset.paused = String(paused);
        pauseButton.textContent = paused ? 'Resume' : 'Pause';
    });
    document.addEventListener('click', onOutside, true);
    document.addEventListener('keydown', onEscape);
    reel.hidden = false;
})();
