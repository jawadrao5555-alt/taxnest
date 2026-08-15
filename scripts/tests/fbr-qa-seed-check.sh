#!/bin/bash
# Regression test for scripts/fbr-qa-seed.sh + the data assertions in
# scripts/fbr-live-spot-check.sh (Task 730). Runs OFFLINE against a local mock
# FBR POS server. Covers:
#   1. PREFLIGHT fail-closed: FBR reporting ON  -> seed aborts, ZERO writes.
#   2. PREFLIGHT fail-closed: wrong company     -> seed aborts, ZERO writes.
#   3. Happy path with NON-2026 serials (year-rollover / pre-existing-serial
#      proof): seed captures the server's ACTUAL serials into the state file,
#      and the spot-check asserts those exact serials on the right tabs.
# Usage: bash scripts/tests/fbr-qa-seed-check.sh   (exit 0 = pass)
set -uo pipefail
cd "$(dirname "$0")/../.."

TMPD=$(mktemp -d /tmp/fbr-qa-test.XXXXXX)
trap '[ "${SRV_PID:-0}" -gt 0 ] && kill "$SRV_PID" 2>/dev/null; rm -rf "$TMPD"' EXIT

cat > "$TMPD/mock.py" <<'PYEOF'
import json, os, re, sys
from http.server import BaseHTTPRequestHandler, HTTPServer

PORT = int(sys.argv[1])
MODE = sys.argv[2]          # 'ok' | 'reporting_on' | 'wrong_company'
HITLOG = sys.argv[3]        # write log of mutating POSTs
COMPANY = "Some Real Customer Shop" if MODE == "wrong_company" else "QA FBR Audit Store"
FBRCHARGE = "1" if MODE == "reporting_on" else "0"
# Deliberately NOT 2026 and NOT starting at 00001.
SERIALS = {}
COUNTER = {"n": 41}

def page(marker, extra=""):
    return f"""<html><head><meta name="csrf-token" content="mocktok"></head>
    <body>{COMPANY} {marker} {extra}</body></html>"""

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def _send(self, code, body, ctype="text/html"):
        b = body.encode()
        self.send_response(code)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(b)))
        self.end_headers()
        self.wfile.write(b)
    def do_GET(self):
        p = self.path
        if p == "/fbr-pos/login":
            self._send(200, '<form><input name="_token" value="mocktok"></form>')
        elif p == "/fbr-pos/dashboard":
            self._send(200, page("fbr-pos/day-close"))
        elif p == "/fbr-pos/create":
            self._send(200, page("manualItemNameInput", f"<script>var fbrCharge = {FBRCHARGE};</script>"))
        elif p == "/fbr-pos/tax-reports":
            self._send(200, page("tax-reports/csv"))
        elif p == "/fbr-pos/reports":
            self._send(200, page('name="from"'))
        elif p == "/fbr-pos/day-close":
            self._send(200, page("dayclose"))
        elif p == "/fbr-pos/tax-reports/csv":
            self._send(200, '"Total Sales excl. Tax (PKR)",7640.00\n', "text/csv")
        elif p.startswith("/fbr-pos/transactions?tab=local"):
            rows = " ".join(s for s, m in SERIALS.values() if m == "local")
            self._send(200, page("tx-local", rows))
        elif p.startswith("/fbr-pos/transactions"):
            rows = " ".join(s for s, m in SERIALS.values() if m == "fbr")
            self._send(200, page("tx", rows))
        elif p == "/fbr-pos/products":
            self._send(200, page("products", "Chai Patti 500g Basmati Rice 5kg"))
        elif p.startswith("/fbr-pos/api/products/search"):
            self._send(200, "[]", "application/json")
        else:
            self._send(404, "nope")
    def do_POST(self):
        n = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(n).decode(errors="replace")
        p = self.path
        if p == "/fbr-pos/login":
            self.send_response(302)
            self.send_header("Location", "/fbr-pos/create")
            self.send_header("Set-Cookie", "sess=mock; Path=/")
            self.send_header("Content-Length", "0")
            self.end_headers()
            return
        with open(HITLOG, "a") as f:
            f.write(p + "\n")
        if p == "/fbr-pos/products":
            self.send_response(302)
            self.send_header("Location", "/fbr-pos/products")
            self.send_header("Content-Length", "0")
            self.end_headers()
        elif p == "/fbr-pos/store":
            d = json.loads(body)
            uuid = d.get("offline_uuid", "")
            replay = uuid in SERIALS
            if not replay:
                mode = "local" if d.get("save_as_provisional") else "fbr"
                prefix = "FLOCAL" if mode == "local" else "FPOS"
                COUNTER["n"] += 1
                SERIALS[uuid] = (f"{prefix}-2031-{COUNTER['n']:05d}", mode)
            serial, mode = SERIALS[uuid]
            resp = {"success": True, "invoice_number": serial, "invoice_mode": mode,
                    "fbr_status": "local" if mode == "local" else None,
                    "fbr_invoice_number": None, "total_amount": 1, "change_due": 0}
            if replay: resp["replayed"] = True
            self._send(200, json.dumps(resp), "application/json")
        else:
            self._send(404, "nope")

HTTPServer(("127.0.0.1", PORT), H).serve_forever()
PYEOF

