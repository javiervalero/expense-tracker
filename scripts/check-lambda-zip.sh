#!/bin/sh
# Usage: scripts/check-lambda-zip.sh [ZIP]   (default: build/lambda/backend.zip)
# Rules for the Lambda package (Phase 7, D11), read from the zip's entry list:
#   - present: php/conf.d/app.ini, public/index.php, entrypoints/lambda/worker.php, vendor/autoload.php;
#   - absent: the project's own tests/, any .env* file, and anything named phpunit, phpstan or php-cs-fixer;
#   - at most 50 MB zipped and 200 MB unzipped (names are matched case-insensitively).
# That vendor/autoload.php loads in the pinned bref/php-85 image is checked by `make lambda-check` (it needs Docker).
# POSIX sh on purpose: `make lambda-check` runs it in the tools image (Alpine, no bash), which has unzip.
# Exit: 0 fine, 1 a rule is broken, 2 bad usage or a file that is not a zip.
set -eu

MAX_BYTES=52428800
# Lambda allows 250 MB for the package and its layers (PHP, gd) together, unzipped; 200 MB leaves room for the layers.
MAX_UNZIPPED_BYTES=209715200
zip_file="${1:-build/lambda/backend.zip}"

if [ "$#" -gt 1 ] || [ ! -f "$zip_file" ]; then
  echo "check-lambda-zip: usage: check-lambda-zip.sh ZIP (not a file: $zip_file)" >&2
  exit 2
fi

entries="$(unzip -Z1 "$zip_file" 2>/dev/null)" || {
  echo "check-lambda-zip: $zip_file is not a zip file" >&2
  exit 2
}

status=0
for required in php/conf.d/app.ini public/index.php entrypoints/lambda/worker.php vendor/autoload.php; do
  if ! printf '%s\n' "$entries" | grep -qxF "$required"; then
    echo "check-lambda-zip: missing $required" >&2
    status=1
  fi
done

forbidden="$(printf '%s\n' "$entries" | grep -iE '^tests/|(^|/)\.env[^/]*$|(^|/)[^/]*(phpunit|phpstan|php-cs-fixer)[^/]*(/|$)' || true)"
if [ -n "$forbidden" ]; then
  echo "check-lambda-zip: entries that must not be shipped:" >&2
  printf '%s\n' "$forbidden" | head -n 20 >&2
  status=1
fi

size="$(wc -c <"$zip_file")"
if [ "$size" -gt "$MAX_BYTES" ]; then
  echo "check-lambda-zip: $zip_file is $size bytes, over the 50 MB limit ($MAX_BYTES)" >&2
  status=1
fi

# `unzip -Zt` prints "N files, X bytes uncompressed, Y bytes compressed: ..."; X is the third field.
unzipped="$(unzip -Zt "$zip_file" | awk '{print $3}')"
case "$unzipped" in
  '' | *[!0-9]*)
    echo "check-lambda-zip: could not read the unzipped size of $zip_file" >&2
    exit 2
    ;;
esac
if [ "$unzipped" -gt "$MAX_UNZIPPED_BYTES" ]; then
  echo "check-lambda-zip: $zip_file is $unzipped bytes unzipped, over the 200 MB limit ($MAX_UNZIPPED_BYTES)" >&2
  status=1
fi

[ "$status" -ne 0 ] || echo "check-lambda-zip: ok ($size bytes zipped, $unzipped unzipped)"
exit "$status"
