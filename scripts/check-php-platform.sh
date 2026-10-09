#!/usr/bin/env bash
# Guard: the PHP that Composer resolves against (config.platform.php in backend/composer.json) must be the
# PHP that really runs in the Lambda layer (the bref/php-85 image pinned by digest in the worker Dockerfile).
# If they drift, Composer could install packages that need a PHP the layer does not have.
#
# Usage: scripts/check-php-platform.sh [--composer-json FILE] [--with-vendor] [DOCKERFILE]
#   DOCKERFILE        Dockerfile holding the `FROM bref/php-85:<tag>@sha256:<digest>` line
#                     (default: docker/worker/Dockerfile)
#   --composer-json   composer.json to read config.platform.php from (default: backend/composer.json)
#   --with-vendor     also run `composer install --no-dev` into build/vendor-check/ (dev image) and require
#                     vendor/autoload.php inside the bref image, so the production vendor tree is proven to
#                     load on the layer's PHP (Composer's own platform_check.php runs there too)
#
# Environment: USE_JQ=0 reads composer.json with PHP inside the bref image instead of jq (the default when jq
# is not installed); VENDOR_CHECK_DIR overrides build/vendor-check; PHP_IMAGE is the dev image that has
# Composer (default expense-tracker-php:dev, built by `make php-image`).
#
# Exit codes: 0 = match, 1 = mismatch or the vendor check failed, 2 = bad usage or unreadable input,
# 3 = no `bref/php-85` FROM line with a digest in the Dockerfile.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCKERFILE="$ROOT/docker/worker/Dockerfile"
COMPOSER_JSON="$ROOT/backend/composer.json"
WITH_VENDOR=0
VENDOR_CHECK_DIR="${VENDOR_CHECK_DIR-$ROOT/build/vendor-check}"
PHP_IMAGE="${PHP_IMAGE:-expense-tracker-php:dev}"
USE_JQ="${USE_JQ:-1}"

usage() {
  sed -n '/^# Usage:/,/^#   --with-vendor/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//' >&2
}

die() { # <exit code> <message>
  local code="$1"
  shift
  printf 'check-php-platform: %s\n' "$*" >&2
  exit "$code"
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --composer-json)
      [ "$#" -ge 2 ] || { usage; die 2 'Usage error: --composer-json needs a file'; }
      COMPOSER_JSON="$2"
      shift 2
      ;;
    --with-vendor)
      WITH_VENDOR=1
      shift
      ;;
    -h | --help)
      usage
      exit 0
      ;;
    -*)
      usage
      die 2 "Usage error: unknown option $1"
      ;;
    *)
      DOCKERFILE="$1"
      shift
      ;;
  esac
done

[ -f "$DOCKERFILE" ] || die 2 "Dockerfile not found: $DOCKERFILE"
[ -f "$COMPOSER_JSON" ] || die 2 "composer.json not found: $COMPOSER_JSON"

# Docker mount syntax treats ':' and ',' as separators, so paths holding them could smuggle extra mount options.
reject_mount_separators() { # <label> <path>
  case "$2" in
    *[:,]*) die 2 "$1 must not contain ':' or ',' (docker mount syntax): $2" ;;
  esac
}

reject_mount_separators '--composer-json' "$COMPOSER_JSON"
COMPOSER_JSON="$(cd "$(dirname "$COMPOSER_JSON")" && pwd -P)/$(basename "$COMPOSER_JSON")"
reject_mount_separators '--composer-json' "$COMPOSER_JSON"

