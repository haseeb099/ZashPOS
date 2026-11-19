#!/usr/bin/env bash
# Shared safety helpers for MySQL backup/restore proof (sourced, not executed alone).
# No network or filesystem mutations except readlink/stat of paths under test.

assert_sql_identifier() {
  local name="$1"
  local label="${2:-identifier}"
  if [[ -z "$name" ]]; then
    echo "Refusing empty $label." >&2
    return 1
  fi
  if [[ ${#name} -gt 64 ]]; then
    echo "Refusing $label longer than 64 characters." >&2
    return 1
  fi
  if [[ ! "$name" =~ ^[A-Za-z0-9_]+$ ]]; then
    echo "Refusing unsafe $label \"$name\" (allowed: A-Za-z0-9_ only)." >&2
    return 1
  fi
  case "$name" in
    mysql|information_schema|performance_schema|sys)
      echo "Refusing reserved schema name \"$name\"." >&2
      return 1
      ;;
  esac
  return 0
}

canonicalize_path() {
  local input="$1"
  if [[ -z "$input" ]]; then
    echo "Refusing empty path." >&2
    return 1
  fi
  local resolved
  if command -v realpath >/dev/null 2>&1; then
    resolved="$(realpath -m "$input" 2>/dev/null)" || resolved=""
  fi
  if [[ -z "$resolved" ]]; then
    # Fallback: absolute-ize without requiring the leaf to exist.
    local dir base
    dir="$(dirname -- "$input")"
    base="$(basename -- "$input")"
    if command -v realpath >/dev/null 2>&1; then
      dir="$(realpath -m "$dir")"
    else
      dir="$(cd "$dir" 2>/dev/null && pwd -P)" || {
        echo "Unable to resolve directory for path: $input" >&2
        return 1
      }
    fi
    resolved="${dir%/}/${base}"
  fi
  # Collapse duplicate slashes (keep leading slash / Windows drive).
  resolved="$(printf '%s' "$resolved" | sed -E 's#/+#/#g')"
  printf '%s\n' "$resolved"
}

is_filesystem_root() {
  local p="$1"
  case "$p" in
    /|//|[A-Za-z]:|[A-Za-z]:/|[A-Za-z]:\\) return 0 ;;
  esac
  return 1
}

paths_overlap_or_equal() {
  local a="$1"
  local b="$2"
  [[ -z "$a" || -z "$b" ]] && return 0
  [[ "$a" == "$b" ]] && return 0
  [[ "$a" == "$b"/* ]] && return 0
  [[ "$b" == "$a"/* ]] && return 0
  # Windows-ish slash variants already normalized by canonicalize.
  return 1
}

assert_safe_storage_pair() {
  local source_raw="$1"
  local target_raw="$2"
  local safe_root_raw="$3"

  local source target safe_root
  source="$(canonicalize_path "$source_raw")" || return 1
  target="$(canonicalize_path "$target_raw")" || return 1
  safe_root="$(canonicalize_path "$safe_root_raw")" || return 1

  if is_filesystem_root "$source" || is_filesystem_root "$target" || is_filesystem_root "$safe_root"; then
    echo "Refusing filesystem root as storage path." >&2
    return 1
  fi

  if paths_overlap_or_equal "$source" "$target"; then
    echo "Refusing overlapping source/target storage paths:" >&2
    echo "  source=$source" >&2
    echo "  target=$target" >&2
    return 1
  fi

  if [[ "$target" != "$safe_root" && "$target" != "$safe_root"/* ]]; then
    echo "Refusing target outside disposable safe root:" >&2
    echo "  target=$target" >&2
    echo "  safe_root=$safe_root" >&2
    return 1
  fi

  if [[ -e "$target" ]]; then
    echo "Refusing existing target storage path (will not rm -rf): $target" >&2
    return 1
  fi

  # Export for callers.
  CANON_SOURCE_STORAGE="$source"
  CANON_TARGET_STORAGE="$target"
  CANON_SAFE_ROOT="$safe_root"
  return 0
}

sha256_file() {
  local file="$1"
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$file" | awk '{print $1}'
  elif command -v shasum >/dev/null 2>&1; then
    shasum -a 256 "$file" | awk '{print $1}'
  else
    echo "sha256sum/shasum required" >&2
    return 1
  fi
}

# Relative path under a storage root: no absolute, no traversal, no empty segments.
assert_safe_relative_path() {
  local rel="$1"
  local label="${2:-relative path}"
  if [[ -z "$rel" ]]; then
    echo "Refusing empty $label." >&2
    return 1
  fi
  case "$rel" in
    /*|[A-Za-z]:*|*\\*)
      echo "Refusing absolute or drive-qualified $label: $rel" >&2
      return 1
      ;;
  esac
  if [[ "$rel" == *".."* ]]; then
    echo "Refusing traversal in $label: $rel" >&2
    return 1
  fi
  if [[ "$rel" == /* || "$rel" == */ || "$rel" == *"//"* ]]; then
    echo "Refusing unsafe slash pattern in $label: $rel" >&2
    return 1
  fi
  return 0
}

# Create a uniquely named private directory under storage_root and an exclusive marker file.
# Sets: OWNED_MARKER_DIR OWNED_MARKER_PATH OWNED_MARKER_REL PROOF_MARKER_REL
create_owned_storage_marker() {
  local storage_root="$1"
  local stamp="$2"
  local rand="$3"
  local probe_value="$4"

  local root_canon dir_rel marker_rel marker_dir marker_path
  root_canon="$(canonicalize_path "$storage_root")" || return 1
  if [[ ! -d "$root_canon" ]]; then
    echo "SOURCE_STORAGE_APP is not a directory: $root_canon" >&2
    return 1
  fi

  dir_rel="zashpos-mysql-proof-${stamp}-${rand}"
  marker_rel="${dir_rel}/probe.txt"
  assert_safe_relative_path "$marker_rel" "owned marker relative path" || return 1

  marker_dir="$(canonicalize_path "$root_canon/$dir_rel")" || return 1
  marker_path="$(canonicalize_path "$root_canon/$marker_rel")" || return 1

  # Marker must resolve inside the canonical storage root (blocks symlink escapes).
  if [[ "$marker_dir" != "$root_canon" && "$marker_dir" != "$root_canon"/* ]]; then
    echo "Refusing marker directory outside storage root: $marker_dir" >&2
    return 1
  fi
  if [[ "$marker_path" != "$root_canon"/* ]]; then
    echo "Refusing marker path outside storage root: $marker_path" >&2
    return 1
  fi
  if [[ -e "$marker_dir" || -e "$marker_path" || -L "$marker_dir" || -L "$marker_path" ]]; then
    echo "Refusing pre-existing marker path (no overwrite): $marker_path" >&2
    return 1
  fi

  # Plain mkdir first — `mkdir -m` can fail on Windows/Git Bash even when create succeeds.
  if ! mkdir "$marker_dir"; then
    echo "Failed to create private marker directory: $marker_dir" >&2
    return 1
  fi
  chmod 700 "$marker_dir" 2>/dev/null || true

  # Exclusive create: noclobber fails if the file already exists.
  if ! (
    set -C
    printf '%s' "$probe_value" > "$marker_path"
  ); then
    rmdir "$marker_dir" 2>/dev/null || true
    echo "Failed exclusive marker create (collision): $marker_path" >&2
    return 1
  fi

  # Re-check canonicalization after create (directory could have been replaced by a symlink).
  local dir_after path_after
  dir_after="$(canonicalize_path "$marker_dir")" || return 1
  path_after="$(canonicalize_path "$marker_path")" || return 1
  if [[ "$dir_after" != "$root_canon"/* || "$path_after" != "$root_canon"/* ]]; then
    rm -f "$marker_path" 2>/dev/null || true
    rmdir "$marker_dir" 2>/dev/null || true
    echo "Marker escaped storage root after create; removed and aborting." >&2
    return 1
  fi

  OWNED_MARKER_DIR="$dir_after"
  OWNED_MARKER_PATH="$path_after"
  OWNED_MARKER_REL="$marker_rel"
  PROOF_MARKER_REL="$marker_rel"
  return 0
}

remove_owned_storage_marker() {
  local marker_path="${1:-}"
  local marker_dir="${2:-}"
  if [[ -n "$marker_path" && -f "$marker_path" ]]; then
    rm -f "$marker_path" || return 1
  fi
  if [[ -n "$marker_dir" && -d "$marker_dir" ]]; then
    rmdir "$marker_dir" 2>/dev/null || true
  fi
  if [[ -n "$marker_path" && -e "$marker_path" ]]; then
    echo "Owned marker still present after cleanup: $marker_path" >&2
    return 1
  fi
  return 0
}
