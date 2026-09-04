const DEFAULT_SERVER_URL = 'https://taxnest.pk/api/agent';

const LEGACY_TAXNEST_HOSTS = new Set([
  'taxnest.com.pk',
  'www.taxnest.com.pk',
]);

function canonicalAgentServerUrl(value) {
  const raw = String(value || '').trim();
  if (!raw) return raw;

  try {
    const parsed = new URL(raw);
    if (
      (parsed.protocol === 'https:' || parsed.protocol === 'http:') &&
      LEGACY_TAXNEST_HOSTS.has(parsed.hostname.toLowerCase())
    ) {
      return DEFAULT_SERVER_URL;
    }
  } catch (_error) {
    // Preserve malformed/custom values so the normal configuration UI can
    // surface and repair them; this migration only owns known TaxNest hosts.
  }

  return raw;
}

function migrateAgentConfig(config) {
  if (!config || typeof config !== 'object') {
    return { config, changed: false };
  }

  const serverUrl = canonicalAgentServerUrl(config.serverUrl);
  if (!serverUrl || serverUrl === config.serverUrl) {
    return { config, changed: false };
  }

  return {
    config: { ...config, serverUrl },
    changed: true,
  };
}

module.exports = {
  DEFAULT_SERVER_URL,
  canonicalAgentServerUrl,
  migrateAgentConfig,
};