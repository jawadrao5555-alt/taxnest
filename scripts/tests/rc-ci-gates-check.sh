#!/usr/bin/env bash
# Semantic check for the proposed (not active) fail-closed Phase G DAG.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if ! node -e "require.resolve('js-yaml')" >/dev/null 2>&1; then
  echo 'rc-ci-gates-check: BLOCKED js-yaml is locked but node_modules is absent; run npm ci in a fresh authorized verification job.' >&2
  exit 2
fi
node - "$root/docs/release-candidate/proposed-workflows/pr-checks.yml" <<'NODE'
const fs=require('fs'), yaml=require('js-yaml');
const doc=yaml.load(fs.readFileSync(process.argv[2],'utf8'));
const jobs=doc.jobs||{}, expected=['syntax','fullphpunit','native-mariadb','isolation-fiscal','jsagentrealtime','dependency-audit','browserdesktopmobile','manifestbuild'];
for(const n of expected)if(!jobs[n])throw Error(`missing mandatory job ${n}`);
if(jobs.validate?.if!=='always()')throw Error('validate must use if: always()');
if(JSON.stringify(jobs.validate.needs)!==JSON.stringify(expected))throw Error('validate needs must be exact and ordered');
const run=jobs.validate.steps?.[0]?.run||'';
for(const n of expected)if(!run.includes(`needs.${n}.result`)||!run.includes('success'))throw Error(`validate does not require ${n} success`);
console.log('PASS: proposed Phase G DAG is fail-closed; recovery gates intentionally remain BLOCKED');
NODE