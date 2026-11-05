'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const SAMPLE_MS = 5 * 60 * 1000;
const RETAIN_MS = 14 * 24 * 60 * 60 * 1000;
const MAX_EVENTS = 4096;
const MAX_BYTES = 2 * 1024 * 1024;
const integer = (v, max) => Number.isSafeInteger(v) && v >= 0 ? Math.min(max, v) : null;

// Numeric operational samples only: no logs, names, document IDs, URLs, tokens,
// fiscal payloads or print commands. The file is bound to one pairing identity.
function sample(raw = {}) {
  const p = raw.printer || {}, q = raw.queue || {}, f = raw.fiscal_connectivity || {};
  return {
    printing_enabled: typeof p.printing_enabled === 'boolean' ? p.printing_enabled : null,
    printer_healthy: typeof p.healthy === 'boolean' ? p.healthy : null,
    printers_reported: integer(p.printers_reported, 100),
    pending_callbacks: integer(q.pending_callbacks, 100000),
    fiscal_state: ['unknown', 'reachable', 'unreachable'].includes(f.state) ? f.state : 'unknown',
  };
}

class DiagnosticBuffer {
  constructor(root, config, now = Date.now, io = fs) {
    this.io = io;
    this.now = now;
    const identity = [config.serverUrl, config.companyId, config.deviceUid, config.apiKey];
    this.file = path.join(root, 'diagnostics', crypto.createHash('sha256').update(JSON.stringify(identity)).digest('hex') + '.json');
    this.error = null;
    this.state = {version: 1, enabled: false, stream_id: crypto.randomUUID(), next_sequence: 1, dropped: 0, resets: 0, last_sample_at: 0, events: []};
    try {
      if (io.existsSync(this.file)) {
        if (io.statSync(this.file).size > MAX_BYTES) throw new Error('oversize');
        const s = JSON.parse(io.readFileSync(this.file, 'utf8'));
        if (s.version !== 1 || typeof s.enabled !== 'boolean' || !/^[a-f0-9-]{36}$/.test(s.stream_id) || !Number.isSafeInteger(s.next_sequence) || s.next_sequence < 1
          || !Array.isArray(s.events) || s.events.length > MAX_EVENTS || !Number.isSafeInteger(s.dropped) || s.dropped < 0
          || !Number.isSafeInteger(s.resets) || s.resets < 0 || !Number.isFinite(s.last_sample_at)
          || s.events.some((e, i) => !Number.isSafeInteger(e.sequence) || e.sequence < 1 || e.sequence >= s.next_sequence
            || (i > 0 && e.sequence <= s.events[i - 1].sequence) || !Number.isFinite(Date.parse(e.at)))) throw new Error('invalid');
        this.state = {version: 1, enabled:s.enabled, stream_id:s.stream_id, next_sequence:s.next_sequence, dropped:s.dropped, resets:s.resets, last_sample_at:s.last_sample_at, events: s.events.map(e => ({sequence: e.sequence, at: e.at, ...sample({printer: {printing_enabled:e.printing_enabled,healthy:e.printer_healthy,printers_reported:e.printers_reported},queue:{pending_callbacks:e.pending_callbacks},fiscal_connectivity:{state:e.fiscal_state}})}))};
      }
    } catch (_) {
      this.error = 'buffer_reset';
      this.state.resets = 1;
      // A damaged file is retained for local recovery. Do not overwrite it or
      // acknowledge data until a successful supported-server response arrives.
    }
  }

  persist() {
    try {
      this.io.mkdirSync(path.dirname(this.file), {recursive: true, mode: 0o700});
      const encoded = JSON.stringify(this.state);
      if (Buffer.byteLength(encoded) > MAX_BYTES) throw new Error('size');
      const tmp = this.file + '.tmp';
      this.io.writeFileSync(tmp, encoded, {mode: 0o600});
      this.io.renameSync(tmp, this.file);
      return true;
    } catch (_) { this.error = 'storage_unavailable'; return false; }
  }

  capture(raw) {
    if (!this.state.enabled || !raw || typeof raw !== 'object') return;
    const now = this.now();
    const keep = this.state.events.filter(e => Date.parse(e.at) >= now - RETAIN_MS);
    this.state.dropped += this.state.events.length - keep.length;
    this.state.events = keep;
    if (now >= this.state.last_sample_at && now - this.state.last_sample_at < SAMPLE_MS) return;
    while (this.state.events.length >= MAX_EVENTS) { this.state.events.shift(); this.state.dropped++; }
    this.state.events.push({sequence: this.state.next_sequence++, at: new Date(now).toISOString(), ...sample(raw)});
    this.state.last_sample_at = now;
    this.persist();
  }

  batch() {
    return {version: 1, stream_id: this.state.stream_id, dropped: this.state.dropped, resets: this.state.resets,
      storage_status: this.error || 'available', pending: this.state.events.length, events: this.state.events.slice(0, 50)};
  }

  acknowledge(ack, sent) {
    if (!ack || ack.version !== 1 || ack.stream_id !== sent.stream_id || !Array.isArray(ack.accepted)) return;
    const offered = new Set(sent.events.map(e => e.sequence));
    const accepted = new Set(ack.accepted.filter(n => Number.isSafeInteger(n) && offered.has(n)));
    this.state.enabled = true;
    this.state.events = this.state.events.filter(e => !accepted.has(e.sequence));
    this.persist();
  }
}

module.exports = {DiagnosticBuffer, sample, SAMPLE_MS, RETAIN_MS, MAX_EVENTS};
