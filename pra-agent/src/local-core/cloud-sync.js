'use strict';

// One in-process flight even when timer, network-resume and manual callers
// request a drain together. fetch is injected so this remains plain Node.
class CloudSyncClient {
    constructor(options) {
        const opts = options || {};
        if (!opts.store) throw new Error('store is required');
        if (typeof opts.request !== 'function') throw new Error('request is required');
        this.store = opts.store;
        this.request = opts.request;
        this.batchSize = Number.isInteger(opts.batchSize) ? opts.batchSize : 100;
        this.deviceUid = String(opts.deviceUid || '');
        if (!this.deviceUid) throw new Error('deviceUid is required');
        this.inFlight = null;
        this.lastResult = null;
    }

    sync() {
        if (this.inFlight) return this.inFlight;
        this.inFlight = this._sync().finally(() => { this.inFlight = null; });
        return this.inFlight;
    }

    async _sync() {
        if (this.store.status().read_only) return (this.lastResult = { ok: false, error: 'store_read_only' });
        const events = this.store.pending(this.batchSize);
        if (!events.length) return (this.lastResult = { ok: true, sent: 0, pending: 0 });
        try {
            this.store.noteAttempt(events.map((e) => e.id));
            const submittedIds = new Set(events.map((e) => e.id));
            const response = await this.request({
                version: 1,
                device_uid: this.deviceUid,
                events: events.map((e) => ({
                    event_id: e.id,
                    event_type: e.type,
                    occurred_at: new Date(e.at_ms).toISOString(),
                    idempotency_key: e.id,
                    payload: e.payload,
                })),
            });
            // A server response is untrusted input. Never allow an ACK for an
            // event that was not in this exact flight to mutate the outbox.
            const acknowledged = response && Array.isArray(response.acknowledged_ids)
                ? Array.from(new Set(response.acknowledged_ids
                    .map(String).filter((id) => submittedIds.has(id))))
                : [];
            this.store.markSent(acknowledged);
            return (this.lastResult = { ok: true, sent: acknowledged.length, pending: this.store.status().pending_count });
        } catch (e) {
            return (this.lastResult = { ok: false, error: (e && e.message) || 'cloud_sync_failed', pending: this.store.status().pending_count });
        }
    }

    status() { return { in_flight: !!this.inFlight, last_result: this.lastResult }; }
}

module.exports = { CloudSyncClient };