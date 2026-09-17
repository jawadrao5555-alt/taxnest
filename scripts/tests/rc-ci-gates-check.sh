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
  'workflow-safety':['rc-ci-gates-check.sh','npm-lock-portability-test.sh','ci-sanitize-evidence-check.sh','rc-failure-observability-check.sh'],
  'dependency-audit-build':['rc-recertify.sh --all-locks'],
  fullphpunit:['rc-safe-run -- vendor/bin/phpunit'],
  'native-mariadb-di':['ci-mariadb-provision.sh install','ci-mariadb-provision.sh verify','rc-mariadb-migration-lab.sh --all'],
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
    const artifact=steps.find(s=>s.uses==='actions/upload-artifact@v4');
    if(!artifact||artifact.if!=="always() && steps.sanitize_evidence.outcome == 'success'")throw Error(`${label}: ${name} must upload only after sanitizer success`);
    const sanitize=steps.find(s=>String(s.name||'').toLowerCase().includes('sanitize ci evidence'));
    if(!sanitize||sanitize.if!=='always()'||!String(sanitize.run||'').includes('ci-sanitize-evidence.sh'))throw Error(`${label}: ${name} lacks the sanitizer collector`);
    if(text.includes('npm ci')&&!text.includes('npm-bootstrap-pinned.sh'))throw Error(`${label}: ${name} invokes npm ci without the pinned helper`);
  }
  const native=jobs['native-mariadb-di'];
  const browser=jobs.browserdesktopmobile;
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
if(JSON.stringify(activeContract)!==JSON.stringify(proposalContract))throw Error('active and proposed PR workflows must have equivalent mandatory DAG/container contracts');
const sha256=b=>crypto.createHash('sha256').update(b).digest('hex');
console.log(`PASS: active/proposed workflows have equivalent runnable fail-closed contracts; active_sha256=${sha256(activeBytes)} proposed_sha256=${sha256(proposalBytes)} build_agent_sha256=${sha256(buildActiveBytes)}`);
NODE