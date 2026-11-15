#!/usr/bin/env bash
# Prove MySQL/MariaDB dump → restore into an isolated schema + storage artifact restore.
# zashpos:backup / zashpos:restore are SQLite-ONLY — do not use them here.
#
# Required env (never commit values):
#   MYSQL_HOST MYSQL_USER MYSQL_PASSWORD
#   MYSQL_SOURCE_DATABASE   — schema to dump (validated identifier)
# Optional:
#   MYSQL_PORT              — default 3306
#   MYSQL_TARGET_DATABASE   — disposable schema name; must NOT already exist.
#                             If omitted, a unique zashpos_rstr_* name is generated.
#                             This script never DROP a caller-supplied existing schema
#                             to "make room"; it only DROP DATABASE for schemas it created.
#   SOURCE_STORAGE_APP      — default: <repo>/storage/app
#   TARGET_STORAGE_APP      — must be under storage/framework/testing/ and must not exist
#   PROOF_MYSQL_DRY_RUN=1   — validate inputs only; no dump/restore/mutations
#
# Writes evidence JSON to storage/logs/phase5-mysql-backup-restore-proof.json (gitignored).
# Real staging MySQL restore remains pending until a host with credentials runs this.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=lib/mysql-backup-proof-safety.sh
source "$ROOT/scripts/lib/mysql-backup-proof-safety.sh"
cd "$ROOT"

: "${MYSQL_HOST:?}"
: "${MYSQL_PORT:=3306}"
: "${MYSQL_USER:?}"
: "${MYSQL_PASSWORD:?}"
: "${MYSQL_SOURCE_DATABASE:?}"

STAMP="$(date +%Y%m%d%H%M%S)"
RAND="$(printf '%04x' "$RANDOM")"
DRY_RUN="${PROOF_MYSQL_DRY_RUN:-0}"

assert_sql_identifier "$MYSQL_SOURCE_DATABASE" "MYSQL_SOURCE_DATABASE"

if [[ -n "${MYSQL_TARGET_DATABASE:-}" ]]; then
  assert_sql_identifier "$MYSQL_TARGET_DATABASE" "MYSQL_TARGET_DATABASE"
else
  MYSQL_TARGET_DATABASE="zashpos_rstr_${STAMP}_${RAND}"
  assert_sql_identifier "$MYSQL_TARGET_DATABASE" "generated MYSQL_TARGET_DATABASE"
fi

if [[ "$MYSQL_SOURCE_DATABASE" == "$MYSQL_TARGET_DATABASE" ]]; then
  echo "Refusing MYSQL_SOURCE_DATABASE == MYSQL_TARGET_DATABASE." >&2
  exit 1
fi

SAFE_STORAGE_ROOT="$ROOT/storage/framework/testing"
SOURCE_STORAGE_APP="${SOURCE_STORAGE_APP:-$ROOT/storage/app}"
TARGET_STORAGE_APP="${TARGET_STORAGE_APP:-$SAFE_STORAGE_ROOT/mysql-restore-${STAMP}-${RAND}}"
PROOF_MARKER_REL="${PROOF_MARKER_REL:-zashpos-mysql-proof/probe.txt}"
EVIDENCE="$ROOT/storage/logs/phase5-mysql-backup-restore-proof.json"
ARTIFACT_DIR="$SAFE_STORAGE_ROOT/mysql-proof-artifacts/${STAMP}-${RAND}"
DUMP_FILE="$ARTIFACT_DIR/source-${STAMP}.sql"
STORAGE_TAR="$ARTIFACT_DIR/storage-app-${STAMP}.tar"
STORAGE_MANIFEST="$ARTIFACT_DIR/storage-manifest-${STAMP}.tsv"

assert_safe_storage_pair "$SOURCE_STORAGE_APP" "$TARGET_STORAGE_APP" "$SAFE_STORAGE_ROOT"
SOURCE_STORAGE_APP="$CANON_SOURCE_STORAGE"
TARGET_STORAGE_APP="$CANON_TARGET_STORAGE"

if [[ "$DRY_RUN" == "1" ]]; then
  echo "DRY_RUN OK: identifiers and storage paths validated; no dump/restore performed."
  echo "source_db=$MYSQL_SOURCE_DATABASE target_db=$MYSQL_TARGET_DATABASE"
  echo "source_storage=$SOURCE_STORAGE_APP"
  echo "target_storage=$TARGET_STORAGE_APP"
  exit 0
fi

if ! command -v mysqldump >/dev/null || ! command -v mysql >/dev/null; then
  echo "mysqldump/mysql clients required (set PROOF_MYSQL_DRY_RUN=1 to validate paths only)." >&2
  exit 1
