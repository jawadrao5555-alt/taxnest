#!/usr/bin/env python3
"""Exercise the actual shell probe with synthetic HTTP responses; no network."""
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

sys.dont_write_bytecode = True
ROOT = Path(__file__).resolve().parents[2]
SPEC = importlib.util.spec_from_file_location("probe", ROOT / "scripts/lib/ci-live-verify-day-close.py")
PROBE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PROBE)

VALID = """<html><body><form method='GET' action='/pos/day-close'>
<input name='date'></form><input type='checkbox' id='dc-auto-close-chk'></body></html>"""
MISSING = VALID.replace("<input type='checkbox' id='dc-auto-close-chk'>", "")
LOGIN = "<html><form action='/pos/login' method='POST'><input name='password'></form></html>"


class DayCloseProbeTest(unittest.TestCase):
    def run_probe(self, responses, missing_helper=False):
        with tempfile.TemporaryDirectory() as directory:
            temp = Path(directory)
            (temp / "responses.json").write_text(json.dumps(responses))
            (temp / "mock.py").write_text('''import json, os, sys
from pathlib import Path
assert sys.argv[-1] == "https://qa.invalid/pos/day-close"
assert not any(arg in sys.argv for arg in ["-X", "--data", "--data-urlencode"])
root = Path(os.environ["MOCK_ROOT"])
count = root / "count"
index = int(count.read_text()) if count.exists() else 0
responses = json.loads((root / "responses.json").read_text())
item = responses[min(index, len(responses)-1)]
count.write_text(str(index+1))
Path(sys.argv[sys.argv.index("-o")+1]).write_text(item.get("body", ""))
sys.stdout.write(str(item.get("status", 200)))
sys.exit(item.get("exit", 0))
''')
            script = '''
set -uo pipefail
FAIL=0
ok() { echo "OK: $*"; }
bad() { echo "FAIL: $*"; FAIL=1; }
say() { :; }
sleep() { :; }
CURL=(python3 "$MOCK_ROOT/mock.py")
TMPD="$MOCK_ROOT"
LIVE_URL=https://qa.invalid
'''
            verifier = (ROOT / "scripts/ci-live-verify.sh").read_text()
            start = verifier.index('  say "/pos/day-close markers')
            end = verifier.index('  say "/pos/transactions markers', start)
            script += verifier[start:end] + '\nexit "$FAIL"\n'
            result = subprocess.run(["bash", "-c", script], text=True, capture_output=True,
                                    env={**os.environ, "ROOT": str(temp if missing_helper else ROOT), "MOCK_ROOT": str(temp)})
            count = int((temp / "count").read_text()) if (temp / "count").exists() else 0
            return result.returncode, count, result.stdout + result.stderr

    def test_unchanged_working_document_passes_once(self):
        code, count, _ = self.run_probe([{"body": VALID}])
        self.assertEqual((code, count), (0, 1))

    def test_missing_packaged_probe_cannot_silently_pass(self):
        code, count, output = self.run_probe([{"body": VALID}], missing_helper=True)
        self.assertEqual((code, count), (1, 0))
        self.assertIn("required probe is unavailable", output)

    def test_incomplete_response_recovers_only_after_full_valid_response(self):
        code, count, output = self.run_probe([{"body": VALID[:50]}, {"body": VALID}])
        self.assertEqual((code, count), (0, 2))
        self.assertIn("reason=incomplete_document", output)
        self.assertNotIn("FAIL:", output)

    def test_challenge_response_recovers_without_passing_the_challenge_page(self):
        code, count, output = self.run_probe([{"body": "<html>cf-chl-synthetic</html>"}, {"body": VALID}])
        self.assertEqual((code, count), (0, 2))
        self.assertIn("reason=challenge_document", output)

    def test_persistently_missing_control_stays_failed(self):
        code, count, output = self.run_probe([{"body": MISSING}])
        self.assertEqual((code, count), (1, 3))
        self.assertIn("reason=day_close_toggle_missing", output)

    def test_wrong_document_with_matching_input_stays_failed(self):
        code, count, output = self.run_probe([{"body": VALID.replace("/pos/day-close", "/other")}])
        self.assertEqual((code, count), (1, 3))
        self.assertIn("reason=unexpected_document", output)

    def test_login_document_is_immediate_failure(self):
        code, count, output = self.run_probe([{"body": LOGIN}, {"body": VALID}])
        self.assertEqual((code, count), (1, 1))
        self.assertIn("reason=login_document", output)

    def test_http_access_failures_are_not_retried(self):
        for status in (401, 403, 419):
            with self.subTest(status=status):
                code, count, _ = self.run_probe([{"status": status}, {"body": VALID}])
                self.assertEqual((code, count), (1, 1))

    def test_temporary_http_failure_can_recover(self):
        code, count, _ = self.run_probe([{"status": 503}, {"body": VALID}])
        self.assertEqual((code, count), (0, 2))

    def test_transport_error_does_not_accept_even_a_marker_in_a_partial_body(self):
        code, count, output = self.run_probe([{"body": VALID, "exit": 28}, {"body": VALID}])
        self.assertEqual((code, count), (0, 2))
        self.assertIn("reason=transport_error", output)

    def test_persistent_transport_error_fails_after_three_attempts(self):
        code, count, _ = self.run_probe([{"exit": 28}])
        self.assertEqual((code, count), (1, 3))

    def test_private_page_details_never_enter_logs(self):
        private = "SYNTHETIC_PRIVATE_IDENTITY_TOKEN_abc123"
        body = MISSING.replace("</body>", f"<p>{private}</p><input value='{private}'></body>")
        code, _, output = self.run_probe([{"body": body}])
        self.assertEqual(code, 1)
        self.assertNotIn(private, output)
        self.assertNotIn("<html>", output)

    def test_comments_and_other_input_types_do_not_satisfy_control(self):
        for body in (MISSING.replace("</body>", "<!-- <input type='checkbox' id='dc-auto-close-chk'> --></body>"),
                     VALID.replace("type='checkbox'", "type='hidden'")):
            self.assertEqual(PROBE.classify(body), "day_close_toggle_missing")

    def test_valid_document_with_background_challenge_script_still_passes(self):
        body = VALID.replace("</body>", "<script src='/cdn-cgi/challenge-platform/script.js'></script></body>")
        self.assertEqual(PROBE.classify(body), "ok")


if __name__ == "__main__":
    unittest.main()
