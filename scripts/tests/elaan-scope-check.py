#!/usr/bin/env python3
"""Fictional publication scope and remote gate negative tests; no external access."""
import importlib
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts/lib'))
parser = importlib.import_module('elaan-spec-parse')
sql = importlib.import_module('elaan-scope-sql')
SHA = 'a' * 40


class ScopeTest(unittest.TestCase):
    def test_parser_and_command_line_dry_run_use_same_strict_scope(self):
        for audience, category in [('di', 'di_services'), ('fbr_pos', 'pharmacy'), ('pos', 'restaurant'), ('all', 'salon')]:
            spec = parser.parse_restricted_yaml(f'title: Fictional notice\naudience: {audience}\npoints:\n  - Fictional point\ncategory: {category}\n')
            self.assertEqual(audience, spec['audience'])
            out = subprocess.run(['bash', str(ROOT/'scripts/elaan-insert.sh'), '--dry-run', '--title', 'Fictional notice', '--point', 'Point', '--audience', audience, '--category', category], cwd=ROOT, capture_output=True)
            self.assertEqual(0, out.returncode, out.stderr)
        for tail in ['audience: unknown', 'category: restuarant', 'categories: typo', 'audience: di\ncategory: restaurant', 'audience: pos\naudience: all']:
            with self.assertRaises(ValueError):
                parser.parse_restricted_yaml('title: Notice\npoints:\n  - Point\n' + tail)
        out = subprocess.run(['bash', str(ROOT/'scripts/elaan-insert.sh'), '--dry-run', '--title', 'Notice', '--point', 'Point', '--category', 'restuarant'], cwd=ROOT, capture_output=True)
        self.assertNotEqual(0, out.returncode)

    def test_category_registry_matches_application(self):
        code = 'require "vendor/autoload.php"; echo json_encode([array_keys(App\\Services\\PosFeatureService::allCategoryDefaults()),array_keys(App\\Models\\AppUpdate::DI_SECTORS)]);'
        values = json.loads(subprocess.check_output(['php', '-r', code], cwd=ROOT))
        self.assertEqual(set(values[0]), parser.POS_CATEGORIES)
        self.assertEqual(set(values[1]), parser.DI_CATEGORIES)

    def test_remote_gate_requires_current_receipt_and_exact_scope(self):
        # The SSH substitute executes the real read-only remote script against a
        # fictional env/marker and DB transport. Never selects a real endpoint.
        with tempfile.TemporaryDirectory(prefix='taxnest-elaan-scope-') as directory:
            temp = Path(directory)
            (temp/'.env').write_text('DB_HOST=127.0.0.1\nDB_USERNAME=fictional\nDB_PASSWORD=fictional\nDB_DATABASE=fictional\n')
            (temp/'marker').write_text('1|' + SHA)
            mysql = temp/'php'
            mysql.write_text('''#!/usr/bin/env python3
import os,sys,base64
# Exercise the actual SSH payload/transport without production access.
source=open(sys.argv[-1]).read()
assert 'elaanReceiptCount' in source
q=base64.b64decode(os.environ['SCOPE_B64']).decode()
assert 'audience=CONVERT(0x'+os.environ['AUDIENCE'].encode().hex()+' USING utf8mb4)' in q
assert 'is_published=1' in q and 'target_categories IS NULL' in q
case=os.environ['CASE']
if case in {'db_error','missing_key'}: print('ELAAN_DB_ERROR');sys.exit(1)
if case=='missing_marker': print('ELAAN_NO_MARKER');sys.exit(0)
negative={'wrong_scope','wrong_sha','unpublished','archived','deleted','unrelated'}
fresh=0 if case in negative or case=='same_sha' else 1
existing=0 if case in negative else 1
print('ELAAN_COUNT=%d MARKER_COMMIT=%s RECEIPT_COUNT=%d' % (fresh,'a'*40,existing))
''')
            mysql.chmod(0o700)
            ssh = temp/'ssh'
            ssh.write_text('#!/bin/bash\n[ "$CASE" != ssh_error ] || exit 1\nbash -c "${@: -1}"\n')
            ssh.chmod(0o700)
            for audience in ['pos', 'fbr_pos', 'di', 'all']:
                spec = temp/'spec.yml'
                spec.write_text(f'title: Fictional receipt\naudience: {audience}\npoints:\n  - Point\n')
                for case in ['fresh', 'same_sha', 'wrong_scope', 'wrong_sha', 'unpublished', 'archived', 'deleted', 'unrelated', 'db_error', 'missing_key', 'ssh_error', 'missing_marker', 'invalid_spec']:
                    marker = temp/'marker'
                    if case == 'missing_marker': marker.unlink()
                    else: marker.write_text('1|' + SHA)
                    if case == 'invalid_spec': spec.write_text('title: invalid\naudience: typo\npoints:\n  - Point\n')
                    else: spec.write_text(f'title: Fictional receipt\naudience: {audience}\npoints:\n  - Point\n')
                    script = '''set -uo pipefail
ROOT="$1"; LIVE_DIR="$2"; LIVE_DEPLOY_MARKER="$2/marker"; HOST=fictional; SSH_OPTS=(); LIVE_PHP="$2/php"
fail() { echo "$*" >&2; exit 1; }; step() { :; }
ssh() { [ "$CASE" != ssh_error ] || return 1; bash -c "${@: -1}"; }
export -f ssh
source "$ROOT/scripts/lib/elaan-freshness-check.sh"
elaan_committed_spec_path() { printf '%s/spec.yml' "$LIVE_DIR"; }
elaan_freshness_check "$3" "$3"
'''
                    env = dict(os.environ, PATH=str(temp)+os.pathsep+os.environ['PATH'], CASE=case, AUDIENCE=audience)
                    result = subprocess.run(['bash', '-c', script, 'fixture', str(ROOT), str(temp), SHA], env=env, capture_output=True, timeout=10)
                    self.assertEqual(case in ['fresh', 'same_sha'], result.returncode == 0, (audience, case, result.stdout, result.stderr))

    def test_sql_scope_requires_exact_categories(self):
        value = sql.scope_sql({'audience': 'di', 'categories': ['di_services']})
        self.assertIn('JSON_LENGTH(target_categories)=1', value)
        self.assertIn('JSON_CONTAINS', value)
        self.assertNotIn("audience IN", value)


if __name__ == '__main__':
    unittest.main()
