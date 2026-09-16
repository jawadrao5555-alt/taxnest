#!/usr/bin/env bash
# Local-only MariaDB recovery proof. Never accepts application DB names/credentials.
set -euo pipefail
set +x
umask 077

DRY_RUN=1
LOCAL_SYNTHETIC=0
SOCKET=""
MARIADB_BIN=""
SOURCE_DB="taxnest_rc_recovery_source"
RESTORE_DB="taxnest_rc_recovery_restore"

usage() {
    cat <<'USAGE'
Usage:
  bash scripts/ops/local-mariadb-recovery-rehearsal.sh \
    --socket /tmp/taxnest-rc-mariadb-browser-<uid>/run/mariadb.sock \
    --mariadb-bin /absolute/mariadb/bin [--dry-run]
  bash scripts/ops/local-mariadb-recovery-rehearsal.sh --local-synthetic --execute \
    --socket /tmp/taxnest-rc-mariadb-browser-<uid>/run/mariadb.sock \
    --mariadb-bin /absolute/mariadb/bin

Only the fixed synthetic databases taxnest_rc_recovery_source and
taxnest_rc_recovery_restore are ever created or dropped. The client runs with
--no-defaults, socket-only protocol, and a passwordless local root connection.
No app database, .env, credentials, TCP host, or network destination is used.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --socket) SOCKET="${2:-}"; shift 2 ;;
        --mariadb-bin) MARIADB_BIN="${2:-}"; shift 2 ;;
        --dry-run) DRY_RUN=1; shift ;;
        --execute) DRY_RUN=0; shift ;;
        --local-synthetic) LOCAL_SYNTHETIC=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "ERROR: unknown argument: $1" >&2; exit 2 ;;
    esac
done

