#!/usr/bin/env bash
# ============================================================================
# TaxNest Production Sync — 2026-04-24 — One-Shot Runner
# Uses ~/.my.cnf (already configured on Hostcry) — no -u/-p needed.
# Safe to re-run: schema-align uses ADD COLUMN IF NOT EXISTS,
# data uses INSERT IGNORE.
# ============================================================================
set -e

DB="taxnestc_db"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TS=$(date +%Y%m%d-%H%M%S)
LOG="$HOME/migration-$TS.log"

echo "==================================================="
echo " TaxNest Prod Sync — $TS"
echo " Log file: $LOG"
echo "==================================================="

# Step 0: ensure backup exists
if [ ! -f "$HOME/backup-before-sync.sql" ]; then
  echo "[INFO] Taking pre-migration backup..."
  mysqldump --single-transaction --quick --skip-lock-tables "$DB" > "$HOME/backup-before-sync-$TS.sql"
  echo "[OK]   Backup at $HOME/backup-before-sync-$TS.sql ($(du -h "$HOME/backup-before-sync-$TS.sql" | cut -f1))"
else
  echo "[OK]   Existing backup found at $HOME/backup-before-sync.sql ($(du -h "$HOME/backup-before-sync.sql" | cut -f1))"
fi

# Step 1: Run MASTER-ALL.sql
echo ""
echo "[RUN]  Executing MASTER-ALL.sql..."
echo ""
if mysql --show-warnings --verbose "$DB" < "$SCRIPT_DIR/MASTER-ALL.sql" 2>&1 | tee "$LOG" | tail -50; then
  echo ""
  echo "==================================================="
  echo " [OK] Migration complete. Full log at: $LOG"
  echo "==================================================="
else
  echo ""
  echo "==================================================="
  echo " [ERROR] Migration failed. See full log: $LOG"
  echo " To restore: mysql $DB < \$HOME/backup-before-sync.sql"
  echo "==================================================="
  exit 1
fi

# Step 2: Re-run verify standalone for clean output
echo ""
echo "==================================================="
echo " VERIFY (clean output)"
echo "==================================================="
mysql "$DB" < "$SCRIPT_DIR/999-VERIFY.sql"
