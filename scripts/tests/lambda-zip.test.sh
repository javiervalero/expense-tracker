#!/usr/bin/env bash
# Tests for scripts/build-lambda-zip.php and scripts/check-lambda-zip.sh. Run: bash scripts/tests/lambda-zip.test.sh
# Everything runs in the pinned php tools image (make php-image), as the host user: the host has no pinned php, zip or
# composer. Fixtures are tiny and created at run time under build/ (git-ignored). The fixture backend has an empty
# composer.lock, so `composer install` needs no network.
# Exit codes asserted: check-lambda-zip.sh 0 = zip is fine, 1 = a rule is broken, 2 = bad usage.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
cd "$ROOT"

PHP_IMAGE="${PHP_IMAGE:-expense-tracker-php:dev}"
IN_IMAGE=(docker run --rm -u "$(id -u):$(id -g)" -v "$ROOT:/app" -w /app "$PHP_IMAGE")

mkdir -p build
WORK="$(mktemp -d "$ROOT/build/lambda-zip-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
REL="${WORK#"$ROOT"/}" # the same directory as seen from /app inside the container

failures=0
status=0
output=''

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

expect_status() { # <label> <expected>
  [ "$status" -eq "$2" ] || fail "$1: expected exit $2, got $status: $output"
}

run_in_image() { # <command...>: sets status and output
  status=0
  output="$(timeout 300 "${IN_IMAGE[@]}" "$@" 2>&1)" || status=$?
}

check_zip() { # <zip relative to the repository>
  run_in_image sh scripts/check-lambda-zip.sh "$1"
}

# --- Fixture backend --------------------------------------------------------------------------------------------
BACKEND="$WORK/backend"
mkdir -p "$BACKEND/public" "$BACKEND/entrypoints/lambda" "$BACKEND/src" "$BACKEND/php/conf.d" "$BACKEND/tests"
printf '<?php\n' >"$BACKEND/public/index.php"
printf '<?php\n' >"$BACKEND/entrypoints/lambda/worker.php"
printf '<?php\nnamespace App;\nfinal class Hello {}\n' >"$BACKEND/src/Hello.php"
printf 'expose_php=0\n' >"$BACKEND/php/conf.d/app.ini"
printf '<?php\n' >"$BACKEND/tests/HelloTest.php"
printf '{"name":"fixture/backend","require":{},"autoload":{"psr-4":{"App\\\\":"src/"}}}\n' >"$BACKEND/composer.json"
printf '{"_readme":[],"content-hash":"x","packages":[],"packages-dev":[]}\n' >"$BACKEND/composer.lock"

build() { # <build dir> <tfvars file> (relative to the repository)
  run_in_image php scripts/build-lambda-zip.php --backend "$REL/backend" --build-dir "$1" --tfvars "$2"
}

# --- Build: composer runs without plugins or scripts ------------------------------------------------------------
# A Composer plugin of a dependency runs code during `install`; the package build must run none (a supply-chain barrier).
# The command line is asserted on the script's source, because the fixture backend has no dependencies to carry a plugin.
install_line="$(grep -F "'composer', 'install'" scripts/build-lambda-zip.php || true)"
grep -qF "'--no-plugins'" <<<"$install_line" || fail "build: composer install must pass --no-plugins: $install_line"
grep -qF "'--no-scripts'" <<<"$install_line" || fail "build: composer install must pass --no-scripts: $install_line"

# --- Build: shape of the zip ------------------------------------------------------------------------------------
build "$REL/b1" "$REL/pkg1.json"
expect_status 'build' 0
[ -f "$WORK/b1/backend.zip" ] || fail 'build: build/backend.zip was not written'
run_in_image unzip -Z1 "$REL/b1/backend.zip"
for want in php/conf.d/app.ini public/index.php entrypoints/lambda/worker.php vendor/autoload.php src/Hello.php composer.json; do
  grep -qxF "$want" <<<"$output" || fail "build: zip lacks $want"
done
grep -qE '^tests/' <<<"$output" && fail 'build: the zip contains tests/ (it must not be copied)'
# Sorted entries (byte order).
sorted="$(LC_ALL=C sort <<<"$output")"
[ "$sorted" = "$output" ] || fail 'build: zip entries are not sorted'

# tfvars: the two keys, the sha256 of the zip, and a path that reaches the zip from the tfvars directory.
sha_zip="$(sha256sum "$WORK/b1/backend.zip" | cut -d' ' -f1)"
grep -qF "\"lambda_package_sha256\": \"$sha_zip\"" "$WORK/pkg1.json" || fail "build: tfvars lacks the zip sha256: $(cat "$WORK/pkg1.json")"
grep -qF '"lambda_package_path"' "$WORK/pkg1.json" || fail 'build: tfvars lacks lambda_package_path'
[ "$(tail -c 1 "$WORK/pkg1.json" | od -An -c | tr -d ' ')" = '\n' ] || fail 'build: tfvars has no trailing newline'

