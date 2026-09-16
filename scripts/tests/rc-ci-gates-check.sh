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
if(!activeBytes.equals(proposalBytes))throw Error('active and proposed PR workflows must be byte-identical');
const buildActive=path.join(path.dirname(active), 'build-agent.yml');
const buildProposal=path.join(path.dirname(proposal), 'build-agent.yml');
const buildActiveBytes=fs.readFileSync(buildActive), buildProposalBytes=fs.readFileSync(buildProposal);
if(!buildActiveBytes.equals(buildProposalBytes))throw Error('active and proposed Build PRA Agent workflows must be byte-identical');
const doc=yaml.load(activeBytes.toString('utf8'));
const jobs=doc.jobs||{};
const expected=['workflow-safety','dependency-audit-build','fullphpunit','native-mariadb-di','browserdesktopmobile','jsagentrealtime','manifest-provenance'];
for(const n of expected)if(!jobs[n])throw Error(`missing mandatory job ${n}`);
if(jobs.validate?.if!=='always()')throw Error('validate must use if: always()');
if(JSON.stringify(jobs.validate.needs)!==JSON.stringify(expected))throw Error('validate needs must be exact and ordered');
const run=jobs.validate.steps?.[0]?.run||'';
for(const n of expected)if(!run.includes(`needs.${n}.result`)||!run.includes('success'))throw Error(`validate does not require ${n} success`);
const jobText=name=>(jobs[name].steps||[]).map(s=>s.run||'').join('\n');
for(const [name, needle] of Object.entries({
  'workflow-safety':'rc-ci-gates-check.sh',
  'dependency-audit-build':'rc-recertify.sh --all-locks',
  fullphpunit:'rc-safe-run -- vendor/bin/phpunit',
  'native-mariadb-di':'rc-mariadb-migration-lab.sh --all',
  browserdesktopmobile:'rc-browser-fixture.sh run',
  jsagentrealtime:'rc-safe-run -- node --test',
  'manifest-provenance':'AgentReleaseManifestTest.php',
}))if(!jobText(name).includes(needle))throw Error(`${name} lacks mandatory command: ${needle}`);
if(!jobText('native-mariadb-di').includes("MariaDB.*10\\.6\\."))throw Error('native MariaDB lane must require MariaDB 10.6');
if(!jobText('browserdesktopmobile').includes('npx playwright install chromium'))throw Error('browser lane must install Chromium');
const sha256=b=>crypto.createHash('sha256').update(b).digest('hex');
console.log(`PASS: active/proposed workflows are byte-identical, runnable, and fail-closed; pr_sha256=${sha256(activeBytes)} build_agent_sha256=${sha256(buildActiveBytes)}`);
NODE