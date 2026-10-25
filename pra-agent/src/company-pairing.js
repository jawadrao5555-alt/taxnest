'use strict';

const crypto = require('node:crypto');

/** Uses the shell's authenticated company session, never an installer-embedded key. */
async function companyPairing({ session, origin, panel = 'pos', existing, confirm, isCurrent = () => true }) {
  if (!['pos', 'fbrpos'].includes(panel)) throw new Error('Unsupported company panel');
  const url = new URL(origin);
  if (url.origin !== origin || url.username || url.password ||
      (url.protocol !== 'https:' && !(url.protocol === 'http:' && ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname)))) {
    throw new Error('Pairing requires a secure origin');
  }
  const path = panel === 'pos' ? '/pos' : '/fbr-pos';
  const endpoint = origin + path + '/desktop/agent-pairing';
  const response = await session.fetch(endpoint, {
    headers: { Accept: 'application/json' }, credentials: 'include', redirect: 'error', cache: 'no-store',
  });
  if (!response.ok) return { ok: false };
  const preview = await response.json();
  if (!isCurrent() || !preview || preview.success !== true ||
      !/^[1-9]\d*$/.test(String(preview.company_id)) ||
      typeof preview.company_name !== 'string' || !preview.company_name.trim() ||
      !/^[A-Za-z0-9]{64}$/.test(preview.nonce || '') || !preview.csrf_token) return { ok: false };

  const sameCompany = existing && String(existing.companyId) === String(preview.company_id);
  const digest = crypto.createHash('sha256').update(String(existing?.apiKey || '')).digest('hex');
  if (sameCompany && existing.serverUrl === origin + '/api/agent' && existing.apiKey &&
      preview.agent_enabled === true && digest === preview.credential_digest) {
    return { ok: true, unchanged: true };
  }
  // The dialog sees only company identity. Tokens/credentials never enter UI/logs/URLs.
  const accepted = await confirm({ companyId: preview.company_id, companyName: preview.company_name,
    switching: !!(existing?.companyId && !sameCompany) });
  if (!isCurrent()) return { ok: false };
  if (!accepted) return { ok: true, cancelled: true };
  const result = await session.fetch(endpoint, {
    method: 'POST', credentials: 'include', redirect: 'error', cache: 'no-store',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': preview.csrf_token },
    body: JSON.stringify({ nonce: preview.nonce, company_id: preview.company_id }),
  });
  if (!result.ok) return { ok: false };
  const data = await result.json();
  if (!isCurrent() || !data || data.success !== true ||
      String(data.company_id) !== String(preview.company_id) ||
      data.server_url !== origin + '/api/agent' || typeof data.api_key !== 'string' || !data.api_key) {
    return { ok: false };
  }
  // Keep saved printer/device and all other preferences on a same-company refresh.
  return { ok: true, config: { ...(existing || {}), serverUrl: data.server_url,
    apiKey: data.api_key, companyId: data.company_id }, switching: !!(existing?.companyId && !sameCompany) };
}

module.exports = { companyPairing };
