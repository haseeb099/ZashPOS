#!/usr/bin/env bash
# Prove MySQL/MariaDB dump → restore into an isolated schema + storage mirror.
# zashpos:backup / zashpos:restore are SQLite-ONLY — do not use them here.
#
# Required env (never commit values):
#   MYSQL_HOST MYSQL_PORT MYSQL_USER MYSQL_PASSWORD
#   MYSQL_SOURCE_DATABASE   — live/staging schema to dump
#   MYSQL_TARGET_DATABASE   — empty disposable schema (must differ from source)
# Optional:
#   SOURCE_STORAGE_APP      — default: storage/app
#   TARGET_STORAGE_APP      — disposable storage mirror path
#   PROOF_MARKER_REL        — relative path under storage/app for upload probe
#
# Writes evidence JSON to storage/logs/phase5-mysql-backup-restore-proof.json (gitignored).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

: "${MYSQL_HOST:?}"
: "${MYSQL_PORT:=3306}"
: "${MYSQL_USER:?}"
: "${MYSQL_PASSWORD:?}"
: "${MYSQL_SOURCE_DATABASE:?}"
: "${MYSQL_TARGET_DATABASE:?}"

if [[ "$MYSQL_SOURCE_DATABASE" == "$MYSQL_TARGET_DATABASE" ]]; then
  echo "MYSQL_TARGET_DATABASE must differ from MYSQL_SOURCE_DATABASE" >&2
  exit 1
fi

if ! command -v mysqldump >/dev/null || ! command -v mysql >/dev/null; then
  echo "mysqldump/mysql clients required" >&2
  exit 1
fi

SOURCE_STORAGE_APP="${SOURCE_STORAGE_APP:-$ROOT/storage/app}"
TARGET_STORAGE_APP="${TARGET_STORAGE_APP:-$ROOT/storage/framework/testing/mysql-restore-storage}"
PROOF_MARKER_REL="${PROOF_MARKER_REL:-zashpos-mysql-proof/probe.txt}"
EVIDENCE="$ROOT/storage/logs/phase5-mysql-backup-restore-proof.json"
STAMP="$(date +%Y%m%d%H%M%S)"
DUMP_DIR="$ROOT/storage/app/zashpos-backups/mysql-proof"
DUMP_FILE="$DUMP_DIR/source-${STAMP}.sql"
MARKER_PATH="$SOURCE_STORAGE_APP/$PROOF_MARKER_REL"
PROBE_VALUE="mysql-restore-proof-${STAMP}"

mkdir -p "$DUMP_DIR" "$(dirname "$EVIDENCE")" "$(dirname "$MARKER_PATH")"
printf '%s' "$PROBE_VALUE" > "$MARKER_PATH"

MYSQL_PWD="$MYSQL_PASSWORD"
export MYSQL_PWD
MYSQL=(mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER")
DUMP=(mysqldump -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" --single-transaction --routines --triggers)

echo "Inserting DB proof row in source…"
PROOF_KEY="zashpos_mysql_proof_${STAMP}"
"${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "CREATE TABLE IF NOT EXISTS zashpos_backup_proof (
      proof_key VARCHAR(191) PRIMARY KEY,
      proof_value VARCHAR(191) NOT NULL,
      created_at TIMESTAMP NULL
   ) ENGINE=InnoDB;
   INSERT INTO zashpos_backup_proof (proof_key, proof_value, created_at)
   VALUES ('${PROOF_KEY}', '${PROBE_VALUE}', NOW())
   ON DUPLICATE KEY UPDATE proof_value=VALUES(proof_value);"

SOURCE_COUNT_BEFORE="$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM ${MYSQL_SOURCE_DATABASE}.zashpos_backup_proof WHERE proof_key='${PROOF_KEY}'")"
SOURCE_MARKER_BEFORE="$(cat "$MARKER_PATH")"

echo "Dumping source schema…"
"${DUMP[@]}" "$MYSQL_SOURCE_DATABASE" > "$DUMP_FILE"
test -s "$DUMP_FILE"

echo "Recreating isolated target schema…"
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${MYSQL_TARGET_DATABASE}\`; CREATE DATABASE \`${MYSQL_TARGET_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" < "$DUMP_FILE"

rm -rf "$TARGET_STORAGE_APP"
mkdir -p "$TARGET_STORAGE_APP"
# Mirror only the proof marker tree (full storage/app copy is an ops choice on the host).
mkdir -p "$(dirname "$TARGET_STORAGE_APP/$PROOF_MARKER_REL")"
cp "$MARKER_PATH" "$TARGET_STORAGE_APP/$PROOF_MARKER_REL"

SOURCE_COUNT_AFTER="$("${MYSQL[@]}" -N -e "SELECT COUNT(*) FROM ${MYSQL_SOURCE_DATABASE}.zashpos_backup_proof WHERE proof_key='${PROOF_KEY}'")"
SOURCE_MARKER_AFTER="$(cat "$MARKER_PATH")"
TARGET_VALUE="$("${MYSQL[@]}" -N -e "SELECT proof_value FROM ${MYSQL_TARGET_DATABASE}.zashpos_backup_proof WHERE proof_key='${PROOF_KEY}' LIMIT 1")"
TARGET_MARKER="$(cat "$TARGET_STORAGE_APP/$PROOF_MARKER_REL")"

if [[ "$SOURCE_COUNT_BEFORE" != "1" || "$SOURCE_COUNT_AFTER" != "1" ]]; then
  echo "Source proof row missing or changed unexpectedly" >&2
  exit 1
fi
if [[ "$SOURCE_MARKER_BEFORE" != "$SOURCE_MARKER_AFTER" ]]; then
  echo "Source storage marker changed during restore proof" >&2
  exit 1
fi
if [[ "$TARGET_VALUE" != "$PROBE_VALUE" ]]; then
  echo "Restored DB proof mismatch: $TARGET_VALUE" >&2
  exit 1
fi
if [[ "$TARGET_MARKER" != "$PROBE_VALUE" ]]; then
  echo "Restored storage marker mismatch: $TARGET_MARKER" >&2
  exit 1
fi

# Cleanup disposable proof row from source (leave dump + evidence).
"${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e "DELETE FROM zashpos_backup_proof WHERE proof_key='${PROOF_KEY}';"

SHA256="$(sha256sum "$DUMP_FILE" | awk '{print $1}')"
cat > "$EVIDENCE" <<EOF
{
  "proved_at": "$(date -Iseconds)",
  "engine": "mysql",
  "source_database": "${MYSQL_SOURCE_DATABASE}",
  "target_database": "${MYSQL_TARGET_DATABASE}",
  "dump_file": "$(basename "$DUMP_FILE")",
  "dump_sha256": "${SHA256}",
  "restored_proof_key": "${PROOF_KEY}",
  "restored_probe": "${PROBE_VALUE}",
  "source_db_unchanged": true,
  "source_storage_unchanged": true,
  "note": "zashpos:backup was not used; mysqldump/mysql only. APP_KEY not included in dump."
}
EOF

echo "MySQL backup/restore proof OK → $EVIDENCE"
unset MYSQL_PWD
