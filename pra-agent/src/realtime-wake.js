'use strict';

// Small, dependency-injected WebSocket lifecycle used by the printer service.
// Authentication stays in the HTTP upgrade headers; it is deliberately never
// put in the URL (which may be captured by proxies or diagnostics).
class RealtimeWakeClient {
  constructor(options) {
    this.WebSocket = options.WebSocket;
    this.url = options.url;
    this.apiKey = options.apiKey;
    this.onWake = options.onWake || (() => {});
    this.onState = options.onState || (() => {});
    this.log = options.log || (() => {});
    this.random = options.random || Math.random;
    this.baseDelay = options.baseDelay || 1000;
    this.maxDelay = options.maxDelay || 30000;
    this.ws = null;
    this.timer = null;
    this.stopped = true;
    this.attempt = 0;
  }

  start() {
    if (!this.stopped) return;
    this.stopped = false;
    this.connect();
  }

  stop() {
    this.stopped = true;
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    const ws = this.ws;
    this.ws = null;
    if (ws) {
      try { ws.removeAllListeners(); ws.close(); } catch (e) {}
    }
    this.onState(false);
  }

  connect() {
    if (this.stopped || this.ws) return;
    let ws;
    try {
      ws = new this.WebSocket(this.url, {
        headers: { Authorization: `Bearer ${this.apiKey}` },
        handshakeTimeout: 10000,
        maxPayload: 1024,
      });
    } catch (e) {
      this.scheduleReconnect();
      return;
    }
    this.ws = ws;
    ws.once('open', () => {
      if (this.ws !== ws || this.stopped) return;
      this.attempt = 0;
      this.onState(true);
    });
    ws.on('message', (data) => {
      if (this.ws !== ws || this.stopped) return;
      try {
        const message = JSON.parse(String(data));
        if (message && message.type === 'wake') this.onWake(message);
      } catch (e) {
        // Ignore malformed gateway messages; the connection remains usable.
      }
    });
    const disconnected = () => {
      if (this.ws !== ws) return;
      this.ws = null;
      this.onState(false);
      this.scheduleReconnect();
    };
    ws.once('close', disconnected);
    ws.once('error', () => {}); // close performs reconnect; never leak EventEmitter errors.
  }

  scheduleReconnect() {
    if (this.stopped || this.timer) return;
    const cap = Math.min(this.maxDelay, this.baseDelay * (2 ** this.attempt++));
    const delay = Math.floor(cap * (0.5 + this.random()));
    this.timer = setTimeout(() => {
      this.timer = null;
      this.connect();
    }, delay);
  }
}

function realtimeUrl(serverUrl, deviceUid) {
  const url = new URL(serverUrl);
  url.protocol = url.protocol === 'https:' ? 'wss:' : 'ws:';
  url.pathname = '/agent-realtime';
  url.search = '';
  url.searchParams.set('device_uid', deviceUid);
  return url.toString();
}

module.exports = { RealtimeWakeClient, realtimeUrl };