[[ "$SOCKET" == /tmp/taxnest-rc-mariadb-browser-[0-9]*/run/mariadb.sock && -S "$SOCKET" && ! -L "$SOCKET" ]] || {
    echo "ERROR: socket must be the allowlisted local browser MariaDB Unix socket." >&2; exit 2;
}
[[ "$MARIADB_BIN" = /* && -d "$MARIADB_BIN" && ! -L "$MARIADB_BIN" && -x "$MARIADB_BIN/mysql" && -x "$MARIADB_BIN/mysqldump" ]] || {
    echo "ERROR: --mariadb-bin must be an existing absolute non-symlink directory containing mysql and mysqldump." >&2; exit 2;
}
[[ "$DRY_RUN" -eq 1 || "$LOCAL_SYNTHETIC" -eq 1 ]] || {
    echo "ERROR: --execute requires --local-synthetic; no non-synthetic database is accepted." >&2; exit 2;
}

if [[ "$DRY_RUN" -eq 1 ]]; then
    echo "DRY RUN — would use only $SOURCE_DB and $RESTORE_DB over the allowlisted Unix socket; no SQL, archive, credentials, or network action occurs."
    exit 0
fi

mysql_cmd() {
    env -i PATH="$MARIADB_BIN:/usr/bin:/bin" "$MARIADB_BIN/mysql" --no-defaults --protocol=SOCKET --socket="$SOCKET" -u root "$@"
}
dump_cmd() {
    env -i PATH="$MARIADB_BIN:/usr/bin:/bin" "$MARIADB_BIN/mysqldump" --no-defaults --protocol=SOCKET --socket="$SOCKET" -u root "$@"
}
for tool in openssl gzip sha256sum tar; do command -v "$tool" >/dev/null 2>&1 || { echo "ERROR: missing local tool: $tool" >&2; exit 2; }; done

existing="$(mysql_cmd -N -B -e "SELECT schema_name FROM information_schema.schemata WHERE schema_name IN ('$SOURCE_DB','$RESTORE_DB') ORDER BY schema_name")"
[[ -z "$existing" ]] || {
    echo "ERROR: refusing reuse or deletion; synthetic rehearsal database already exists: $existing" >&2; exit 2;
}

made_source=0
made_restore=0
WORK="$(mktemp -d "${TMPDIR:-/tmp}/taxnest-mariadb-recovery.XXXXXX")"
cleanup() {
    local status=$?
    trap - EXIT HUP INT TERM
    [[ "$made_restore" -eq 0 ]] || mysql_cmd -e "DROP DATABASE \`$RESTORE_DB\`" >/dev/null 2>&1 || true
    [[ "$made_source" -eq 0 ]] || mysql_cmd -e "DROP DATABASE \`$SOURCE_DB\`" >/dev/null 2>&1 || true
    rm -rf -- "$WORK"
    exit "$status"
}
trap cleanup EXIT HUP INT TERM

manifest_for() {
    local database="$1"
    printf 'accounts\t%s\t%s\n' \
        "$(mysql_cmd -N -B "$database" -e 'SELECT COUNT(*) FROM accounts')" \
        "$(mysql_cmd -N -B "$database" -e "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':',id,email) ORDER BY id SEPARATOR '|'),''),256) FROM accounts")"
    printf 'invoices\t%s\t%s\n' \
        "$(mysql_cmd -N -B "$database" -e 'SELECT COUNT(*) FROM invoices')" \
        "$(mysql_cmd -N -B "$database" -e "SELECT SHA2(COALESCE(GROUP_CONCAT(CONCAT_WS(':',id,account_id,invoice_number,amount_minor) ORDER BY id SEPARATOR '|'),''),256) FROM invoices")"
}
schema_proof_for() {
    local database="$1"
    mysql_cmd -N -B -e "SELECT table_name,index_name,non_unique,seq_in_index,column_name FROM information_schema.statistics WHERE table_schema='$database' AND table_name IN ('accounts','invoices') ORDER BY table_name,index_name,seq_in_index"
    mysql_cmd -N -B -e "SELECT table_name,column_name,referenced_table_name,referenced_column_name FROM information_schema.key_column_usage WHERE table_schema='$database' AND referenced_table_name IS NOT NULL ORDER BY table_name,column_name"
}

mysql_cmd -e "CREATE DATABASE \`$SOURCE_DB\`"
made_source=1
mysql_cmd "$SOURCE_DB" -e "
CREATE TABLE accounts (
  id INT NOT NULL PRIMARY KEY,
  email VARCHAR(100) NOT NULL,
  UNIQUE KEY accounts_email_unique (email)
) ENGINE=InnoDB;
CREATE TABLE invoices (
  id INT NOT NULL PRIMARY KEY,
  account_id INT NOT NULL,
  invoice_number VARCHAR(40) NOT NULL,
  amount_minor INT NOT NULL,
  UNIQUE KEY invoices_number_unique (invoice_number),
  KEY invoices_account_index (account_id),
  CONSTRAINT invoices_account_fk FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;
INSERT INTO accounts VALUES (1,'alpha@example.invalid'),(2,'bravo@example.invalid');
INSERT INTO invoices VALUES (1,1,'RC-1001',1250),(2,1,'RC-1002',2400),(3,2,'RC-2001',875);"

manifest_for "$SOURCE_DB" >"$WORK/source-data.tsv"
schema_proof_for "$SOURCE_DB" >"$WORK/source-schema.tsv"
dump_cmd --single-transaction --quick --routines --triggers --events "$SOURCE_DB" | gzip -c >"$WORK/synthetic.sql.gz"
printf '%s  synthetic.sql.gz\n' "$(sha256sum "$WORK/synthetic.sql.gz" | awk '{print $1}')" >"$WORK/synthetic.sql.gz.sha256"
MASTER_KEY="$WORK/ephemeral-master-key"
ENC_KEY="$WORK/encryption-key"
MAC_KEY="$WORK/authentication-key"
head -c 32 </dev/urandom >"$MASTER_KEY"
chmod 0600 "$MASTER_KEY"
printf 'taxnest-local-recovery-encryption-v1' | openssl dgst -sha256 -mac HMAC -macopt "key:file:$MASTER_KEY" -binary >"$ENC_KEY"
printf 'taxnest-local-recovery-authentication-v1' | openssl dgst -sha256 -mac HMAC -macopt "key:file:$MASTER_KEY" -binary >"$MAC_KEY"
chmod 0600 "$ENC_KEY" "$MAC_KEY"
openssl enc -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$WORK/synthetic.sql.gz" -out "$WORK/synthetic.sql.gz.enc" -pass "file:$ENC_KEY"
openssl dgst -sha256 -mac HMAC -macopt "key:file:$MAC_KEY" -r "$WORK/synthetic.sql.gz.enc" | awk '{print $1}' >"$WORK/synthetic.sql.gz.enc.hmac"
verify_hmac() {
    local ciphertext="$1" expected actual
    expected="$(cat "$ciphertext.hmac")"
    [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || return 1
    actual="$(openssl dgst -sha256 -mac HMAC -macopt "key:file:$MAC_KEY" -r "$ciphertext" | awk '{print $1}')"
    [[ "$actual" == "$expected" ]]
}
TRANSFER="$WORK/transfer"
mkdir -p "$TRANSFER"
mv -- "$WORK/synthetic.sql.gz.enc" "$TRANSFER/synthetic.sql.gz.enc"
mv -- "$WORK/synthetic.sql.gz.enc.hmac" "$TRANSFER/synthetic.sql.gz.enc.hmac"
mv -- "$WORK/synthetic.sql.gz.sha256" "$TRANSFER/synthetic.sql.gz.sha256"
rm -f -- "$WORK/synthetic.sql.gz"
[[ ! -e "$WORK/synthetic.sql.gz" ]] || { echo "ERROR: plaintext dump remained after transfer." >&2; exit 1; }
cp -- "$TRANSFER/synthetic.sql.gz.enc" "$WORK/tampered.sql.gz.enc"
cp -- "$TRANSFER/synthetic.sql.gz.enc.hmac" "$WORK/tampered.sql.gz.enc.hmac"
printf '\0' >>"$WORK/tampered.sql.gz.enc"
if verify_hmac "$WORK/tampered.sql.gz.enc"; then
    echo "ERROR: tampered ciphertext passed authentication." >&2
    exit 1
fi
verify_hmac "$TRANSFER/synthetic.sql.gz.enc" || { echo "ERROR: transferred ciphertext failed authentication." >&2; exit 1; }
RECOVERY="$WORK/recovery"
mkdir -p "$RECOVERY"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 600000 -salt -in "$TRANSFER/synthetic.sql.gz.enc" -out "$RECOVERY/synthetic.sql.gz" -pass "file:$ENC_KEY"
(cd "$RECOVERY" && sha256sum -c "$TRANSFER/synthetic.sql.gz.sha256" >/dev/null)

mysql_cmd -e "CREATE DATABASE \`$RESTORE_DB\`"
made_restore=1
gzip -dc -- "$RECOVERY/synthetic.sql.gz" | mysql_cmd "$RESTORE_DB"
manifest_for "$RESTORE_DB" >"$WORK/restore-data.tsv"
schema_proof_for "$RESTORE_DB" >"$WORK/restore-schema.tsv"
cmp -s "$WORK/source-data.tsv" "$WORK/restore-data.tsv" || { echo "ERROR: row-count/data checksum mismatch after restore." >&2; exit 1; }
cmp -s "$WORK/source-schema.tsv" "$WORK/restore-schema.tsv" || { echo "ERROR: foreign-key/index proof mismatch after restore." >&2; exit 1; }
[[ "$(mysql_cmd -N -B "$RESTORE_DB" -e 'SELECT COUNT(*) FROM invoices i LEFT JOIN accounts a ON a.id=i.account_id WHERE a.id IS NULL')" == 0 ]] || {
    echo "ERROR: restored foreign-key relationship has orphaned rows." >&2; exit 1;
}
mysql_cmd "$RESTORE_DB" -e 'CHECK TABLE accounts, invoices' >/dev/null
printf 'PASS: authenticated local MariaDB synthetic recovery proof completed; version=%s source=%s restore=%s accounts=2 invoices=3 dump_sha256=%s tamper=detected\n' \
    "$(mysql_cmd -N -B -e 'SELECT VERSION()')" "$SOURCE_DB" "$RESTORE_DB" "$(awk '{print $1}' "$TRANSFER/synthetic.sql.gz.sha256")"