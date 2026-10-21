import {test} from 'node:test';
import assert from 'node:assert/strict';
import {mkdtempSync, mkdirSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {betaManifest} from '../android-beta-manifest.mjs';

test('beta inventory binds all three bytes and distinct source versions without claiming phone QA', () => {
  const root = mkdtempSync(join(tmpdir(), 'android-manifest-'));
  try {
    for (const [module, code] of [['rider-app', 14], ['caller-app', 10]]) {
      mkdirSync(join(root, module, 'app'), {recursive: true});
      writeFileSync(join(root, module, 'app/build.gradle'), `versionName "1.8.0"\nversionCode ${code}\n`);
    }
    writeFileSync(join(root, 'rider-app/app/google-services.json'), '{"synthetic":true}');
    for (const name of ['taxnest-rider.apk', 'taxnest-caller.apk', 'taxnest-caller-plus.apk']) writeFileSync(join(root, name), name);
    const first = betaManifest('a'.repeat(40), root, root);
    assert.equal(first.rollout_status, 'OWNER_PHONE_TEST_REQUIRED');
    assert.deepEqual(first.artifacts.map(x => x.version_code), [14, 10, 10]);
    writeFileSync(join(root, 'taxnest-caller.apk'), 'different bytes');
    assert.notEqual(first.artifacts[1].sha256, betaManifest('a'.repeat(40), root, root).artifacts[1].sha256);
    assert.throws(() => betaManifest('main', root, root), /SHA/);
    rmSync(join(root, 'taxnest-rider.apk'));
    assert.throws(() => betaManifest('a'.repeat(40), root, root));
  } finally { rmSync(root, {recursive: true, force: true}); }
});
