#!/bin/bash
# Offline route fixtures for the public ERP deployment probe.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMPD="$(mktemp -d)"
trap 'rm -rf "$TMPD"' EXIT
LIVE_URL='https://erp-fixture.invalid'
closed=0
broken=0
missing_link=0
status=0
ok() { :; }
bad() { status=1; }

curl() {
  local path="${!#}" output= action= mode=current availability=open
  while [ "$#" -gt 1 ]; do
    if [ "$1" = '-o' ]; then output="$2"; shift; fi
    shift
  done
  case "${path#"$LIVE_URL"}" in
    /) printf '<a href="/nest-erps">ERP</a>' > "$output" ;;
    /nest-erps)
      if [ "$closed" -eq 1 ]; then availability=closed; fi
      printf '<div id="hospital-erp" data-erp-signup="%s"></div><div id="lab-erp" data-erp-signup="%s"></div><div id="school-erp" data-erp-signup="%s"></div><section id="pricing"></section>' \
        "$availability" "$availability" "$availability" > "$output"
      if [ "$closed" -eq 0 ]; then
        printf '<a href="/health/register?org_type=hospital"></a><a href="/health/register?org_type=lab"></a>' >> "$output"
        if [ "$missing_link" -eq 0 ]; then printf '<a href="/school-erp/register"></a>' >> "$output"; fi
      fi
      ;;
    /health/login) action=/health/login ;;
    /school-erp/login) action=/school-erp/login ;;
    /health/register?org_type=*) action=/health/register; mode=new ;;
    /school-erp/register)
      if [ "$closed" -eq 1 ]; then
        printf '404'
        return
      fi
      action=/school-erp/register; mode=new
      if [ "$broken" -eq 1 ]; then action=/school-erp/login; mode=current; fi
      ;;
    *) return 1 ;;
  esac
  if [ -n "$action" ]; then
    printf '<form action="%s"><input autocomplete="%s-password"></form>' "$action" "$mode" > "$output"
  fi
  printf '200'
}

source "$ROOT/scripts/lib/ci-live-verify-erp.sh"
verify_public_erp
[ "$status" -eq 0 ]
closed=1
verify_public_erp
[ "$status" -eq 0 ]
closed=0
broken=1
verify_public_erp
[ "$status" -eq 1 ]
status=0
broken=0
missing_link=1
verify_public_erp
[ "$status" -eq 1 ]
echo "PASS: ERP site probe verifies configured signup states, missing links, and invalid forms"
