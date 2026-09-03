'use strict';

const http = require('node:http');
const { WebSocketServer, WebSocket } = require('ws');

const MAX_CONNECTIONS = 1000;
const MAX_WAKE_BYTES = 2048;
const RATE_WINDOW_MS = 60_000;
const MAX_UPGRADES_PER_WINDOW = 60;
const MAX_GLOBAL_UPGRADES_PER_WINDOW = 3000;
const MAX_RATE_LIMIT_ENTRIES = 10_000;
const MAX_PENDING_AUTH = 100;

function bearerOrAgentKey(headers) {
  const authorization = headers.authorization;
  if (typeof authorization === 'string' && /^Bearer\s+\S+$/i.test(authorization)) {
    return { Authorization: authorization };
  }
  const agentKey = headers['x-agent-key'];
  if (typeof agentKey === 'string' && agentKey.trim()) return { 'X-Agent-Key': agentKey };
  return null;
}

// Laravel may serialize an integer id as a string. Accept only its canonical
// decimal representation so values such as 01, 1e0, whitespace, or numbers
// outside JavaScript's safe range cannot select an unintended company.
function normalizeCompanyId(value) {
  if (typeof value === 'number') return Number.isSafeInteger(value) && value > 0 ? value : null;
  if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) return null;
  const normalized = Number(value);
  return Number.isSafeInteger(normalized) ? normalized : null;
}

function responseJson(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, { 'content-type': 'application/json', 'content-length': Buffer.byteLength(body) });
  res.end(body);
}

