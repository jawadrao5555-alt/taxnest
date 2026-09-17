'use strict';

const CANONICAL_ZIP = 'TaxNest-PRA-Agent-Windows.zip';
const PRODUCT = 'taxnest-pra-agent';

function semver(value) {
  const match = String(value || '').trim().replace(/^v/i, '').match(/^(\d{1,2})\.(\d+)\.(\d+)$/);
  return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : null;
}

function compare(left, right) {
  const a = semver(left);
  const b = semver(right);
  if (!a || !b) return null;
  for (let i = 0; i < 3; i += 1) {
    if (a[i] !== b[i]) return a[i] > b[i] ? 1 : -1;
  }
  return 0;
}

/**
 * The server already validates its release inventory. Validate it again at
 * the executable boundary before bytes are downloaded or swapped: a partial
 * deploy, a stale server cache, or a forged heartbeat must not create a fake
 * "successful" self-update.
 */
function validateUpdateInfo(info, currentVersion) {
  if (!info || typeof info !== 'object'
      || info.product !== PRODUCT
      || !semver(info.version)
      || info.asset_name !== CANONICAL_ZIP
      || !/^[a-f0-9]{64}$/i.test(String(info.zip_sha256 || ''))
      || !/^[a-f0-9]{40}$/i.test(String(info.source_sha || ''))
      || !/^[a-f0-9]{40}$/i.test(String(info.build_sha || ''))
      // A valid-looking pair of different commits is not exact-source
      // provenance. The canonical workflow sets both to its checked-out
      // owner-approved target, and the updater requires that invariant.
      || String(info.source_sha).toLowerCase() !== String(info.build_sha).toLowerCase()
      || !Number.isSafeInteger(info.zip_size) || info.zip_size <= 0
      || !semver(info.min_agent_version) || !semver(info.max_agent_version)) {
    return { ok: false, code: 'release_manifest_invalid' };
  }
  const rangeOrder = compare(info.min_agent_version, info.max_agent_version);
  const currentMin = compare(currentVersion, info.min_agent_version);
  const currentMax = compare(currentVersion, info.max_agent_version);
  if (rangeOrder === null || rangeOrder > 0 || currentMin === null || currentMax === null
      || currentMin < 0 || currentMax > 0) {
    return { ok: false, code: 'release_manifest_incompatible' };
  }
  return { ok: true };
}

module.exports = { CANONICAL_ZIP, PRODUCT, compare, validateUpdateInfo };