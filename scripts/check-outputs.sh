#!/usr/bin/env bash
# Usage: scripts/check-outputs.sh [outputs_file]   (default: infra/terraform/envs/dev/outputs.tf)
# Phase 7 plan, D26 (distribution_id added in Phase 8): the dev root exports exactly eight outputs (distribution_domain,
# distribution_id, site_bucket, receipts_bucket, thumbnails_bucket, api_endpoint, dlq_url, table_name) and never an
# origin-verify secret. A `terraform test` can name
# outputs but cannot list them, so an extra output that carries a secret would go unseen there; this static guard
# closes that gap. It fails when:
#   - an `output "<name>"` block has a name outside the eight (the name is printed);
#   - one of the eight is missing (the set is exactly eight);
#   - the file mentions `random_password` or `origin_verify` anywhere (comments included: the file's own header says
#     "origin-verify" with a hyphen on purpose).
# Limits: it reads text only; an output built in another file or a `.tf.json` file is out of its reach.
# Exit: 0 ok, 1 a violation (named on stderr), 2 bad usage or unreadable file.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
if [ "$#" -gt 1 ]; then
  echo "usage: check-outputs.sh [outputs_file]" >&2
  exit 2
fi
file="${1:-$REPO/infra/terraform/envs/dev/outputs.tf}"
if [ ! -f "$file" ]; then
  echo "check-outputs: not a file: $file" >&2
  exit 2
fi

EXPECTED="distribution_domain distribution_id site_bucket receipts_bucket thumbnails_bucket api_endpoint dlq_url table_name"
problems=0

# Names of the output blocks, one per line (a carriage return is dropped).
declared="$(awk '
  { sub(/\r$/, "") }
  match($0, /^[ \t]*output[ \t]+"[^"]*"/) {
    name = substr($0, RSTART, RLENGTH)
    sub(/^[^"]*"/, "", name)
    sub(/"$/, "", name)
    print name
  }
' "$file")"

while IFS= read -r name; do
  [ -n "$name" ] || continue
  case " $EXPECTED " in
    *" $name "*) ;;
    *)
      echo "check-outputs: unexpected output \"$name\" in $file (D26 allows only: $EXPECTED)" >&2
      problems=1
      ;;
  esac
done <<<"$declared"

for name in $EXPECTED; do
  if ! grep -qxF "$name" <<<"$declared"; then
    echo "check-outputs: missing output \"$name\" in $file" >&2
    problems=1
  fi
done

for word in random_password origin_verify; do
  if grep -nF -- "$word" "$file" >&2; then
    echo "check-outputs: $file mentions $word: an origin-verify secret must never reach an output" >&2
    problems=1
  fi
done

if [ "$problems" -eq 0 ]; then
  echo "check-outputs: ok: the eight D26 outputs and no secret in $file"
fi
exit "$problems"
