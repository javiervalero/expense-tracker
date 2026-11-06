#!/usr/bin/env bash
# Usage: scripts/check-tftest-mocks.sh [dir...]   (default: infra/terraform)
# Every Terraform test file under the given directories must mock the aws provider and must not bring a real one, so
# no `terraform test` can reach a real AWS account (Phase 7 plan, rule 3.1):
#   - each *.tftest.hcl declares `mock_provider "aws"` on a line of its own (comments, line or block, do not count);
#   - no *.tftest.hcl has a `provider "aws"` block or a `providers = ...` argument (that would bypass the mock);
#   - *.tftest.json files are rejected (Terraform loads them too, and they are not checked);
#   - symlinks named *.tftest.hcl are checked like files.
# Symlinked directories are followed (as Terraform does). A text scan is a safety net, not the only barrier: the
# wrapper (scripts/terraform.sh) passes no credentials and the provider restricts the account.
# Limits: heredoc strings are not recognised; the mock line is not required to be at the top level of the file; a
# string that contains `provider "aws"` is reported as a real provider (a false alarm, never a miss).
# Exit: 0 all mocked (or no test files), 1 a file breaks a rule, 2 bad usage or a directory that cannot be scanned.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ "$#" -eq 0 ]; then
  set -- "$REPO/infra/terraform"
fi

dirs=()
for dir in "$@"; do
  if [ ! -d "$dir" ]; then
    echo "check-tftest-mocks: not a directory: $dir" >&2
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
# instead of looking like "no test files".
if ! find -L "${dirs[@]}" \( -type f -o -type l \) \( -name '*.tftest.hcl' -o -name '*.tftest.json' \) -print0 >"$LIST"; then
  echo "check-tftest-mocks: could not scan every directory" >&2
  exit 2
fi

# Prints the file without comments: /* ... */ blocks, and everything after # or // on a line. A comment opener inside a
# double-quoted string (or inside another comment) does not count. Strings do not span lines (heredocs: see the limit
# in the header).
strip_comments() {
  awk '
    {
      out = ""
      in_str = 0
      n = length($0)
      i = 1
      while (i <= n) {
        c = substr($0, i, 1)
        two = substr($0, i, 2)
        if (in_block) {
          if (two == "*/") { in_block = 0; i += 2 } else { i++ }
          continue
        }
        if (in_str) {
          out = out c
          if (c == "\\") { out = out substr($0, i + 1, 1); i += 2; continue }
          if (c == "\"") { in_str = 0 }
          i++
          continue
        }
        if (two == "/*") { in_block = 1; i += 2; continue }
        if (c == "#" || two == "//") { break }
        if (c == "\"") { in_str = 1 }
        out = out c
        i++
      }
      print out
    }' "$1"
}

status=0
while IFS= read -r -d '' file; do
  case "$file" in
    *.tftest.json)
      echo "check-tftest-mocks: $file uses the JSON test format, which this check does not read; use .tftest.hcl" >&2
      status=1
      continue
      ;;
  esac
  if ! code="$(strip_comments "$file")"; then
    echo "check-tftest-mocks: could not read $file" >&2
    exit 2
  fi
  if ! grep -qE '^[[:space:]]*mock_provider[[:space:]]*"aws"' <<<"$code"; then
    echo "check-tftest-mocks: $file has no mock_provider \"aws\"" >&2
    status=1
  fi
  # Not anchored to the line start, so `run "x" { providers = { ... } }` on one line is caught. The character before
  # `provider` must not be a letter, digit or underscore, so `mock_provider` is not mistaken for a real provider.
  if grep -qE '(^|[^[:alnum:]_])(provider[[:space:]]*"aws"|providers[[:space:]]*=)' <<<"$code"; then
    echo "check-tftest-mocks: $file declares a real provider or passes providers, which bypasses the mock" >&2
    status=1
  fi
done <"$LIST"
exit "$status"