fi
if ! command -v tar >/dev/null; then
  echo "tar required to archive/restore storage separately from SQL dump." >&2
  exit 1
fi

MYSQL_PWD="$MYSQL_PASSWORD"
export MYSQL_PWD
MYSQL=(mysql -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" --batch --skip-column-names)
DUMP=(mysqldump -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" --single-transaction --routines --triggers)

OWNED_PROOF_KEY=""
OWNED_MARKER_PATH=""
OWNED_TARGET_DB=""
CLEANED=0

cleanup_owned_fixtures() {
  if [[ "$CLEANED" -eq 1 ]]; then
    return 0
  fi
  CLEANED=1
  if [[ -n "${MYSQL_PWD:-}" ]]; then
    if [[ -n "$OWNED_PROOF_KEY" ]]; then
      "${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
        "DELETE FROM zashpos_backup_proof WHERE proof_key='${OWNED_PROOF_KEY}';" 2>/dev/null || true
    fi
    if [[ -n "$OWNED_TARGET_DB" ]]; then
      "${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${OWNED_TARGET_DB}\`;" 2>/dev/null || true
      OWNED_TARGET_DB=""
    fi
  fi
  if [[ -n "$OWNED_MARKER_PATH" && -f "$OWNED_MARKER_PATH" ]]; then
    rm -f "$OWNED_MARKER_PATH" 2>/dev/null || true
    rmdir "$(dirname "$OWNED_MARKER_PATH")" 2>/dev/null || true
  fi
  unset MYSQL_PWD || true
}

on_err() {
  local rc=$?
  echo "Proof failed (exit $rc); cleaning owned fixtures…" >&2
  cleanup_owned_fixtures || true
  exit "$rc"
}
trap on_err ERR
trap cleanup_owned_fixtures EXIT

# Refuse existing target schema — never DROP a pre-existing caller-supplied name.
EXISTING_TARGET="$("${MYSQL[@]}" -e "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='${MYSQL_TARGET_DATABASE}' LIMIT 1" || true)"
if [[ -n "$EXISTING_TARGET" ]]; then
  echo "Refusing existing MYSQL_TARGET_DATABASE \"${MYSQL_TARGET_DATABASE}\" (will not DROP)." >&2
  exit 1
fi

mkdir -p "$ARTIFACT_DIR" "$(dirname "$EVIDENCE")"

MARKER_PATH="$SOURCE_STORAGE_APP/$PROOF_MARKER_REL"
PROBE_VALUE="mysql-restore-proof-${STAMP}"
mkdir -p "$(dirname "$MARKER_PATH")"
printf '%s' "$PROBE_VALUE" > "$MARKER_PATH"
OWNED_MARKER_PATH="$MARKER_PATH"

echo "Inserting disposable DB proof fixture in source…"
PROOF_KEY="zashpos_mysql_proof_${STAMP}_${RAND}"
OWNED_PROOF_KEY="$PROOF_KEY"
"${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "CREATE TABLE IF NOT EXISTS zashpos_backup_proof (
      proof_key VARCHAR(191) PRIMARY KEY,
      proof_value VARCHAR(191) NOT NULL,
      created_at TIMESTAMP NULL
   ) ENGINE=InnoDB;
   INSERT INTO zashpos_backup_proof (proof_key, proof_value, created_at)
   VALUES ('${PROOF_KEY}', '${PROBE_VALUE}', NOW());"

APP_TABLE_COUNT_BEFORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema='${MYSQL_SOURCE_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof');")"
APP_TABLE_NAMES_BEFORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT table_name FROM information_schema.tables
   WHERE table_schema='${MYSQL_SOURCE_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof')
   ORDER BY table_name ASC
   LIMIT 8;" | tr '\n' ',' | sed 's/,$//')"

# Prefer a concrete application table row count when present.
APP_PROBE_TABLE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT table_name FROM information_schema.tables
   WHERE table_schema='${MYSQL_SOURCE_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name IN ('ns_nexopos_options','nexopos_options','ns_users','users','migrations')
   ORDER BY FIELD(table_name,'ns_nexopos_options','nexopos_options','ns_users','users','migrations')
   LIMIT 1;" || true)"
APP_PROBE_COUNT_BEFORE=""
if [[ -n "$APP_PROBE_TABLE" ]]; then
  assert_sql_identifier "$APP_PROBE_TABLE" "application probe table"
  APP_PROBE_COUNT_BEFORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e "SELECT COUNT(*) FROM \`${APP_PROBE_TABLE}\`;")"
fi

SOURCE_MARKER_HASH_BEFORE="$(sha256_file "$MARKER_PATH")"