# VENDOR_CHECK_DIR is deleted with rm -rf, so it must be a directory this script may own: strictly inside
# build/ of the repository or inside the temp dir. Checked up front, long before anything is removed.
guard_vendor_check_dir() {
  [ -n "$VENDOR_CHECK_DIR" ] || die 2 'VENDOR_CHECK_DIR is empty: refusing to delete it'
  local resolved temp_root build_root
  resolved="$(realpath -m -- "$VENDOR_CHECK_DIR")"
  temp_root="$(realpath -m -- "${TMPDIR:-/tmp}")"
  build_root="$(realpath -m -- "$ROOT/build")"
  local forbidden
  for forbidden in / "${HOME:-/}" "$ROOT" "$build_root" "$temp_root"; do
    if [ "$resolved" = "$(realpath -m -- "$forbidden")" ]; then
      die 2 "VENDOR_CHECK_DIR must not be $resolved: refusing to delete it"
    fi
  done
  case "$resolved" in
    "$build_root"/* | "$temp_root"/*) ;;
    *) die 2 "VENDOR_CHECK_DIR must be inside $build_root/ or $temp_root/, got $resolved: refusing to delete it" ;;
  esac
  reject_mount_separators VENDOR_CHECK_DIR "$resolved"
  VENDOR_CHECK_DIR="$resolved"
}

if [ "$WITH_VENDOR" = 1 ]; then guard_vendor_check_dir; fi

# First `FROM [--platform=...] bref/php-85:<tag>@sha256:<64 hex>` line; bref/php-85-dev and the extra-*
# layers do not match because the name must be followed by `:`.
IMAGE="$(sed -nE 's/^[[:space:]]*FROM[[:space:]]+(--platform=[^[:space:]]+[[:space:]]+)?(bref\/php-85:[^@[:space:]]+@sha256:[0-9a-f]{64}).*$/\2/Ip' "$DOCKERFILE" | head -n 1)"
if [ -z "$IMAGE" ]; then
  die 3 "no 'FROM bref/php-85:<tag>@sha256:<digest>' line (a digest is required, @sha256) in $DOCKERFILE"
fi

read_platform_with_jq() {
  jq -er '.config.platform.php // empty' "$COMPOSER_JSON"
}

read_platform_with_php() {
  docker run --rm --entrypoint php \
    --mount "type=bind,src=$COMPOSER_JSON,dst=/check/composer.json,readonly" "$IMAGE" -r '
    $json = json_decode((string) file_get_contents("/check/composer.json"), true);
    $php = is_array($json) ? ($json["config"]["platform"]["php"] ?? "") : "";
    echo is_string($php) ? $php : "";'
}

if [ "$USE_JQ" = 1 ] && command -v jq >/dev/null 2>&1; then
  PLATFORM="$(read_platform_with_jq || true)"
else
  PLATFORM="$(read_platform_with_php || true)"
fi
[ -n "$PLATFORM" ] || die 2 "config.platform.php is not set in $COMPOSER_JSON"

ACTUAL="$(docker run --rm --entrypoint php "$IMAGE" -r 'echo PHP_VERSION;')" ||
  die 2 "could not run PHP in $IMAGE"

if [ "$ACTUAL" != "$PLATFORM" ]; then
  die 1 "MISMATCH: composer.json config.platform.php is $PLATFORM but $IMAGE runs PHP $ACTUAL"
fi
printf 'check-php-platform: OK, config.platform.php %s equals PHP %s in %s\n' "$PLATFORM" "$ACTUAL" "$IMAGE"

if [ "$WITH_VENDOR" = 1 ]; then
  rm -rf "$VENDOR_CHECK_DIR"
  mkdir -p "$VENDOR_CHECK_DIR"
  cp "$COMPOSER_JSON" "$VENDOR_CHECK_DIR/composer.json"
  LOCK="$(dirname "$COMPOSER_JSON")/composer.lock"
  if [ -f "$LOCK" ]; then cp "$LOCK" "$VENDOR_CHECK_DIR/composer.lock"; fi

  docker run --rm -u "$(id -u):$(id -g)" \
    --mount "type=bind,src=$VENDOR_CHECK_DIR,dst=/app" -w /app "$PHP_IMAGE" \
    composer install --no-dev --no-plugins --no-scripts --no-progress --prefer-dist --classmap-authoritative ||
    die 1 'composer install --no-dev failed'

  docker run --rm --entrypoint php --mount "type=bind,src=$VENDOR_CHECK_DIR,dst=/check,readonly" "$IMAGE" \
    -r 'require "/check/vendor/autoload.php"; echo "vendor/autoload.php loads", PHP_EOL;' ||
    die 1 "vendor/autoload.php does not load inside $IMAGE"
fi
