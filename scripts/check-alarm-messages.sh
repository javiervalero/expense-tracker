#!/usr/bin/env bash
# Usage: scripts/check-alarm-messages.sh [module_dir [src_dir]]
#   (defaults: infra/terraform/modules/observability and backend/src)
# The CloudWatch metric filters of modules/observability match text that the PHP code logs (Phase 7 plan, D22). If a
# message is renamed in PHP, its filter silently stops matching and its alarm can never fire. This guard reads the
# literals from the one list the module keeps for that purpose, between the lines `# alarm-messages:begin` and
# `# alarm-messages:end` in the module's .tf files (one `message = "..."` per entry), and fails when one of them no
# longer appears in a *.php file under src_dir.
# A line that starts with `*`, `//`, `/*` or `#` is a comment and does not count: a literal that survives only in a
# docblock means the code was renamed. A literal is matched as plain text (no regular expression), anywhere in a code
# line, because the alarm pattern is a substring match too. A literal that is also a failure code (`CorruptItem`) is
# found where the constant is defined.
# Limits: a message built at run time (concatenation, a sprintf) is not seen; the guard does not prove that the
# filter syntax matches a real log line (Phase 8 runs the read-only `test-metric-filter` for that).
# Exit: 0 every literal found, 1 at least one is gone (named on stderr), 2 bad usage, unreadable directory, or no
# literal to check (a guard that checks nothing must not pass).
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ "$#" -gt 2 ]; then
  echo "usage: check-alarm-messages.sh [module_dir [src_dir]]" >&2
  exit 2
fi
module_dir="${1:-$REPO/infra/terraform/modules/observability}"
src_dir="${2:-$REPO/backend/src}"

for dir in "$module_dir" "$src_dir"; do
  if [ ! -d "$dir" ]; then
    echo "check-alarm-messages: not a directory: $dir" >&2
    exit 2
  fi
done
case "$module_dir" in -*) module_dir="./$module_dir" ;; esac
case "$src_dir" in -*) src_dir="./$src_dir" ;; esac

# Prints the literals, one per line. A carriage return (CRLF files) is dropped.
literals() {
  find -L "$module_dir" -type f -name '*.tf' -exec awk '
    { sub(/\r$/, "") }
    /alarm-messages:begin/ { listed = 1; next }
    /alarm-messages:end/ { listed = 0; next }
    listed && match($0, /message[ \t]*=[ \t]*"[^"]*"/) {
      entry = substr($0, RSTART, RLENGTH)
      sub(/^[^"]*"/, "", entry)
      sub(/"$/, "", entry)
      print entry
    }
  ' {} +
}

# Succeeds when the literal is in a code line of a *.php file under src_dir.
in_code() {
  local hits
  hits="$(LITERAL="$1" find -L "$src_dir" -type f -name '*.php' -exec awk '
    index($0, ENVIRON["LITERAL"]) && $0 !~ /^[ \t]*(\*|\/\/|\/\*|#)/ { print FILENAME; exit }
  ' {} +)"
  [ -n "$hits" ]
}

found_any=0
missing=0
while IFS= read -r literal; do
  if [ -z "$literal" ]; then
    echo "check-alarm-messages: an empty message in the guarded list of $module_dir" >&2
    exit 2
  fi
  found_any=1
  if in_code "$literal"; then
    echo "check-alarm-messages: ok: $literal"
  else
    echo "check-alarm-messages: missing from $src_dir: $literal" >&2
    missing=1
  fi
done < <(literals)

if [ "$found_any" -eq 0 ]; then
  echo "check-alarm-messages: no message between alarm-messages:begin and alarm-messages:end in $module_dir" >&2
  exit 2
fi
exit "$missing"
