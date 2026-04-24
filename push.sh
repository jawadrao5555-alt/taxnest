#!/usr/bin/env bash
set -e
echo ">>> Removing stale lock files"
/bin/rm -f .git/refs/remotes/origin/main.lock
/bin/rm -f .git/refs/remotes/origin/pra-offline-fix-deploy.lock
/bin/rm -f .git/index.lock
echo ">>> Locks cleared"
echo ""
echo ">>> Git status"
git status --short
echo ""
echo ">>> Pushing to origin/main"
git push origin main
echo ""
echo ">>> DONE"