echo "Dumping source schema to artifact…"
"${DUMP[@]}" "$MYSQL_SOURCE_DATABASE" > "$DUMP_FILE"
test -s "$DUMP_FILE"
DUMP_SHA="$(sha256_file "$DUMP_FILE")"

echo "Archiving source storage probe into a separate artifact (not the SQL dump)…"
(
  cd "$SOURCE_STORAGE_APP"
  tar -cf "$STORAGE_TAR" -- "$PROOF_MARKER_REL"
)
printf '%s\t%s\n' "$PROOF_MARKER_REL" "$(sha256_file "$MARKER_PATH")" > "$STORAGE_MANIFEST"
STORAGE_TAR_SHA="$(sha256_file "$STORAGE_TAR")"

SOURCE_PROOF_PRE_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT proof_value FROM zashpos_backup_proof WHERE proof_key='${PROOF_KEY}' LIMIT 1")"
SOURCE_MARKER_HASH_PRE_RESTORE="$(sha256_file "$MARKER_PATH")"
APP_TABLE_COUNT_PRE_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema='${MYSQL_SOURCE_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof');")"
APP_PROBE_COUNT_PRE_RESTORE=""
if [[ -n "$APP_PROBE_TABLE" ]]; then
  APP_PROBE_COUNT_PRE_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e "SELECT COUNT(*) FROM \`${APP_PROBE_TABLE}\`;")"
fi

echo "Creating disposable target schema (CREATE only; refused if it already existed)…"
"${MYSQL[@]}" -e "CREATE DATABASE \`${MYSQL_TARGET_DATABASE}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
OWNED_TARGET_DB="$MYSQL_TARGET_DATABASE"
"${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" < "$DUMP_FILE"

echo "Restoring storage exclusively from tar artifact into a fresh destination…"
mkdir -p "$TARGET_STORAGE_APP"
tar -xf "$STORAGE_TAR" -C "$TARGET_STORAGE_APP"

echo "Verifying restored file hashes against backup manifest…"
while IFS=$'\t' read -r rel expected; do
  [[ -z "${rel:-}" ]] && continue
  actual_file="$TARGET_STORAGE_APP/$rel"
  if [[ ! -f "$actual_file" ]]; then
    echo "Restored file missing: $rel" >&2
    exit 1
  fi
  actual="$(sha256_file "$actual_file")"
  if [[ "$actual" != "$expected" ]]; then
    echo "Hash mismatch for $rel (manifest=$expected actual=$actual)" >&2
    exit 1
  fi
done < "$STORAGE_MANIFEST"

SOURCE_PROOF_POST_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT proof_value FROM zashpos_backup_proof WHERE proof_key='${PROOF_KEY}' LIMIT 1")"
SOURCE_MARKER_HASH_POST_RESTORE="$(sha256_file "$MARKER_PATH")"
APP_TABLE_COUNT_POST_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e \
  "SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema='${MYSQL_SOURCE_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof');")"

if [[ "$SOURCE_PROOF_PRE_RESTORE" != "$SOURCE_PROOF_POST_RESTORE" ]]; then
  echo "Source proof fixture changed during restore." >&2
  exit 1
fi
if [[ "$SOURCE_MARKER_HASH_PRE_RESTORE" != "$SOURCE_MARKER_HASH_POST_RESTORE" ]]; then
  echo "Source storage marker changed during restore." >&2
  exit 1
fi
if [[ "$APP_TABLE_COUNT_PRE_RESTORE" != "$APP_TABLE_COUNT_POST_RESTORE" ]]; then
  echo "Source application table count changed during restore." >&2
  exit 1
