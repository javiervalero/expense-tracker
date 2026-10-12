#!/usr/bin/env bash
# Usage: scripts/check-fixtures.sh <dir-a> <dir-b>
# The *.json files of the two directories must be the same set and byte-identical (the S3 event fixtures live in
# backend/ and docker/local-aws/, separate Composer projects). Exit: 0 identical, 1 differ, 2 bad usage.
set -euo pipefail

if [ "$#" -ne 2 ]; then
  echo "usage: $0 <dir-a> <dir-b>" >&2
  exit 2
fi
a="$1"
b="$2"
for dir in "$a" "$b"; do
  if [ ! -d "$dir" ]; then
    echo "check-fixtures: not a directory: $dir" >&2
    exit 2
  fi
done

shopt -s nullglob
names=()
for f in "$a"/*.json "$b"/*.json; do
  names+=("$(basename "$f")")
done
if [ "${#names[@]}" -eq 0 ]; then
  echo "check-fixtures: no *.json files in $a or $b" >&2
  exit 2
fi

status=0
while IFS= read -r name; do
  if [ ! -f "$a/$name" ] || [ ! -f "$b/$name" ]; then
    echo "check-fixtures: $name exists in only one of $a and $b" >&2
    status=1
  elif ! cmp -s "$a/$name" "$b/$name"; then
    echo "check-fixtures: $name differs between $a and $b" >&2
    status=1
  fi
done < <(printf '%s\n' "${names[@]}" | sort -u)

[ "$status" -eq 0 ] && echo "check-fixtures: the JSON fixtures in $a and $b are identical"
exit "$status"
