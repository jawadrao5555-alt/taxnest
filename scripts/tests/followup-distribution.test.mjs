import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, mkdtempSync, writeFileSync, rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';

test('actual owner release predicate skips test-only but retains runtime, dependency and workflow changes', () => {
  const script = readFileSync('scripts/owner-merge-and-deploy.sh', 'utf8');
  const predicate = script.match(/AGENT_RELEASE_NEEDED=false\n([\s\S]*?)\nfi/)[0];
  const dir = mkdtempSync(join(tmpdir(), 'release-predicate-'));
  try {
    for (const [paths, expected] of [
      ['pra-agent/test/unit.test.js', 'false'], ['', 'false'],
      ['app/Models/Company.php', 'false'], ['pra-agent/main.js', 'true'],
      ['pra-agent/package-lock.json', 'true'], ['.github/workflows/build-agent.yml', 'true'],
      ['pra-agent/test/test.js\npra-agent/package.json', 'true'],
      [Array(10000).fill('pra-agent/test/test.js').join('\n')+'\npra-agent/main.js', 'true'],
    ]) {
      writeFileSync(join(dir, 'pr-files.txt'), paths+'\n');
      const r = spawnSync('bash', ['-c', `set -euo pipefail\n${predicate}\nprintf '%s' "$AGENT_RELEASE_NEEDED"`], {env:{...process.env,TMP:dir},encoding:'utf8'});
      assert.equal(r.status, 0, r.stderr); assert.equal(r.stdout, expected);
    }
  } finally {rmSync(dir,{recursive:true,force:true});}
});

test('download source keeps independent gated APKs and safe Caller ID advice', () => {
  const view = readFileSync('resources/views/downloads.blade.php','utf8');
  assert.match(view, /fbrPosApkVersion !== '' && is_file\(\$fbrPosApkPath\)/);
  assert.match(view, /@if\(\$fbrPosApkVisible\)/);
  assert.match(view, /@if\(\$callerPlusApkVisible\)\s*<div id="caller-whatsapp"/);
  assert.match(view, /If Play Protect blocks the installation, keep it on/);
  assert.doesNotMatch(view, /Scan apps with Play Protect|Play Protect band karna|no Play Protect warning|App bilkul theek hai/);
});

test('POS setup keeps Play Protect enabled in every supported language', () => {
  for (const [locale, enabled, support] of [
    ['en', /Keep Google Play Protect enabled/, /contact TaxNest support/],
    ['rur', /Play Protect ON rakhein/, /TaxNest support se rabta/],
    ['ur', /پلے پروٹیکٹ آن رکھیں/, /ٹیکس نیسٹ سپورٹ سے رابطہ/],
  ]) {
    const copy = readFileSync(`lang/${locale}/pos.php`, 'utf8')
      .split('\n').filter(line => /'caller_id_plus_(intro|step[1-4]|warn)'/.test(line)).join('\n');
    assert.match(copy, enabled);
    assert.match(copy, support);
    assert.doesNotMatch(copy, /turn OFF|turn the scan off|Turning Play Protect off|OFF kar dein|scan band karein|بند کر دیں/);
  }
});

test('Rider push is a private refresh hint; resumed refresh coalesces and guards account changes', () => {
  const main = readFileSync('rider-app/app/src/main/java/pk/taxnest/rider/MainActivity.kt','utf8');
  const push = readFileSync('rider-app/app/src/main/java/pk/taxnest/rider/PushService.kt','utf8');
  assert.match(push, /Intent\(ACTION_DELIVERIES_CHANGED\).setPackage\(c.packageName\)/);
  assert.match(main, /ContextCompat.RECEIVER_NOT_EXPORTED/);
  assert.match(main, /if \(refreshInFlight\) \{\s*refreshPending = true/);
  assert.match(main, /applyMePayload\(body, token\)/);
  assert.match(main, /expectedToken\) return@runOnUiThread/);
  assert.match(main, /OutboxDrain.drainBlocking\(this@MainActivity\)\s*refreshMe\(\)/);
});
