#!/usr/bin/env bash
# Usage: scripts/check-prevent-destroy.sh [--require-bucket] [dir...]   (default: infra/terraform/bootstrap)
# --require-bucket: exit 2 when no `resource "aws_s3_bucket"` exists under the directories (make tf-unit uses it).
# Every `resource "aws_s3_bucket*"` block in the .tf files under the given directories must contain
# `prevent_destroy = true` (Phase 7 plan, D5: the state bucket and its configuration must survive a stray destroy).
# `terraform test` cannot assert it (the resource has no `lifecycle` attribute), so this is a text check.
# A resource block ends at a line that is exactly `}` (terraform fmt style). A commented-out line (# or //) does not
# count, and neither does `prevent_destroy = false`. Symlinked directories are followed (as Terraform does).
# Exit: 0 all protected (or no .tf files), 1 a resource lacks it (named on stderr), 2 bad usage or an unreadable directory.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
require_bucket=0
if [ "${1:-}" = "--require-bucket" ]; then
  require_bucket=1
  shift
fi
if [ "$#" -eq 0 ]; then
  set -- "$REPO/infra/terraform/bootstrap"
fi

dirs=()
for dir in "$@"; do
  if [ ! -d "$dir" ]; then
    echo "check-prevent-destroy: not a directory: $dir" >&2
    exit 2
  fi
  case "$dir" in
    -*) dir="./$dir" ;; # a leading dash must not be read as a find option
  esac
  dirs+=("$dir")
done

LIST="$(mktemp)"
trap 'rm -f "$LIST"' EXIT

# The scan result goes to a file first, so a find failure (for example an unreadable directory) stops the check
# instead of looking like "no .tf files".
if ! find -L "${dirs[@]}" -type f -name '*.tf' -print0 >"$LIST"; then
  echo "check-prevent-destroy: could not scan every directory" >&2
  exit 2
fi

# Prints "<type>.<name>" for every aws_s3_bucket* resource block of the file that lacks an active
# `prevent_destroy = true` line.
unprotected() {
  awk '
    function finish() {
      if (name != "" && !protected) { print name }
      name = ""
      protected = 0
    }
    /^resource[[:space:]]+"aws_s3_bucket[A-Za-z0-9_]*"[[:space:]]+"[^"]+"[[:space:]]*\{/ {
      finish()
      split($0, q, "\"")
      name = q[2] "." q[4]
      next
    }
    name != "" && /^}[[:space:]]*$/ { finish(); next }
    name != "" && /^[[:space:]]*prevent_destroy[[:space:]]*=[[:space:]]*true[[:space:]]*(#.*|\/\/.*)?$/ { protected = 1 }
    END { finish() }
  ' "$1"
}

status=0
seen_bucket=0
while IFS= read -r -d '' file; do
  if grep -qE '^resource[[:space:]]+"aws_s3_bucket"[[:space:]]+"' "$file"; then
    seen_bucket=1
  fi
  if ! missing="$(unprotected "$file")"; then
    echo "check-prevent-destroy: could not read $file" >&2
    exit 2
  fi
  [ -z "$missing" ] && continue
  while IFS= read -r resource; do
    echo "check-prevent-destroy: $resource in $file has no 'prevent_destroy = true'" >&2
  done <<<"$missing"
  status=1
done <"$LIST"

# With --require-bucket, finding no state bucket at all is an error: the check would otherwise pass on a renamed or
# emptied directory and protect nothing.
if [ "$require_bucket" -eq 1 ] && [ "$seen_bucket" -eq 0 ]; then
  echo "check-prevent-destroy: no aws_s3_bucket resource found under: $*" >&2
  exit 2
fi
exit "$status"
