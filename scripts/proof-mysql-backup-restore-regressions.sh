#!/usr/bin/env bash
# Executable regressions for unsafe MySQL proof inputs.
# Must fail before any dump, DROP, CREATE, or rm -rf.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=lib/mysql-backup-proof-safety.sh
source "$ROOT/scripts/lib/mysql-backup-proof-safety.sh"
PROOF="$ROOT/scripts/proof-mysql-backup-restore.sh"
SAFE_ROOT="$ROOT/storage/framework/testing"
PASS=0
FAIL=0

expect_fail() {
  local title="$1"
  shift
  if "$@" >/tmp/zashpos-mysql-proof-reg.out 2>/tmp/zashpos-mysql-proof-reg.err; then
    echo "FAIL: expected failure: $title" >&2
    cat /tmp/zashpos-mysql-proof-reg.err >&2 || true
    FAIL=$((FAIL + 1))
  else
    echo "OK: $title"
    PASS=$((PASS + 1))
  fi
}

expect_ok() {
  local title="$1"
  shift
  if "$@" >/tmp/zashpos-mysql-proof-reg.out 2>/tmp/zashpos-mysql-proof-reg.err; then
    echo "OK: $title"
    PASS=$((PASS + 1))
  else
    echo "FAIL: expected success: $title" >&2
    cat /tmp/zashpos-mysql-proof-reg.err >&2 || true
    FAIL=$((FAIL + 1))
  fi
}

export PROOF_MYSQL_DRY_RUN=1
export MYSQL_HOST=127.0.0.1
export MYSQL_USER=proof
export MYSQL_PASSWORD=unused-in-dry-run
export MYSQL_SOURCE_DATABASE=zashpos_staging_source

echo "== identifier / source=target =="
expect_fail "invalid SQL identifier" \
  env MYSQL_SOURCE_DATABASE='evil;drop' bash "$PROOF"
expect_fail "reserved schema name" \
  env MYSQL_SOURCE_DATABASE=mysql MYSQL_TARGET_DATABASE=zashpos_rstr_ok bash "$PROOF"
expect_fail "source equals target database" \
  env MYSQL_TARGET_DATABASE=zashpos_staging_source bash "$PROOF"

echo "== storage path overlaps / existing target =="
NEST="$SAFE_ROOT/reg-nest-$$"
mkdir -p "$NEST/child"
expect_fail "equal source and target storage" \
  env SOURCE_STORAGE_APP="$NEST" TARGET_STORAGE_APP="$NEST" bash "$PROOF"
expect_fail "target nested under source" \
  env SOURCE_STORAGE_APP="$NEST" TARGET_STORAGE_APP="$NEST/child" bash "$PROOF"
expect_fail "source nested under target" \
  env SOURCE_STORAGE_APP="$NEST/child" TARGET_STORAGE_APP="$NEST" bash "$PROOF"

EXISTING="$SAFE_ROOT/reg-existing-$$"
mkdir -p "$EXISTING"
expect_fail "existing target storage path (no rm -rf)" \
  env SOURCE_STORAGE_APP="$ROOT/storage/app" TARGET_STORAGE_APP="$EXISTING" bash "$PROOF"

OUTSIDE="$ROOT/storage/app/not-a-safe-target-$$"
expect_fail "target outside safe testing root" \
  env SOURCE_STORAGE_APP="$ROOT/storage/app" TARGET_STORAGE_APP="$OUTSIDE" bash "$PROOF"

echo "== helper unit checks =="
expect_fail "assert_sql_identifier rejects slash" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; assert_sql_identifier "a/b" "x"'
expect_ok "assert_sql_identifier accepts underscore name" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; assert_sql_identifier "zashpos_rstr_1" "x"'

expect_ok "paths_overlap detects ancestor (exit 0 = overlap)" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; paths_overlap_or_equal "/tmp/a" "/tmp/a/b"'
expect_ok "paths_overlap allows siblings (non-zero = no overlap)" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; if paths_overlap_or_equal "/tmp/a" "/tmp/b"; then exit 1; fi'

