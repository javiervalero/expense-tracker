#!/usr/bin/env bash
# Usage: BUNDLE_SECRET_VALUE=<value> scripts/check-bundle-secrets.sh <built-dir>
# Fails when the value appears in any file under <built-dir>: as a literal string, base64-encoded (in each of the three
# byte alignments, standard and URL-safe alphabet), or hex-encoded. Used on frontend/dist/ with the ORIGIN_VERIFY_LOCAL
# value: the Vite proxy sends it, the browser bundle must never contain it.
#
# The value is read from the environment, never from argv (argv is visible in `ps` and in make's echo). It is
# normalised first: a trailing CR (CRLF .env files) and one pair of surrounding quotes are removed. It is never printed,
# only the names of the files that contain it.
#
# Exit: 0 absent, 1 found, 2 error (bad usage, no dir, empty dir, value missing or shorter than 8 characters, a file
# that could not be read: an unreadable file must never look like "absent").
set -euo pipefail
export LC_ALL=C

MIN_VALUE_LENGTH=8

if [ "$#" -ne 1 ]; then
  echo "usage: BUNDLE_SECRET_VALUE=<value> $0 <built-dir>" >&2
  exit 2
fi
dir="$1"
value="${BUNDLE_SECRET_VALUE:-}"

# Normalise: strip every trailing CR, then one pair of matching surrounding quotes.
value="${value%"${value##*[!$'\r']}"}"
case "$value" in
  \"*\") value="${value#\"}"; value="${value%\"}" ;;
  \'*\') value="${value#\'}"; value="${value%\'}" ;;
esac

if [ "${#value}" -lt "$MIN_VALUE_LENGTH" ]; then
  echo "check-bundle-secrets: BUNDLE_SECRET_VALUE is missing or shorter than $MIN_VALUE_LENGTH characters; refusing to pass vacuously" >&2
  exit 2
fi
if [ ! -d "$dir" ]; then
  echo "check-bundle-secrets: not a directory: $dir (build first)" >&2
  exit 2
fi
if [ -z "$(find "$dir" -type f -print -quit)" ]; then
  echo "check-bundle-secrets: $dir has no files; nothing was built" >&2
  exit 2
fi

# The base64 text that must appear whatever bytes surround the value. The value can start at byte offset 0, 1 or 2 of a
# 3-byte group. Characters that also depend on a neighbouring byte are dropped: with a k-byte prefix the first
# ceil(8k/6) characters, and, when the last group is incomplete, the last character.
base64_forms() {
  local v="$1" k prefix encoded drop_front total remainder
  for k in 0 1 2; do
    prefix="$(printf '%*s' "$k" '' | tr ' ' 'A')"
    encoded="$(printf '%s%s' "$prefix" "$v" | base64 | tr -d '\n=')"
    drop_front=$(((8 * k + 5) / 6))
    total=$((${#v} + k))
    remainder=$((total % 3))
    encoded="${encoded:$drop_front}"
    if [ "$remainder" -ne 0 ]; then
      encoded="${encoded:0:${#encoded}-1}"
    fi
    printf '%s\n' "$encoded"
    printf '%s\n' "$encoded" | tr '+/' '-_'
  done
}

hex_form() {
  printf '%s' "$1" | od -An -tx1 | tr -d ' \n'
}

# grep: 0 = a match, 1 = no match, 2 or more = an error (an unreadable file, for example). The patterns are given on
# file descriptors (process substitution), so the value is in no argument list and in no temporary file.
# -F: literal strings (the value may contain regex characters); -r: recurse; -l: only the file names.
found=''
errors=0
search() { # <extra grep flags> <patterns...>
  local flags="$1" status=0 hits
  shift
  hits="$(grep -rlF $flags -f <(printf '%s\n' "$@") -- "$dir")" || status=$?
  if [ "$status" -ge 2 ]; then
    errors=1
  fi
  if [ "$status" -ne 1 ] && [ -n "$hits" ]; then
    found+="$hits"$'\n'
  fi
}

search '' "$value" $(base64_forms "$value")
search '-i' "$(hex_form "$value")"

if [ "$errors" -ne 0 ]; then
  echo "check-bundle-secrets: grep could not read every file under $dir; the result cannot be trusted" >&2
  exit 2
fi
if [ -n "$found" ]; then
  echo "check-bundle-secrets: the origin-verify value is present in:" >&2
  printf '%s' "$found" | sort -u | sed 's/^/  /' >&2
  exit 1
fi
echo "check-bundle-secrets: the value is not present in $dir"
