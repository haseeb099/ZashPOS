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
