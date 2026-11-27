#!/bin/bash
# Sourced after the protected deployment has verified its exact live SHA.

fetch_public_erp_page() {
  local path="$1" output="$2" code
  if ! code=$(curl -sS --max-time 35 -o "$output" -w '%{http_code}' "${LIVE_URL}${path}") || [ "$code" != 200 ]; then
    bad "$path did not serve the expected ERP page (HTTP ${code:-unavailable})"
    return 1
  fi
}

require_public_erp_form() {
  local path="$1" action="$2" password_mode="$3"
  if fetch_public_erp_page "$path" "$TMPD/erp-form.html"; then
    if { grep -qF "action=\"$action\"" "$TMPD/erp-form.html" ||
        grep -qF "action=\"${LIVE_URL}$action\"" "$TMPD/erp-form.html"; } &&
      grep -qF "autocomplete=\"${password_mode}-password\"" "$TMPD/erp-form.html"; then
      ok "public ERP form at $path"
    else
      bad "$path did not render the expected ERP form"
    fi
  fi
}

has_public_erp_link() {
  local path="$1" page="$2"
  grep -qF "href=\"$path\"" "$page" || grep -qF "href=\"${LIVE_URL}$path\"" "$page"
}

verify_public_erp() {
  local home="$TMPD/erp-home.html" hub="$TMPD/erp-hub.html" id path action signup
  if fetch_public_erp_page '/' "$home"; then
    has_public_erp_link '/nest-erps' "$home" && ok "homepage ERP navigation" || bad "homepage ERP link missing"
  fi
  if ! fetch_public_erp_page '/nest-erps' "$hub"; then
    return
  fi
  for id in hospital-erp lab-erp school-erp pricing; do
    grep -qF "id=\"$id\"" "$hub" && ok "ERP hub $id" || bad "ERP hub is missing $id"
  done
  require_public_erp_form '/health/login' '/health/login' current
  require_public_erp_form '/school-erp/login' '/school-erp/login' current

  for id in hospital lab school; do
    path="/health/register?org_type=$id"
    if [ "$id" = school ]; then path='/school-erp/register'; fi
    action="${path%%\?*}"
    signup="id=\"$id-erp\"[^>]*data-erp-signup="
    if grep -qE "$signup\"open\"" "$hub"; then
      if has_public_erp_link "$path" "$hub"; then
        require_public_erp_form "$path" "$action" new
      else
        bad "$id ERP signup is open but its link is missing"
      fi
    elif grep -qE "$signup\"closed\"" "$hub" && ! has_public_erp_link "$path" "$hub"; then
      ok "public signup closed for $id ERP"
    else
      bad "$id ERP signup availability does not match its public link"
    fi
  done
}
