import './bootstrap';

import Alpine from 'alpinejs';

if (!window.__alpineStarted) {
    window.Alpine = Alpine;
    window.__alpineStarted = true;
    Alpine.start();
}
