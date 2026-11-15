#!/usr/bin/env bash
# Semantic check for the active, byte-identical fail-closed PR DAG.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if ! node -e "require.resolve('js-yaml')" >/dev/null 2>&1; then
  echo 'rc-ci-gates-check: js-yaml is locked but node_modules is absent; run npm ci before this static check.' >&2
  exit 2
fi
node - "$root/.github/workflows/pr-checks.yml" "$root/docs/release-candidate/proposed-workflows/pr-checks.yml" <<'NODE'
const fs=require('fs'), crypto=require('crypto'), path=require('path'), yaml=require('js-yaml');
const [active, proposal]=process.argv.slice(2);
const activeBytes=fs.readFileSync(active), proposalBytes=fs.readFileSync(proposal);
const buildActive=path.join(path.dirname(active), 'build-agent.yml');
const buildProposal=path.join(path.dirname(proposal), 'build-agent.yml');
const buildActiveBytes=fs.readFileSync(buildActive), buildProposalBytes=fs.readFileSync(buildProposal);
if(!buildActiveBytes.equals(buildProposalBytes))throw Error('active and proposed Build PRA Agent workflows must be byte-identical');
const expected=['workflow-safety','dependency-audit-build','fullphpunit','native-mariadb-di','browserdesktopmobile','jsagentrealtime','manifest-provenance'];
const mandatoryCommands={
  'workflow-safety':['rc-ci-gates-check.sh','npm-lock-portability-test.sh','ci-sanitize-evidence-check.sh','rc-failure-observability-check.sh','synthetic-pixels-check.mjs','synthetic-pixels-check.py'],
  'dependency-audit-build':['rc-recertify.sh --all-locks'],
  fullphpunit:['rc-safe-run -- vendor/bin/phpunit','rc-safe-run -- python3 scripts/tests/elaan-native-sql-check.py'],
  'native-mariadb-di':['ci-mariadb-provision.sh install','ci-mariadb-provision.sh verify','rc-mariadb-migration-lab.sh --all','rc-safe-run -- python3 scripts/tests/elaan-native-sql-check.py --mariadb'],
  browserdesktopmobile:['ci-mariadb-provision.sh install','ci-mariadb-provision.sh verify','rc-browser-fixture.sh run','rc-playwright-install.sh','local-browser-discovery-check.mjs'],
  jsagentrealtime:['rc-safe-run -- node --test'],
  'manifest-provenance':['AgentReleaseManifestTest.php']
};
function contract(doc,label){
  const jobs=doc.jobs||{};
  for(const n of expected)if(!jobs[n])throw Error(`${label}: missing mandatory job ${n}`);
  if(jobs.validate?.if!=='always()')throw Error(`${label}: validate must use if: always()`);
  if(JSON.stringify(jobs.validate.needs)!==JSON.stringify(expected))throw Error(`${label}: validate needs must be exact and ordered`);
  const validateRun=jobs.validate.steps?.[0]?.run||'';
  for(const n of expected)if(!validateRun.includes(`needs.${n}.result`)||!validateRun.includes('success'))throw Error(`${label}: validate does not require ${n} success`);
  for(const [name,needles] of Object.entries(mandatoryCommands)){
    const steps=jobs[name].steps||[];
    const text=steps.map(s=>s.run||'').join('\n');
    for(const needle of needles)if(!text.includes(needle))throw Error(`${label}: ${name} lacks mandatory command: ${needle}`);
    const artifacts=steps.filter(s=>s.uses==='actions/upload-artifact@v4');
    const artifact=artifacts.find(s=>s.name==='Upload sanitized CI evidence');
    for(const extra of artifacts.filter(s=>s!==artifact)) {
      if(extra.name==='Upload verified synthetic screenshots for visual review') {
        const gates=steps.filter(s=>s.id==='synthetic_pixels');
        const gate=gates[0];
        const commands=[
          'mkdir -p "$RUNNER_TEMP/taxnest-synthetic-pixels"',
          'python3 scripts/lib/verify-synthetic-pixels.py "$RUNNER_TEMP/taxnest-synthetic-pixels/$GITHUB_JOB"',
          'test -f "$RUNNER_TEMP/taxnest-synthetic-pixels/$GITHUB_JOB/VERIFIED_SYNTHETIC_PIXELS"'
        ];
        if(name!=='browserdesktopmobile'||jobs[name].env?.RC_SYNTHETIC_PIXEL_EVIDENCE!=='1'
          ||gates.length!==1||gate.if!=='always()'||gate['continue-on-error']
          ||JSON.stringify(String(gate.run||'').trim().split('\n'))!==JSON.stringify(commands)
          ||steps.indexOf(gate)>=steps.indexOf(extra)
          ||extra.if!=="always() && steps.synthetic_pixels.outcome == 'success'"
          ||extra.with?.name!=='synthetic-visual-review-${{ github.run_id }}'
          ||extra.with?.path!=='${{ runner.temp }}/taxnest-synthetic-pixels/${{ github.job }}'
          ||extra.with?.['if-no-files-found']!=='error'||extra.with?.['retention-days']!==14
          ||extra['continue-on-error'])throw Error(`${label}: invalid guarded synthetic pixel artifact`);
        continue;
      }
      const paths=String(extra.with?.path||'').trim().split('\n');
      const allowed=['${{ steps.browser-preflight.outputs.preview_dir }}/*.webm','${{ steps.browser-preflight.outputs.preview_dir }}/recording-manifest.json'];
      const preflight=steps.find(s=>s.id==='browser-preflight');
      if(name!=='browserdesktopmobile'||extra.name!=='Upload synthetic Top Selling recording preview'
        ||extra.if!=='success()'||extra.with?.name!=='tutorial-preview-${{ github.run_id }}'
        ||JSON.stringify(paths)!==JSON.stringify(allowed)||extra.with?.['if-no-files-found']!=='error'
        ||!String(preflight?.run||'').includes('echo "preview_dir=$(realpath "$RUNNER_TEMP/tutorial-preview")" >> "$GITHUB_OUTPUT"')
        ||!text.includes('RC_TUTORIAL_RECORDING=1 RC_BROWSER_ONLY=restaurant-top-selling')
        ||!text.includes('test -s "$RC_BROWSER_STATE_ROOT/safe-runtime/browser-state/tutorial-videos/recording-manifest.json"')) {
        throw Error(`${label}: ${name} has an unapproved extra artifact upload`);
      }
    }
    if(!artifact||artifact.if!=="always() && steps.sanitize_evidence.outcome == 'success'")throw Error(`${label}: ${name} must upload only after sanitizer success`);
    const sanitize=steps.find(s=>String(s.name||'').toLowerCase().includes('sanitize ci evidence'));
    if(!sanitize||sanitize.if!=='always()'||!String(sanitize.run||'').includes('ci-sanitize-evidence.sh'))throw Error(`${label}: ${name} lacks the sanitizer collector`);
    if(text.includes('npm ci')&&!text.includes('npm-bootstrap-pinned.sh'))throw Error(`${label}: ${name} invokes npm ci without the pinned helper`);
  }
  if((jobs.browserdesktopmobile.steps||[]).filter(s=>s.name==='Upload verified synthetic screenshots for visual review').length!==1)throw Error(`${label}: one guarded synthetic pixel upload required`);
  const native=jobs['native-mariadb-di'];
  const browser=jobs.browserdesktopmobile;
  const erp=(browser.steps||[]).find(s=>s.name==='Verify dedicated Hospital ERP demo role access at desktop and 390px');
  if(!erp || erp.if || erp['continue-on-error'] || erp.run!=='bash scripts/check-erp-demo-browser.sh "$RC_BROWSER_STATE_ROOT/playwright-cache"'
    || erp.env?.RC_BROWSER_STATE_ROOT!=='/tmp/taxnest-rc-browser-${{ github.run_id }}-${{ github.job }}')throw Error(`${label}: dedicated ERP role acceptance must remain mandatory and isolated`);

  for (const [mode, prefix] of [['upgrade', 'upgrade_'], ['reviewed-callback', 'callback_']]) {
    const steps=native.steps||[];
    const index=steps.findIndex(s=>String(s.run||'').endsWith(`rc-mariadb-migration-lab.sh --${mode}`));
    const step=steps[index];
    const allIndex=steps.findIndex(s=>String(s.run||'').endsWith('rc-mariadb-migration-lab.sh --all'));
    if(!step || step.if!=='always()' || step['continue-on-error'] || index<=allIndex
      || step.env?.RC_MARIADB_RUN_ID!==prefix+'${{ github.run_id }}_${{ github.run_attempt }}') {
      throw Error(`${label}: native ${mode} must run independently, mandatorily, after --all with its own disposable run ID`);
    }
  }

  for(const [name,job] of [['native-mariadb-di',native],['browserdesktopmobile',browser]]){
    const image=job.container?.image||'';
    if(!/^mariadb:10\.6\.23-jammy@sha256:[0-9a-f]{64}$/.test(image))throw Error(`${label}: ${name} must pin MariaDB 10.6.23 jammy by digest`);
    if(job.container?.options!=='--user root')throw Error(`${label}: ${name} must run the container as root for targeted bootstrap ownership`);
    const bootstrap=(job.steps||[]).find(s=>s.name==='Bootstrap pinned MariaDB container before checkout');
    if(bootstrap?.shell!=='bash')throw Error(`${label}: ${name} pre-checkout bootstrap must explicitly use shell: bash`);
    const checkout=(job.steps||[]).find(s=>s.uses==='actions/checkout@v7');
    if(checkout?.with?.['set-safe-directory']!==false)throw Error(`${label}: ${name} must not use a global safe.directory bypass`);
  }
  for(const name of ['workflow-safety','dependency-audit-build','browserdesktopmobile','jsagentrealtime','manifest-provenance']){
    const setup=(jobs[name].steps||[]).find(s=>s.uses==='actions/setup-node@v4');
    if(setup?.with?.['node-version']!=='22.23.2')throw Error(`${label}: ${name} must retain current Node 22.23.2`);
  }
  return {
    jobs:expected,
    needs:jobs.validate.needs,
    validateIf:jobs.validate.if,
    containers:[native.container.image,browser.container.image],
    nodeVersion:'22.23.2'
  };
}
const activeDoc=yaml.load(activeBytes.toString('utf8'));
const proposalDoc=yaml.load(proposalBytes.toString('utf8'));
const activeContract=contract(activeDoc,'active');
const proposalContract=contract(proposalDoc,'proposed');
for (const mode of ['upgrade','reviewed-callback']) {
  for (const change of ['remove','optional','conditional','shared-root']) {
    const mutated=structuredClone(activeDoc);
    const steps=mutated.jobs['native-mariadb-di'].steps;
    const index=steps.findIndex(s=>String(s.run||'').endsWith(`rc-mariadb-migration-lab.sh --${mode}`));
    if(change==='remove')steps.splice(index,1);
    if(change==='optional')steps[index]['continue-on-error']=true;
    if(change==='conditional')steps[index].if='success()';
    if(change==='shared-root')delete steps[index].env.RC_MARIADB_RUN_ID;
    let rejected=false;
    try { contract(mutated, `negative-${mode}-${change}`); } catch { rejected=true; }
    if(!rejected)throw Error(`Validator accepted unsafe native ${mode} ${change}`);
  }
}
for(const change of ['remove-upload','condition','path','name','missing-file','optional-verifier','missing-verifier','weaken-verifier','disabled-capture','extra-upload']) {
 const mutated=structuredClone(activeDoc), job=mutated.jobs.browserdesktopmobile, steps=job.steps;
 const upload=steps.find(s=>s.name==='Upload verified synthetic screenshots for visual review'), gate=steps.find(s=>s.id==='synthetic_pixels');
 if(change==='remove-upload')steps.splice(steps.indexOf(upload),1);
 if(change==='condition')upload.if='always()';
 if(change==='path')upload.with.path='${{ github.workspace }}/.local';
 if(change==='name')upload.with.name='arbitrary';
 if(change==='missing-file')upload.with['if-no-files-found']='warn';
 if(change==='optional-verifier')gate['continue-on-error']=true;
 if(change==='missing-verifier')steps.splice(steps.indexOf(gate),1);
 if(change==='weaken-verifier')gate.run='true';
 if(change==='disabled-capture')delete job.env.RC_SYNTHETIC_PIXEL_EVIDENCE;
 if(change==='extra-upload')steps.push({uses:'actions/upload-artifact@v4',with:{path:'.local'}});
 let rejected=false;try{contract(mutated,`negative-pixels-${change}`);}catch{rejected=true;}
 if(!rejected)throw Error(`Validator accepted unsafe synthetic pixel ${change}`);
}
console.log('PASS: ten negative synthetic pixel gate mutations rejected');
console.log('PASS: eight negative native preservation/callback gate mutations rejected');

if(JSON.stringify(activeContract)!==JSON.stringify(proposalContract))throw Error('active and proposed PR workflows must have equivalent mandatory DAG/container contracts');
const sha256=b=>crypto.createHash('sha256').update(b).digest('hex');
console.log(`PASS: active/proposed workflows have equivalent runnable fail-closed contracts; active_sha256=${sha256(activeBytes)} proposed_sha256=${sha256(proposalBytes)} build_agent_sha256=${sha256(buildActiveBytes)}`);
NODE