# --- Reproducible: twice gives the same sha256, even when the source mtimes change ------------------------------
sha_first="$sha_zip"
touch -d '2031-05-05 05:05:05' "$BACKEND/src/Hello.php" "$BACKEND/public/index.php"
chmod 600 "$BACKEND/src/Hello.php"
build "$REL/b2" "$REL/pkg2.json"
expect_status 'second build' 0
sha_second="$(sha256sum "$WORK/b2/backend.zip" | cut -d' ' -f1)"
[ "$sha_first" = "$sha_second" ] || fail "build twice: sha256 differs ($sha_first vs $sha_second)"
cmp -s "$WORK/pkg1.json" "$WORK/pkg2.json" && fail 'build twice: tfvars are equal although the build dirs differ (path should differ)'
# Rebuilding in the same directory also gives the same bytes.
build "$REL/b1" "$REL/pkg1.json"
[ "$(sha256sum "$WORK/b1/backend.zip" | cut -d' ' -f1)" = "$sha_first" ] || fail 'rebuild in the same directory: sha256 differs'
# A changed source file changes the hash.
printf '<?php\n// changed\n' >"$BACKEND/src/Hello.php"
build "$REL/b3" "$REL/pkg3.json"
[ "$(sha256sum "$WORK/b3/backend.zip" | cut -d' ' -f1)" != "$sha_first" ] || fail 'a changed source file did not change the sha256'

# A missing backend directory fails.
run_in_image php scripts/build-lambda-zip.php --backend "$REL/nope" --build-dir "$REL/b4" --tfvars "$REL/pkg4.json"
expect_status 'build with a missing backend' 1
[ ! -e "$WORK/pkg4.json" ] || fail 'failed build wrote a tfvars file'

# --- Check: the built fixture passes ----------------------------------------------------------------------------
check_zip "$REL/b1/backend.zip"
expect_status 'check, good zip' 0

# --- Check: broken zips fail (tiny hand-made zips) --------------------------------------------------------------
make_zip() { # <name> <files...>: zip of empty-ish files, relative paths inside the zip
  local name="$1"
  shift
  local dir="$WORK/z-$name"
  mkdir -p "$dir"
  local f
  for f in "$@"; do
    mkdir -p "$dir/$(dirname "$f")"
    printf 'x\n' >"$dir/$f"
  done
  run_in_image sh -c "cd $REL/z-$name && zip -qrX ../$name.zip ."
  [ "$status" -eq 0 ] || fail "make_zip $name: $output"
}

GOOD=(php/conf.d/app.ini public/index.php entrypoints/lambda/worker.php vendor/autoload.php src/A.php)

make_zip good "${GOOD[@]}"
check_zip "$REL/good.zip"
expect_status 'tiny good zip' 0

for missing in php/conf.d/app.ini public/index.php entrypoints/lambda/worker.php vendor/autoload.php; do
  files=()
  for f in "${GOOD[@]}"; do [ "$f" = "$missing" ] || files+=("$f"); done
  name="no-$(tr '/.' '__' <<<"$missing")"
  make_zip "$name" "${files[@]}"
  check_zip "$REL/$name.zip"
  expect_status "zip without $missing" 1
  grep -qF "$missing" <<<"$output" || fail "zip without $missing: message does not name it: $output"
done

for extra in tests/FooTest.php .env .env.local vendor/phpunit/phpunit/phpunit vendor/bin/phpstan vendor/friendsofphp/php-cs-fixer/php-cs-fixer Tests/FooTest.php .ENV .Env.local config/PHPUnit.xml vendor/bin/PHPStan; do
  name="with-$(tr '/.' '__' <<<"$extra")"
  make_zip "$name" "${GOOD[@]}" "$extra"
  check_zip "$REL/$name.zip"
  expect_status "zip with $extra" 1
  grep -qF "$extra" <<<"$output" || fail "zip with $extra: message does not name it: $output"
done

# A tests/ directory deeper in vendor is a package's own business; only the project-level tests/ is refused.
make_zip vendor-tests "${GOOD[@]}" vendor/some/pkg/tests/Case.php
check_zip "$REL/vendor-tests.zip"
expect_status 'zip with vendor/some/pkg/tests' 0

# Size limit: 50 MB zipped. A zip of incompressible data just over the limit fails (the data is made in the image).
run_in_image sh -c "mkdir -p $REL/z-big && cp -r $REL/z-good/. $REL/z-big/ && head -c 52500000 /dev/urandom >$REL/z-big/vendor/blob.bin && cd $REL/z-big && zip -qrX ../big.zip ."
[ "$status" -eq 0 ] || fail "make big zip: $output"
check_zip "$REL/big.zip"
expect_status 'zip over 50 MB' 1
grep -qi '50' <<<"$output" || fail "zip over 50 MB: message does not mention the limit: $output"

# Unzipped size: Lambda allows 250 MB for the package and its layers together, so the package may not exceed 200 MB
# unzipped. Zeros compress to almost nothing, so this zip passes the 50 MB zipped rule and must still fail.
run_in_image sh -c "mkdir -p $REL/z-wide && cp -r $REL/z-good/. $REL/z-wide/ && head -c 210000000 /dev/zero >$REL/z-wide/vendor/zeros.bin && cd $REL/z-wide && zip -qrX ../wide.zip ."
[ "$status" -eq 0 ] || fail "make wide zip: $output"
check_zip "$REL/wide.zip"
expect_status 'zip over 200 MB unzipped' 1
grep -qi 'unzipped' <<<"$output" || fail "zip over 200 MB unzipped: message does not say unzipped: $output"

# Bad usage: missing file, no argument, a file that is not a zip.
check_zip "$REL/does-not-exist.zip"
expect_status 'missing zip file' 2
run_in_image sh scripts/check-lambda-zip.sh "$REL/good.zip" "$REL/good.zip"
expect_status 'two arguments' 2
printf 'not a zip' >"$WORK/notzip.zip"
check_zip "$REL/notzip.zip"
expect_status 'file that is not a zip' 2

if [ "$failures" -ne 0 ]; then
  printf '%d failure(s)\n' "$failures"
  exit 1
fi
echo 'lambda-zip: all cases passed'
