'use strict';

// Transport-neutral handler used by Electron IPC. Authentication is injected
// by main.js as an exact webContents identity check; this module never listens.
function createEventIngress(options) {
    const opts = options || {};
    if (typeof opts.isAuthorized !== 'function' || typeof opts.storeProvider !== 'function') {
        throw new Error('Core ingress requires authorization and store providers');
    }
    return function append(context, input) {
        if (!opts.isAuthorized(context)) return { ok: false, error: 'unauthorized' };
        const store = opts.storeProvider();
        if (!store) return { ok: false, error: 'core_disabled' };
        try {
            const result = store.append(input);
            if (typeof opts.onAccepted === 'function') opts.onAccepted(result);
            return { ok: true, id: result.event.id, duplicate: !!result.duplicate };
        } catch (e) {
            return { ok: false, error: (e && e.code) || 'invalid_event' };
        }
    };
}

module.exports = { createEventIngress };