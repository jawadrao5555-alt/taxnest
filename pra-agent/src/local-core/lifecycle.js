'use strict';

// Pure-Node lifecycle gate. Keeping the opt-in decision here makes it possible
// to prove that disabled mode performs no key/store I/O.
class LocalCoreLifecycle {
    constructor(options) {
        const opts = options || {};
        if (typeof opts.open !== 'function' || typeof opts.close !== 'function') {
            throw new Error('Local Core lifecycle requires open and close callbacks');
        }
        this.openStore = opts.open;
        this.closeStore = opts.close;
        this.active = false;
        this.last = { enabled: false };
    }

    apply(enabled) {
        if (!enabled) {
            if (this.active) this.closeStore();
            this.active = false;
            return (this.last = { enabled: false });
        }
        const health = this.openStore();
        this.active = !!(health && health.enabled !== false);
        return (this.last = health || { enabled: this.active });
    }

    close() {
        if (this.active) this.closeStore();
        this.active = false;
        this.last = { enabled: false };
    }

    status() { return Object.assign({}, this.last, { active: this.active }); }
}

function heartbeatAllowsCore(config, heartbeat) {
    if (!config || !heartbeat) return false;
    const companyId = String(config.companyId || '');
    const deviceUid = String(config.deviceUid || '');
    if (!companyId || !deviceUid) return false;
    if (heartbeat.local_core_kill_switch === true || heartbeat.local_core_enabled === false) return false;
    const capability = heartbeat.local_core;
    const details = capability && typeof capability === 'object' ? capability :
        (heartbeat.capabilities && heartbeat.capabilities.local_core &&
         typeof heartbeat.capabilities.local_core === 'object' ? heartbeat.capabilities.local_core : null);
    // Never infer registration from a bare capability. The server must bind
    // this exact device namespace to this exact company in its signed/auth'd
    // heartbeat response before a local key or journal can be opened.
    if (!details || details.enabled !== true || details.device_registered !== true) return false;
    if (details.company_id != null && String(details.company_id) !== companyId) return false;
    if (details.device_uid != null && String(details.device_uid) !== deviceUid) return false;
    if (details.company_id == null || details.device_uid == null || details.kill_switch === true) return false;
    return String(details.company_id) === companyId && String(details.device_uid) === deviceUid;
}

module.exports = { LocalCoreLifecycle, heartbeatAllowsCore };