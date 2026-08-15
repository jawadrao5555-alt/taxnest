#!/bin/bash
# Deploy-gap check: compare workspace HEAD vs live (cPanel) HEAD.
# Usage: bash scripts/check-live-deploy.sh
# Exit 0 = live is up to date with workspace HEAD (or ahead/equal).
# Exit 1 = DEPLOY GAP (live is behind) — run the deploy runbook.
# Exit 2 = could not check (SSH/network problem) or REAL drift — verify manually.
# Exit 3 = RECONCILABLE lineage divergence (Task 703): origin/main's tree is
#          byte-identical to a workspace-lineage commit — deploy-live.sh will
#          auto-reconcile (merge -s ours) and deploy; callers should run it.
set -uo pipefail
cd "$(dirname "$0")/.."

KEY="/home/runner/workspace/.local/ssh/cpanel_deploy_key"
# Cloudflare (Aug 2026): main domain is proxied — SSH must use the DNS-only host.
HOST="taxnestc@cpanel.taxnest.com.pk"
LIVE_DIR="/home/taxnestc/public_html"

LOCAL_HEAD=$(git rev-parse HEAD 2>/dev/null)
if [ -z "$LOCAL_HEAD" ]; then
  echo "check-live-deploy: cannot read workspace HEAD" >&2
  exit 2
fi

LIVE_HEAD=$(timeout 25 ssh -i "$KEY" -p 22 -o BatchMode=yes -o ConnectTimeout=10 \
  -o StrictHostKeyChecking=accept-new "$HOST" \
  "cd $LIVE_DIR && git rev-parse HEAD" 2>/dev/null)

if [ -z "${LIVE_HEAD:-}" ]; then
  echo "check-live-deploy: WARNING — could not reach live server over SSH; verify live HEAD manually." >&2
  exit 2
fi

if [ "$LIVE_HEAD" = "$LOCAL_HEAD" ]; then
  echo "check-live-deploy: OK — live HEAD matches workspace HEAD ($LOCAL_HEAD)."
  exit 0
fi

# Live differs. Is live's commit an ancestor of workspace HEAD? (= live is behind)
if git merge-base --is-ancestor "$LIVE_HEAD" "$LOCAL_HEAD" 2>/dev/null; then
  BEHIND=$(git rev-list --count "$LIVE_HEAD".."$LOCAL_HEAD" 2>/dev/null || echo "?")
  echo "check-live-deploy: DEPLOY GAP — live is $BEHIND commit(s) behind workspace." >&2
  echo "  workspace HEAD: $LOCAL_HEAD" >&2
  echo "  live HEAD:      $LIVE_HEAD" >&2
  echo "  Fix: git push origin HEAD:main, then run the cPanel deploy runbook" >&2
  echo "  (see .agents/memory/cpanel-deployment.md — pull, migrate --force, caches, opcache reset)." >&2
  exit 1
fi

# Live commit unknown to workspace. Common benign cause: platform task-merge
# lineage divergence (content-identical, different SHAs — see
# .agents/memory/cpanel-deployment.md). Classify it with the shared logic so
# callers (post-merge.sh) can auto-run deploy-live.sh for the benign case.
if [ -f scripts/lib/deploy-reconcile.sh ]; then
  . scripts/lib/deploy-reconcile.sh
  if reconcile_fetch_origin_main >/dev/null 2>&1; then
    CLASS_LINE=$(reconcile_classify "$LOCAL_HEAD" "$LIVE_HEAD" 2>/dev/null || echo ERROR)
    case "${CLASS_LINE%% *}" in
      ANCESTOR)
        # Fresh fetch shows live is actually behind/equal after all.
        if [ "$LIVE_HEAD" = "$LOCAL_HEAD" ]; then
          echo "check-live-deploy: OK — live HEAD matches workspace HEAD ($LOCAL_HEAD)."
          exit 0
        fi
        echo "check-live-deploy: DEPLOY GAP — live is behind workspace (visible after fetch)." >&2
        exit 1
        ;;
      RECONCILABLE)
        echo "check-live-deploy: RECONCILABLE lineage divergence — origin/main's tree is" >&2
        echo "  byte-identical to workspace commit ${CLASS_LINE#RECONCILABLE } (no unique content on origin)." >&2
        echo "  deploy-live.sh will auto-reconcile (git merge -s ours) and deploy: bash scripts/deploy-live.sh" >&2
        exit 3
        ;;
    esac
  fi
fi
echo "check-live-deploy: live HEAD ($LIVE_HEAD) is not an ancestor of workspace HEAD ($LOCAL_HEAD)." >&2
echo "  Origin carries unique content or live has REAL drift — investigate before deploying" >&2
echo "  (see .agents/memory/cpanel-deployment.md). deploy-live.sh will also fail loudly on this." >&2
exit 2
