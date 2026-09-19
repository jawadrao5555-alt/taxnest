import './bootstrap';
import './password-visibility';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

const startAlpine = window.__tnStartAlpine || ((runtime, plugins = [], source = 'vite') => {
    const boot = window.__tnAlpineBoot = window.__tnAlpineBoot || {
        status: 'idle',
        source: null,
        starts: 0,
    };

    if (boot.status === 'started' || boot.status === 'starting') {
        return window.Alpine || runtime;
    }

    boot.status = 'starting';
    boot.source = source;
    plugins.filter(Boolean).forEach(plugin => runtime.plugin(plugin));
    window.Alpine = runtime;

    try {
        runtime.start();
        boot.status = 'started';
        boot.starts += 1;
        window.__alpineStarted = true;
    } catch (error) {
        boot.status = 'failed';
        window.__alpineStarted = false;
        throw error;
    }

    return runtime;
});

startAlpine(Alpine, [collapse], 'vite');
