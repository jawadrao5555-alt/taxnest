import './bootstrap';

import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';

if (!window.__alpineStarted) {
    Alpine.plugin(collapse);
    window.Alpine = Alpine;
    window.__alpineStarted = true;
    Alpine.start();
}