function createGateway(options = {}) {
  const config = {
    host: '127.0.0.1',
    port: Number(options.port || process.env.AGENT_REALTIME_PORT || 6101),
    authUrl: options.authUrl || process.env.LARAVEL_REALTIME_AUTH_URL || 'http://127.0.0.1/api/agent/realtime-auth',
    wakeSecret: options.wakeSecret || process.env.WAKE_SECRET || '',
    authenticate: options.authenticate || null,
    logger: options.logger || (() => {}),
    pingIntervalMs: options.pingIntervalMs || 30_000,
    idleTimeoutMs: options.idleTimeoutMs || 75_000,
    maxPendingAuth: options.maxPendingAuth || MAX_PENDING_AUTH,
    rateWindowMs: options.rateWindowMs || RATE_WINDOW_MS,
    maxUpgradesPerWindow: options.maxUpgradesPerWindow || MAX_UPGRADES_PER_WINDOW,
    maxGlobalUpgradesPerWindow: options.maxGlobalUpgradesPerWindow || MAX_GLOBAL_UPGRADES_PER_WINDOW,
    maxRateLimitEntries: options.maxRateLimitEntries || MAX_RATE_LIMIT_ENTRIES,
  };
  const sockets = new Set();
  const metrics = { upgrades: 0, authFailures: 0, wakeRequests: 0, wakeBroadcasts: 0, rateLimited: 0 };
  const attempts = new Map();
  let globalWindow = { startedAt: Date.now(), count: 0 };
  let pendingAuth = 0;

  function pruneAttempts(now = Date.now()) {
    for (const [ip, window] of attempts) {
      if (now - window.startedAt >= config.rateWindowMs) attempts.delete(ip);
    }
    metrics.rateLimitEntries = attempts.size;
  }

  function clientIp(request) {
    const forwarded = String(request.headers['x-forwarded-for'] || '')
      .split(',').map((part) => part.trim()).filter(Boolean);
    // Apache appends the actual peer address to any client-provided chain.
    return forwarded.at(-1) || request.socket.remoteAddress || 'unknown';
  }

  async function authenticate(request, deviceUid, forwardedAuth) {
    if (config.authenticate) return config.authenticate({ request, deviceUid, headers: forwardedAuth });
    const url = new URL(config.authUrl);
    url.searchParams.set('device_uid', deviceUid);
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 5000);
    try {
      const result = await fetch(url, { headers: forwardedAuth, signal: controller.signal });
      if (!result.ok) return null;
      return await result.json();
    } catch (e) {
      return null;
    } finally {
      clearTimeout(timer);
    }
  }

  const server = http.createServer((req, res) => {
    const pathname = new URL(req.url, 'http://localhost').pathname;
    if (req.method === 'GET' && pathname === '/health') {
      return responseJson(res, 200, { ok: true, connections: sockets.size, metrics });
    }
    if (req.method !== 'POST' || pathname !== '/internal/wake') return responseJson(res, 404, { error: 'not_found' });
    if (!config.wakeSecret || req.headers['x-wake-secret'] !== config.wakeSecret) return responseJson(res, 401, { error: 'unauthorized' });
    let raw = '';
    req.setEncoding('utf8');
    req.on('data', (chunk) => {
      raw += chunk;
      if (Buffer.byteLength(raw) > MAX_WAKE_BYTES) req.destroy();
    });
    req.on('error', () => responseJson(res, 400, { error: 'invalid_body' }));
    req.on('end', () => {
      let body;
      try { body = JSON.parse(raw); } catch (e) { return responseJson(res, 400, { error: 'invalid_json' }); }
      if (!body || !Number.isInteger(body.company_id) || typeof body.job_id !== 'string' && !Number.isInteger(body.job_id) ||
          (body.device_uid !== null && body.device_uid !== undefined && typeof body.device_uid !== 'string')) {
        return responseJson(res, 422, { error: 'invalid_wake' });
      }
      metrics.wakeRequests += 1;
      let delivered = 0;
      const payload = JSON.stringify({ type: 'wake', job_id: body.job_id, device_uid: body.device_uid || null });
      for (const socket of sockets) {
        if (socket.readyState === WebSocket.OPEN && socket.companyId === body.company_id &&
            (body.device_uid == null || socket.deviceUid === body.device_uid)) {
          socket.send(payload);
          delivered += 1;
        }
      }
      metrics.wakeBroadcasts += delivered;
      responseJson(res, 200, { ok: true, delivered });
    });
  });
  const wss = new WebSocketServer({ noServer: true, maxPayload: 1024, clientTracking: false });
  const pingTimer = setInterval(() => {
    const now = Date.now();
    for (const socket of sockets) {
      if (now - socket.lastSeen > config.idleTimeoutMs) { socket.terminate(); continue; }
      if (socket.readyState === WebSocket.OPEN) socket.ping();
    }
  }, config.pingIntervalMs);
  pingTimer.unref();
  const rateCleanupTimer = setInterval(pruneAttempts, config.rateWindowMs);
  rateCleanupTimer.unref();

  server.on('upgrade', async (request, socket, head) => {
    const url = new URL(request.url, 'http://localhost');
    if (url.pathname !== '/agent-realtime' || sockets.size >= MAX_CONNECTIONS) return socket.destroy();
    const deviceUid = url.searchParams.get('device_uid');
    const auth = bearerOrAgentKey(request.headers);
    const ip = clientIp(request);
    const now = Date.now();
    if (now - globalWindow.startedAt >= config.rateWindowMs) {
      globalWindow = { startedAt: now, count: 0 };
    }
    let ipWindow = attempts.get(ip);
    if (ipWindow && now - ipWindow.startedAt >= config.rateWindowMs) {
      attempts.delete(ip);
      ipWindow = null;
    }
    const newIpAtCapacity = !ipWindow && attempts.size >= config.maxRateLimitEntries;
    const rateLimited = newIpAtCapacity ||
      (ipWindow && ipWindow.count >= config.maxUpgradesPerWindow) ||
      globalWindow.count >= config.maxGlobalUpgradesPerWindow ||
      pendingAuth >= config.maxPendingAuth;
    if (!deviceUid || !auth || rateLimited) {
      metrics.authFailures += 1;
      if (rateLimited) metrics.rateLimited += 1;
      return socket.end('HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n');
    }
    if (!ipWindow) {
      ipWindow = { startedAt: now, count: 0 };
      attempts.set(ip, ipWindow);
      metrics.rateLimitEntries = attempts.size;
    }
    ipWindow.count += 1;
    globalWindow.count += 1;
    pendingAuth += 1;
    let identity;
    try {
      identity = await authenticate(request, deviceUid, auth);
    } finally {
      pendingAuth -= 1;
    }
    const data = identity && (identity.data || identity);
    const companyId = data && normalizeCompanyId(data.company_id);
    if (!data || String(data.device_uid) !== deviceUid || companyId === null) {
      metrics.authFailures += 1;
      return socket.end('HTTP/1.1 401 Unauthorized\r\nContent-Length: 0\r\nConnection: close\r\n\r\n');
    }
    wss.handleUpgrade(request, socket, head, (ws) => {
      ws.companyId = companyId;
      ws.deviceUid = deviceUid;
      ws.lastSeen = Date.now();
      sockets.add(ws); metrics.upgrades += 1;
      ws.on('pong', () => { ws.lastSeen = Date.now(); });
      ws.on('message', () => { ws.lastSeen = Date.now(); }); // no client commands are acted upon
      ws.on('close', () => sockets.delete(ws));
      ws.on('error', () => {});
    });
  });

  return {
    server, sockets, metrics,
    listen: () => new Promise((resolve) => server.listen(config.port, config.host, resolve)),
    close: () => new Promise((resolve) => {
      clearInterval(pingTimer);
      clearInterval(rateCleanupTimer);
      for (const socket of sockets) socket.close(1001, 'shutdown');
      server.close(() => resolve());
    }),
  };
}

if (require.main === module) {
  const gateway = createGateway();
  gateway.listen().then(() => console.log(JSON.stringify({ event: 'listening', host: '127.0.0.1', port: 6101 })));
  const shutdown = () => gateway.close().then(() => process.exit(0));
  process.on('SIGTERM', shutdown); process.on('SIGINT', shutdown);
}

module.exports = { createGateway, bearerOrAgentKey, normalizeCompanyId };