fi
if [[ -n "$APP_PROBE_TABLE" ]]; then
  APP_PROBE_COUNT_POST_RESTORE="$("${MYSQL[@]}" "$MYSQL_SOURCE_DATABASE" -e "SELECT COUNT(*) FROM \`${APP_PROBE_TABLE}\`;")"
  if [[ "$APP_PROBE_COUNT_PRE_RESTORE" != "$APP_PROBE_COUNT_POST_RESTORE" ]]; then
    echo "Source application probe table $APP_PROBE_TABLE changed during restore." >&2
    exit 1
  fi
fi

TARGET_PROOF="$("${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" -e \
  "SELECT proof_value FROM zashpos_backup_proof WHERE proof_key='${PROOF_KEY}' LIMIT 1")"
if [[ "$TARGET_PROOF" != "$PROBE_VALUE" ]]; then
  echo "Restored DB proof mismatch: $TARGET_PROOF" >&2
  exit 1
fi

TARGET_APP_TABLES="$("${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" -e \
  "SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema='${MYSQL_TARGET_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof');")"
if [[ "$TARGET_APP_TABLES" != "$APP_TABLE_COUNT_BEFORE" ]] || [[ "$TARGET_APP_TABLES" -lt 1 ]]; then
  echo "Restored schema missing representative application tables (got $TARGET_APP_TABLES, expected $APP_TABLE_COUNT_BEFORE)." >&2
  exit 1
fi

TARGET_TABLE_NAMES="$("${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" -e \
  "SELECT table_name FROM information_schema.tables
   WHERE table_schema='${MYSQL_TARGET_DATABASE}'
     AND table_type='BASE TABLE'
     AND table_name NOT IN ('zashpos_backup_proof')
   ORDER BY table_name ASC
   LIMIT 8;" | tr '\n' ',' | sed 's/,$//')"
if [[ "$TARGET_TABLE_NAMES" != "$APP_TABLE_NAMES_BEFORE" ]]; then
  echo "Restored application table name sample mismatch." >&2
  echo "  source=$APP_TABLE_NAMES_BEFORE" >&2
  echo "  target=$TARGET_TABLE_NAMES" >&2
  exit 1
fi

TARGET_APP_PROBE_COUNT=""
if [[ -n "$APP_PROBE_TABLE" ]]; then
  TARGET_APP_PROBE_COUNT="$("${MYSQL[@]}" "$MYSQL_TARGET_DATABASE" -e "SELECT COUNT(*) FROM \`${APP_PROBE_TABLE}\`;")"
  if [[ "$TARGET_APP_PROBE_COUNT" != "$APP_PROBE_COUNT_BEFORE" ]]; then
    echo "Restored $APP_PROBE_TABLE row count mismatch ($TARGET_APP_PROBE_COUNT vs $APP_PROBE_COUNT_BEFORE)." >&2
    exit 1
  fi
fi

TARGET_MARKER_HASH="$(sha256_file "$TARGET_STORAGE_APP/$PROOF_MARKER_REL")"
if [[ "$TARGET_MARKER_HASH" != "$SOURCE_MARKER_HASH_BEFORE" ]]; then
  echo "Restored marker hash does not match source/manifest." >&2
  exit 1
fi

# Drop only the disposable schema this run created.
"${MYSQL[@]}" -e "DROP DATABASE IF EXISTS \`${OWNED_TARGET_DB}\`;"
OWNED_TARGET_DB=""

cat > "$EVIDENCE" <<EOF
{
  "proved_at": "$(date -Iseconds)",
  "engine": "mysql",
  "status": "ok",
  "remote_staging_mysql_restore": "pending_until_host_access",
  "source_database": "${MYSQL_SOURCE_DATABASE}",
  "target_database": "${MYSQL_TARGET_DATABASE}",
  "target_database_policy": "unique-or-absent-create-only; drop-only-if-created-by-this-run",
  "dump_file": "$(basename "$DUMP_FILE")",
  "dump_sha256": "${DUMP_SHA}",
  "storage_artifact": "$(basename "$STORAGE_TAR")",
  "storage_artifact_sha256": "${STORAGE_TAR_SHA}",
  "storage_manifest": "$(basename "$STORAGE_MANIFEST")",
  "fixture": {
    "proof_key": "${PROOF_KEY}",
    "marker_rel": "${PROOF_MARKER_REL}",
    "created_in_source_before_dump": true,
    "cleaned_after_proof": true,
    "note": "Fixture create/cleanup are intentional and distinct from restore side-effects."
  },
  "source_during_restore": {
    "proof_unchanged": true,
    "marker_hash_unchanged": true,
    "application_table_count_unchanged": true,
    "application_table_count": ${APP_TABLE_COUNT_PRE_RESTORE},
    "application_probe_table": "${APP_PROBE_TABLE}",
    "application_probe_count_unchanged": $([[ -n "$APP_PROBE_TABLE" ]] && echo true || echo null)
  },
  "restored": {
    "proof_value_ok": true,
    "application_table_count": ${TARGET_APP_TABLES},
    "application_table_names_sample": "${APP_TABLE_NAMES_BEFORE}",
    "application_probe_table": "${APP_PROBE_TABLE}",
    "application_probe_count": ${TARGET_APP_PROBE_COUNT:-null},
    "storage_hashes_match_manifest": true
  },
  "note": "zashpos:backup was not used; mysqldump + separate storage tar only. APP_KEY not in artifacts."
}
EOF

echo "MySQL backup/restore proof OK → $EVIDENCE"
# EXIT trap removes source fixtures and unsets MYSQL_PWD.
