const fs = require('fs');

function createBuildInfo(version, now = new Date()) {
  if (!/^\d+\.\d+\.\d+$/.test(version) || !Number.isFinite(now.getTime())) {
    throw new Error('Valid Agent version and packaging date required');
  }
  return { schema_version: 1, version, built_at: now.toISOString() };
}

function parseBuildInfo(raw, version) {
  const unavailable = { version, build: 'unknown', builtAt: null };
  try {
    const info = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (info.schema_version !== 1 || info.version !== version || typeof info.built_at !== 'string') return unavailable;
    const date = new Date(info.built_at);
    if (!Number.isFinite(date.getTime()) || date.toISOString() !== info.built_at) return unavailable;
    const build = info.built_at.slice(0, 10).replaceAll('-', '') + '-' + info.built_at.slice(11, 19).replaceAll(':', '');
    return { version, build, builtAt: info.built_at };
  } catch { return unavailable; }
}

function loadBuildInfo(version, filename) {
  try { return parseBuildInfo(fs.readFileSync(filename, 'utf8'), version); }
  catch { return parseBuildInfo(null, version); }
}

module.exports = { createBuildInfo, parseBuildInfo, loadBuildInfo };
