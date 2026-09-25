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

const LOCKS = [
  {
    lock: 'package-lock.json',
    packages: 249,
    resolved: 242,
    graph: 'ad57413e5eee38427f5921996f271f473f1a2e50778a1a8782d920e9f90e986d',
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
    graph: '78ac9805108bd028718895e4b599fe910e890ded7dc5233d9aca317dc811e2d5',
  },
  {
    lock: 'pra-agent/package-lock.json',
    packages: 356,
    resolved: 355,
    graph: '3e06894662673ec6a5971bd4f3ff18b268ae1588cfc03acd330f0ff75d3ca9ea',
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
  const privateEntries = resolvedEntries.filter(([, pkg]) => {
    try {
      const url = new URL(pkg.resolved);
      return url.protocol !== 'https:' || url.host !== 'registry.npmjs.org';
    } catch {
      return true;
    }
  });
  const invalidIntegrity = resolvedEntries.filter(
    ([, pkg]) => typeof pkg.integrity !== 'string' || !SRI.test(pkg.integrity),
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
    `${expected.lock}: ${entries.length} packages, ${resolvedEntries.length} public resolved URLs, graph ${actualGraph}`,
  );
}

if (errors.length) {
  console.error('\nNPM LOCK PORTABILITY GUARD FAILED');
  for (const error of errors) console.error(`- ${error}`);
  process.exit(1);
}

console.log('NPM LOCK PORTABILITY GUARD PASSED');