PORT=$((20000 + RANDOM % 20000))
HITLOG="$TMPD/hits.log"; : > "$HITLOG"
start_mock() { # $1 = mode
  [ "${SRV_PID:-0}" -gt 0 ] && { kill "$SRV_PID" 2>/dev/null; sleep 0.2; }
  python3 "$TMPD/mock.py" "$PORT" "$1" "$HITLOG" & SRV_PID=$!
  for _ in $(seq 1 50); do curl -s -o /dev/null "http://127.0.0.1:$PORT/fbr-pos/login" && return 0; sleep 0.1; done
  echo "mock server failed to start" >&2; exit 2
}
SRV_PID=0
FAIL=0
ENVV=(env BASE_URL="http://127.0.0.1:$PORT" LIVE_FBR_QA_LOGIN=mock@qa LIVE_FBR_QA_PASS=mock QA_STATE_FILE="$TMPD/state.env")

echo "==> CASE 1: reporting ON — seed must abort with ZERO writes"
start_mock reporting_on; : > "$HITLOG"
if "${ENVV[@]}" bash scripts/fbr-qa-seed.sh >"$TMPD/c1.out" 2>&1; then
  echo "    FAIL: seed exited 0 despite reporting ON"; FAIL=1
elif [ -s "$HITLOG" ]; then
  echo "    FAIL: seed performed writes before aborting: $(cat "$HITLOG")"; FAIL=1
elif [ -f "$TMPD/state.env" ]; then
  echo "    FAIL: state file written despite abort"; FAIL=1
else
  echo "    OK: aborted fail-closed ($(grep -c ABORT "$TMPD/c1.out") abort msg), no writes"
fi

echo "==> CASE 2: wrong company — seed must abort with ZERO writes"
start_mock wrong_company; : > "$HITLOG"
if "${ENVV[@]}" bash scripts/fbr-qa-seed.sh >"$TMPD/c2.out" 2>&1; then
  echo "    FAIL: seed exited 0 despite wrong company"; FAIL=1
elif [ -s "$HITLOG" ] || [ -f "$TMPD/state.env" ]; then
  echo "    FAIL: seed wrote data / state despite wrong company"; FAIL=1
else
  echo "    OK: aborted fail-closed on wrong tenant, no writes"
fi

echo "==> CASE 3: happy path with year-2031 serials — state captured, spot-check asserts them"
start_mock ok; : > "$HITLOG"
if ! "${ENVV[@]}" bash scripts/fbr-qa-seed.sh >"$TMPD/c3.out" 2>&1; then
  echo "    FAIL: seed failed on happy path:"; tail -5 "$TMPD/c3.out"; FAIL=1
else
  . "$TMPD/state.env"
  case "$FBR_QA_FINAL_SERIALS" in FPOS-2031-*" "FPOS-2031-*) echo "    OK: finals captured ($FBR_QA_FINAL_SERIALS)";;
    *) echo "    FAIL: unexpected finals in state: '$FBR_QA_FINAL_SERIALS'"; FAIL=1;; esac
  case "$FBR_QA_LOCAL_SERIALS" in FLOCAL-2031-*" "FLOCAL-2031-*) echo "    OK: locals captured ($FBR_QA_LOCAL_SERIALS)";;
    *) echo "    FAIL: unexpected locals in state: '$FBR_QA_LOCAL_SERIALS'"; FAIL=1;; esac
  # Re-run = idempotent replay: same serials, no new ones.
  "${ENVV[@]}" bash scripts/fbr-qa-seed.sh >"$TMPD/c3b.out" 2>&1
  n_replay=$(grep -c 'already seeded' "$TMPD/c3b.out")
  [ "$n_replay" = "4" ] && echo "    OK: re-run replayed all 4 bills" || { echo "    FAIL: re-run replayed $n_replay/4 bills"; FAIL=1; }
  # Spot-check must PASS against the mock using the recorded serials.
  if "${ENVV[@]}" bash scripts/fbr-live-spot-check.sh >"$TMPD/c3c.out" 2>&1; then
    grep -q "shows seeded finals" "$TMPD/c3c.out" && grep -q "shows seeded provisionals" "$TMPD/c3c.out" \
      && echo "    OK: spot-check asserted exact recorded serials" \
      || { echo "    FAIL: spot-check passed but not via exact-serial assertions"; FAIL=1; }
  else
    echo "    FAIL: spot-check failed on seeded mock:"; grep FAIL "$TMPD/c3c.out" | head -5; FAIL=1
  fi
  # Sabotage: remove one local serial from state -> spot-check must FAIL.
  sed -i 's/FBR_QA_LOCAL_SERIALS="/FBR_QA_LOCAL_SERIALS="FLOCAL-2031-99999 /' "$TMPD/state.env"
  if "${ENVV[@]}" bash scripts/fbr-live-spot-check.sh >"$TMPD/c3d.out" 2>&1; then
    echo "    FAIL: spot-check passed despite a missing expected serial"; FAIL=1
  else
    echo "    OK: spot-check fails loudly when an expected serial is missing"
  fi
fi

echo ""
if [ $FAIL -ne 0 ]; then echo "FBR QA SEED CHECK: FAILED" >&2; exit 1; fi
echo "FBR QA SEED CHECK: PASS"