echo "== dry-run happy path (no destructive commands) =="
FRESH="$SAFE_ROOT/reg-fresh-$$"
expect_ok "dry-run with unique target name and fresh storage path" \
  env MYSQL_TARGET_DATABASE="zashpos_rstr_reg_${RANDOM}" \
      SOURCE_STORAGE_APP="$ROOT/storage/app" \
      TARGET_STORAGE_APP="$FRESH" \
      bash "$PROOF"
expect_ok "PROOF_MARKER_REL override is ignored in dry-run" \
  env MYSQL_TARGET_DATABASE="zashpos_rstr_reg_${RANDOM}" \
      SOURCE_STORAGE_APP="$ROOT/storage/app" \
      TARGET_STORAGE_APP="$SAFE_ROOT/reg-fresh-override-$$" \
      PROOF_MARKER_REL='../../evil.txt' \
      bash "$PROOF"

echo "== marker path safety / exclusive create / owned cleanup =="
MARKER_SRC="$SAFE_ROOT/reg-marker-src-$$"
mkdir -p "$MARKER_SRC"
VICTIM="$MARKER_SRC/preexisting-victim.txt"
printf 'do-not-touch' > "$VICTIM"
VICTIM_HASH="$(sha256_file "$VICTIM")"

expect_fail "relative path rejects traversal" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; assert_safe_relative_path "../etc/passwd" "x"'
expect_fail "relative path rejects absolute" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; assert_safe_relative_path "/tmp/x" "x"'

COLLIDE_STAMP="collide${RANDOM}"
COLLIDE_RAND="abcd"
COLLIDE_DIR="$MARKER_SRC/zashpos-mysql-proof-${COLLIDE_STAMP}-${COLLIDE_RAND}"
mkdir -p "$COLLIDE_DIR"
printf 'collision-victim' > "$COLLIDE_DIR/probe.txt"
COLLIDE_HASH="$(sha256_file "$COLLIDE_DIR/probe.txt")"
expect_fail "exclusive marker create refuses pre-existing path" \
  bash -c 'source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"; create_owned_storage_marker "'"$MARKER_SRC"'" "'"$COLLIDE_STAMP"'" "'"$COLLIDE_RAND"'" "probe-value"'
if [[ "$(sha256_file "$COLLIDE_DIR/probe.txt")" == "$COLLIDE_HASH" ]]; then
  echo "OK: collision left existing marker file unchanged"
  PASS=$((PASS + 1))
else
  echo "FAIL: collision overwrote existing marker file" >&2
  FAIL=$((FAIL + 1))
fi

expect_ok "owned marker create + failure cleanup removes only this run marker" \
  bash -c '
    set -euo pipefail
    source "'"$ROOT"'/scripts/lib/mysql-backup-proof-safety.sh"
    src="'"$MARKER_SRC"'"
    keep="'"$VICTIM"'"
    keep_before="$(sha256_file "$keep")"
    create_owned_storage_marker "$src" "fail'"$RANDOM"'" "ef01" "owned-probe"
    test -f "$OWNED_MARKER_PATH"
    owned="$OWNED_MARKER_PATH"
    # Simulate failure after marker creation: clean only owned fixtures.
    remove_owned_storage_marker "$OWNED_MARKER_PATH" "$OWNED_MARKER_DIR"
    test ! -e "$owned"
    test "$(sha256_file "$keep")" = "$keep_before"
  '

if [[ "$(sha256_file "$VICTIM")" == "$VICTIM_HASH" ]]; then
  echo "OK: sibling victim file unchanged after owned marker cleanup"
  PASS=$((PASS + 1))
else
  echo "FAIL: sibling victim file changed" >&2
  FAIL=$((FAIL + 1))
fi

# Cleanup regression dirs (only ones we created under SAFE_ROOT).
rm -rf "$NEST" "$EXISTING" "$MARKER_SRC" "$FRESH" "$SAFE_ROOT/reg-fresh-override-$$" 2>/dev/null || true

echo
echo "Regressions passed=$PASS failed=$FAIL"
if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
exit 0
