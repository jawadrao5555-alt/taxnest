#!/usr/bin/env node
/**
 * Offline guard for the five committed npm lockfiles.
 *
 * The registry-host migration must not alter versions, integrity values, or
 * dependency edges. The graph fingerprint below excludes only `resolved`;
 * every other lockfile field remains covered by the fingerprint.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

// Reviewed 7 Oct 2026: root shell-quote 1.11.0 addresses GHSA-pqg4-j6r4-53mv.
// Reviewed 6 Oct 2026: source-map-js 1.2.2 and root selector-parser 7.1.6
// address GHSA-68fv-2mgg-jv7q / GHSA-rj75-hqrm-r3gf. All other
// graph entries, local-fork hashes and registry/integrity checks stay pinned.
const LOCKS = [
  {
    lock: 'package-lock.json',
    packages: 249,
    resolved: 242,
    graph: '3fa4dad8472f652b66bdfde685d509bd0facde0ae06521559748ece7b09b35c8',
  },
  {
    lock: 'agent-realtime-gateway/package-lock.json',
    packages: 2,
    resolved: 1,
    graph: 'fef81cf1c08874de4284235fe0f366fcccf2ed0de14deb2586e31fdd0f0877c4',
  },
  {
    lock: 'artifacts/mockup-sandbox/package-lock.json',
    packages: 318,
    resolved: 311,
    graph: '59d62e514361d57322d1c6a6462886b584933ce55728755ccfeb6ffb0e72e56f',
  },
  {
    lock: 'pra-agent/package-lock.json',
    packages: 308,
    resolved: 307,
    graph: '5fa2fb233f8b64ac3f613a834b760e1c85210f4d0680d662189292791e4e5cc3',
  },
  {
    lock: 'tools/video-pipeline/package-lock.json',
    packages: 2,
    resolved: 1,
    graph: '6f0c93bd9311f920e9e43075f21bc77b06cbadbd342814dfc8a01ddf567f95a4',
  },
];

const PUBLIC_REGISTRY = 'https://registry.npmjs.org/';
const SRI = /^(?:sha1|sha256|sha384|sha512)-[A-Za-z0-9+/]+={0,2}$/;
const scriptRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const rootFlag = process.argv.indexOf('--root');
const root = path.resolve(
  rootFlag === -1 ? scriptRoot : (process.argv[rootFlag + 1] ?? ''),
);
const errors = [];


// A single explicitly reviewed local security fork is allowed. Every file is
// hash-pinned below; local packages are not silently exempt from validation.
const FORK_FILES = {
  "LICENSE": "35bdd8a44339719441900fb50fbefc5e2dca1ca662cbaed7a687de842c8b70f2",
  "PATCH.md": "0846cca26dbe372499f1445eee2f0eaedfa9e7c4286e83b409d4407b4228ecd5",
  "README.md": "947b0fc3cc12eaaa070207126213fbdf9ab2bf8cd13dcc6e4007b36b79309866",
  "index.js": "332ea07c7b006361aad12aa994ca75dc1db8e8382b884909e2f38f10b85c88a4",
  "lib/compile.js": "925bd3f251d79825fa03b3230678ac2d2fd3291906ff89a8e4eda7616534d7cf",
  "lib/constants.js": "c18ac5adb57308f1ce42a28552da3a31f5d83709743ebd9a636336813a744d4b",
  "lib/depth-guard.js": "aa2a5b699212348a3d6a6903c06ab308d6c925e39358b0a26188ecae40a731e4",
  "lib/expand.js": "7a81ed45b9b873ac19df030a0b2a1aa02c5016425d43c2e049ce4775fa622e3d",
  "lib/parse.js": "7edbc21687b6e031a5cc17b2c38bfc8377320968a573f5e369f58b07a40e5a64",
  "lib/stringify.js": "ab1fa4364cc41ce6c4bdafcf22775b9941653c78c44367b3d2134862afeb2e4c",
  "lib/utils.js": "b5a7596aa67730412b3c029ef09e84e6b67b8e445cffd35d1d295549c89066c7",
  "package.json": "41ae524edddcd03b8289de9cfb73051115fc6830cb1042c69ee7e204caf9e542"
};
function reviewedFork(lockPath, entryPath, pkg) {
  const expected = lockPath === 'package-lock.json'
    ? 'file:tools/npm-patches/braces'
    : lockPath === 'artifacts/mockup-sandbox/package-lock.json'
      ? 'file:../../tools/npm-patches/braces' : undefined;
  return entryPath === 'node_modules/braces' && expected &&
    pkg.resolved === expected && pkg.name === '@taxnest/braces-bounded' &&
    pkg.version === '3.0.3-taxnest.1';
}
const forkRoot = path.join(root, 'tools/npm-patches/braces');
function forkInventory(dir, prefix = '') {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap(entry => {
    const name = prefix + entry.name;
    if (entry.isSymbolicLink()) throw new Error('symlink in reviewed fork');
    return entry.isDirectory() ? forkInventory(path.join(dir, entry.name), name + '/') : [name];
  });
}
try {
  if (JSON.stringify(forkInventory(forkRoot).sort()) !== JSON.stringify(Object.keys(FORK_FILES).sort())) {
    errors.push('reviewed fork source inventory differs');
  }
} catch { errors.push('reviewed fork source inventory unreadable'); }

for (const [file, expected] of Object.entries(FORK_FILES)) {
  try {
    const actual = crypto.createHash('sha256').update(fs.readFileSync(path.join(forkRoot, file))).digest('hex');
    if (actual !== expected) errors.push(`reviewed fork source hash mismatch: ${file}`);
  } catch { errors.push(`reviewed fork source missing: ${file}`); }
}

function withoutResolved(value) {
  if (Array.isArray(value)) return value.map(withoutResolved);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(
    Object.keys(value)
      .filter((key) => key !== 'resolved')
      .sort()
      .map((key) => [key, withoutResolved(value[key])]),
  );
}

function graphFingerprint(lock) {
  return crypto
    .createHash('sha256')
    .update(JSON.stringify(withoutResolved(lock)))
    .digest('hex');
}

function manifestDependencies(manifest) {
  const result = {};
  for (const key of [
    'dependencies',
    'devDependencies',
    'optionalDependencies',
    'peerDependencies',
  ]) {
    if (manifest[key]) result[key] = manifest[key];
  }
  return result;
}

for (const expected of LOCKS) {
  const lockPath = path.join(root, expected.lock);
  const manifestPath = path.join(path.dirname(lockPath), 'package.json');
  let lock;
  let manifest;

  try {
    lock = JSON.parse(fs.readFileSync(lockPath, 'utf8'));
    manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  } catch (error) {
    errors.push(`${expected.lock}: unable to read JSON (${error.message})`);
    continue;
  }

  if (lock.lockfileVersion !== 3) {
    errors.push(`${expected.lock}: expected lockfileVersion 3`);
  }
  if (!lock.packages || typeof lock.packages !== 'object') {
    errors.push(`${expected.lock}: missing packages object`);
    continue;
  }

  const entries = Object.entries(lock.packages);
  const resolvedEntries = entries.filter(([, pkg]) => pkg && pkg.resolved);
  const privateEntries = resolvedEntries.filter(([entryPath, pkg]) => {
    if (reviewedFork(expected.lock, entryPath, pkg)) return false;
    try {
      const url = new URL(pkg.resolved);
      return url.protocol !== 'https:' || url.host !== 'registry.npmjs.org';
    } catch {
      return true;
    }
  });
  const invalidIntegrity = resolvedEntries.filter(
    ([entryPath, pkg]) => !reviewedFork(expected.lock, entryPath, pkg) &&
      (typeof pkg.integrity !== 'string' || !SRI.test(pkg.integrity)),
  );

  if (entries.length !== expected.packages) {
    errors.push(
      `${expected.lock}: expected ${expected.packages} package entries, found ${entries.length}`,
    );
  }
  if (resolvedEntries.length !== expected.resolved) {
    errors.push(
      `${expected.lock}: expected ${expected.resolved} resolved entries, found ${resolvedEntries.length}`,
    );
  }
  if (privateEntries.length) {
    errors.push(
      `${expected.lock}: ${privateEntries.length} resolved URL(s) are not ${PUBLIC_REGISTRY}`,
    );
  }
  if (invalidIntegrity.length) {
    errors.push(`${expected.lock}: resolved package(s) missing valid SRI integrity`);
  }

  const rootPackage = lock.packages[''];
  if (!rootPackage) {
    errors.push(`${expected.lock}: missing root package entry`);
  } else {
    const lockedDependencies = manifestDependencies(rootPackage);
    const declaredDependencies = manifestDependencies(manifest);
    if (JSON.stringify(withoutResolved(lockedDependencies)) !== JSON.stringify(withoutResolved(declaredDependencies))) {
      errors.push(`${expected.lock}: root manifest dependency declarations drifted`);
    }
  }

  const actualGraph = graphFingerprint(lock);
  if (actualGraph !== expected.graph) {
    errors.push(
      `${expected.lock}: version/integrity/dependency graph fingerprint changed (${actualGraph})`,
    );
  }

  console.log(
    `${expected.lock}: ${entries.length} packages, ${resolvedEntries.length} reviewed resolved sources, graph ${actualGraph}`,
  );
}

if (errors.length) {
  console.error('\nNPM LOCK PORTABILITY GUARD FAILED');
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log('NPM LOCK PORTABILITY GUARD PASSED');
