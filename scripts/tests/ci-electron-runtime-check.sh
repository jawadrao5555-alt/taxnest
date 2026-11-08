#!/usr/bin/env bash
# Offline negative acceptance: no network, real runtime, or user data required.
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
python3 - "$ROOT" <<'PY'
import json, os, pathlib, shutil, subprocess, sys, tempfile
with tempfile.TemporaryDirectory(prefix='taxnest-electron-bootstrap-test-') as directory:
    root=pathlib.Path(directory)
    (root/'scripts').mkdir()
    package=root/'pra-agent/node_modules/electron'
    package.mkdir(parents=True)
    shutil.copyfile(pathlib.Path(sys.argv[1])/'scripts/ci-electron-runtime.sh', root/'scripts/ci-electron-runtime.sh')
    (package/'package.json').write_text(json.dumps({'version':'42.11.16'}))
    (package/'install.js').write_text('process.exit(1);')
    binaries=root/'bin'
    binaries.mkdir()
    curl=binaries/'curl'
    curl.write_text('#!/usr/bin/env python3\nimport pathlib,sys\npathlib.Path(sys.argv[sys.argv.index("-o")+1]).write_bytes(b"SYNTHETIC WRONG DIGEST")\n')
    curl.chmod(0o755)
    env={**os.environ,'PATH':str(binaries)+os.pathsep+os.environ['PATH']}
    result=subprocess.run(['bash',str(root/'scripts/ci-electron-runtime.sh')],env=env,capture_output=True,text=True,timeout=15)
    if result.returncode==0 or (package/'dist').exists() or (package/'path.txt').exists():
        raise SystemExit('FAIL: counterfeit runtime was accepted or extracted')
    (package/'package.json').write_text(json.dumps({'version':'0.0.0'}))
    result=subprocess.run(['bash',str(root/'scripts/ci-electron-runtime.sh')],env=env,capture_output=True,text=True,timeout=15)
    if result.returncode!=2 or 'version does not match' not in result.stderr:
        raise SystemExit('FAIL: unreviewed runtime version was accepted')
    shutil.copyfile(pathlib.Path(sys.argv[1])/'scripts/ci-gradle-runtime.sh', root/'scripts/ci-gradle-runtime.sh')
    env.update({'RUNNER_TEMP':str(root),'GITHUB_PATH':str(root/'github-path')})
    result=subprocess.run(['bash',str(root/'scripts/ci-gradle-runtime.sh')],env=env,capture_output=True,text=True,timeout=15)
    if result.returncode==0 or (root/'github-path').exists() or list(root.glob('taxnest-gradle-runtime-*/gradle-8.11.1')):
        raise SystemExit('FAIL: counterfeit Gradle distribution was accepted or added to PATH')
    env['GITHUB_ACTIONS']='false'
    result=subprocess.run(['bash',str(pathlib.Path(sys.argv[1])/'scripts/ci-php84-runtime.sh')],env=env,capture_output=True,text=True,timeout=15)
    if result.returncode!=2 or 'GitHub CI container only' not in result.stderr:
        raise SystemExit('FAIL: PHP preinstallation did not refuse non-CI invocation')
print('PASS: Electron fallback rejects counterfeit bytes before extraction and refuses an unreviewed package version; Gradle rejects counterfeit bytes without updating PATH; PHP preinstall refuses non-CI; no network used')
PY
