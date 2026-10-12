#!/usr/bin/env bash
# Tests for scripts/check-image-pins.sh. Run: bash scripts/tests/check-image-pins.test.sh
# Exit codes asserted: 0 = everything pinned, 1 = something unpinned, 2 = bad usage or nothing to scan.
# Needs Docker: Compose files are resolved by `docker compose config` and parsed by a pinned jq image (never `up`).
# Every guard run is wrapped in `timeout`, so a parser that loops forever fails the test instead of hanging it.
# Fixtures that git would ignore (build/, dist/, vendor/, node_modules/), symlinks and files with a BOM or CRLF line
# endings are created at run time in a temporary directory.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
GUARD="$HERE/../check-image-pins.sh"
FIX="$HERE/fixtures/pins"
REPO="$HERE/../.."
DIGEST=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
PINNED_FROM="FROM alpine@sha256:$DIGEST"
UNPINNED_FROM='FROM alpine:3'

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

failures=0
status=0
output=''

run_guard() {
  status=0
  output="$(timeout 120 "$GUARD" "$@" 2>&1)" || status=$?
}

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

expect_status() { # <label> <expected>
  [ "$status" -eq "$2" ] || fail "$1: expected exit $2, got $status: $output"
}

no_crash() { # <label>: a clean message, not a shell error
  if grep -qiE 'bad array subscript|unbound variable|syntax error|command not found|line [0-9]+:' <<<"$output"; then
    fail "$1: the script crashed: $output"
  fi
}

pass_case() { # <label> <file>
  run_guard "$FIX/$2"
  expect_status "$1" 0
}

# <label> <file> <expected line number, or - for none> <expected text>
fail_case() {
  run_guard "$FIX/$2"
  expect_status "$1" 1
  if [ "$3" = - ]; then
    grep -qF "$2" <<<"$output" || fail "$1: output does not name $2: $output"
  else
    grep -qF "$2:$3" <<<"$output" || fail "$1: output does not name $2:$3: $output"
  fi
  grep -qF -- "$4" <<<"$output" || fail "$1: output lacks '$4': $output"
  no_crash "$1"
}

# Writes a file below $TMP, creating its directory. Content comes from printf's format argument.
put() { # <relative path> <printf format> [args]
  local path="$TMP/$1"
  shift
  mkdir -p "$(dirname "$path")"
  printf "$@" >"$path"
}

# ---- Dockerfiles -------------------------------------------------------------------------------------------------
pass_case 'Dockerfile: pinned, stages (any case), scratch, index, continuation, --mount from=stage, ONBUILD, syntax' Dockerfile.pinned
pass_case 'Dockerfile: escape=` directive; a backslash is then not a continuation' Dockerfile.pinned-backtick
fail_case 'Dockerfile: unpinned tag' Dockerfile.unpinned-tag 2 'php:8.5-cli-alpine'
fail_case 'Dockerfile: COPY --from an image by tag' Dockerfile.copy-unpinned 3 'composer:2'
fail_case 'Dockerfile: COPY split by a backslash onto a --from line' Dockerfile.copy-continued 3 'composer:2'
fail_case 'Dockerfile: COPY split by the declared escape character (backtick)' Dockerfile.backtick-copy 4 'composer:2'
fail_case 'Dockerfile: RUN --mount=...from=<image>' Dockerfile.run-mount 3 'composer:2'
fail_case 'Dockerfile: unpinned # syntax= directive' Dockerfile.syntax-unpinned 1 'syntax'
fail_case 'Dockerfile: unpinned # syntax= comment after the first instruction' Dockerfile.syntax-late 2 'syntax'
fail_case 'Dockerfile: ONBUILD FROM' Dockerfile.onbuild-from 3 'alpine:3'
fail_case 'Dockerfile: ONBUILD COPY --from=img:tag' Dockerfile.onbuild-copy 3 'img:tag'
fail_case 'Dockerfile: FROM ${BASE} even with a pinned default' Dockerfile.arg-pinned-default 3 'variable'
fail_case 'Dockerfile: FROM $BASE, ARG without a default' Dockerfile.arg-nodefault 3 'variable'
fail_case 'Dockerfile: FROM ${BASE}, ARG default is a tag' Dockerfile.arg-unpinned 3 'variable'
fail_case 'Dockerfile: variable in COPY --from' Dockerfile.copy-from-var 3 'variable'
fail_case 'Dockerfile: variable in RUN --mount from=' Dockerfile.copy-from-var 4 'variable'
fail_case 'Dockerfile: ARG with an empty name' Dockerfile.arg-empty-name 3 'empty name'
fail_case 'Dockerfile: COPY --from= with an empty value' Dockerfile.copy-from-empty 3 'empty'
fail_case 'Dockerfile: AS without a stage name' Dockerfile.empty-names 2 'AS without a stage name'
fail_case 'Dockerfile: FROM without an image' Dockerfile.empty-names 3 'FROM without an image'
fail_case 'Dockerfile: COPY --from a stage defined later' Dockerfile.copy-forward-stage 3 'later'
fail_case 'Dockerfile: COPY --from a stage number out of range' Dockerfile.copy-forward-stage 4 "'1'"
fail_case 'Dockerfile: stage used before it is defined' Dockerfile.stage-forward 2 'later'

run_guard "$FIX/Dockerfile.copy-from-empty"
if grep -q 'subscript' <<<"$output"; then fail "empty --from value: bad array subscript leaked: $output"; fi

# Bytes that fixture files cannot carry well: a BOM before the directive, CRLF line endings.
put bom/Dockerfile '\xef\xbb\xbf# syntax=docker/dockerfile:1\n%s\n' "$PINNED_FROM"
run_guard "$TMP/bom/Dockerfile"
expect_status 'Dockerfile: unpinned # syntax= after a UTF-8 BOM' 1
grep -qF 'syntax' <<<"$output" || fail "BOM: output lacks 'syntax': $output"
put crlf/Dockerfile '%s\r\nCOPY \\\r\n  --from=composer:2 /a /b\r\n' "$PINNED_FROM"
run_guard "$TMP/crlf/Dockerfile"
expect_status 'Dockerfile: CRLF line endings, COPY split by a backslash' 1
grep -qF 'composer:2' <<<"$output" || fail "CRLF: output lacks composer:2: $output"
put crlf-ok/Dockerfile '%s AS a\r\n%s\r\nCOPY \\\r\n  --from=a /a /b\r\n' "$PINNED_FROM" "$PINNED_FROM"
run_guard "$TMP/crlf-ok/Dockerfile"
expect_status 'Dockerfile: CRLF line endings, pinned' 0

# Bytes outside TAB and printable ASCII are refused (BuildKit treats some of them as white space, this parser does not,
# so they could hide an instruction). Each case names the line that holds the byte.
byte_case() { # <label> <dir> <expected line> <printf format> [args]
  local label="$1" dir="$2" line="$3"
  shift 3
  put "$dir/Dockerfile" "$@"
  run_guard "$TMP/$dir/Dockerfile"
  expect_status "$label" 1
  grep -qF "Dockerfile:$line:" <<<"$output" || fail "$label: output does not name Dockerfile:$line: $output"
  grep -qF 'byte' <<<"$output" || fail "$label: output lacks 'byte': $output"
  no_crash "$label"
}
byte_case 'Dockerfile: form feed before FROM' byte-ff 1 '\fFROM alpine:3\n'
byte_case 'Dockerfile: vertical tab before FROM' byte-vt 1 '\vFROM alpine:3\n'
byte_case 'Dockerfile: no-break space before FROM' byte-nbsp 1 '\xc2\xa0FROM alpine:3\n'
byte_case 'Dockerfile: form feed between FROM and the image' byte-ff-from 1 'FROM\falpine:3\n'
byte_case 'Dockerfile: form feed between COPY and --from' byte-ff-copy 2 '%s\nCOPY\f--from=alpine:3 /a /b\n' "$PINNED_FROM"
byte_case 'Dockerfile: a form-feed line inside a continuation' byte-ff-cont 3 '%s\nRUN echo hi \\\n\f\nFROM alpine:3\n' "$PINNED_FROM"
byte_case 'Dockerfile: a CR-space line inside a continuation' byte-cr-cont 3 '%s\nRUN echo \\\n\r \nFROM alpine:3\n' "$PINNED_FROM"
byte_case 'Dockerfile: a NUL byte' byte-nul 2 '%s\nRUN true\0\n' "$PINNED_FROM"
# TAB is allowed, and a continuation is a backslash followed only by spaces or tabs (as in BuildKit).
put tab-ok/Dockerfile 'FROM\talpine@sha256:%s\t\r\nRUN true \\ \t\r\n\t&& true\r\n' "$DIGEST"
run_guard "$TMP/tab-ok/Dockerfile"
expect_status 'Dockerfile: TAB separators and a backslash followed by blanks, pinned' 0
put tab-cont/Dockerfile '%s\nCOPY \\ \t \n  --from=composer:2 /a /b\n' "$PINNED_FROM"
run_guard "$TMP/tab-cont/Dockerfile"
expect_status 'Dockerfile: a backslash followed by blanks continues the line' 1
grep -qF 'composer:2' <<<"$output" || fail "backslash + blanks: output lacks composer:2: $output"

# Heredocs are refused (none are used; their bodies follow other line rules).
fail_case 'Dockerfile: COPY heredoc whose body ends in a backslash' Dockerfile.heredoc 3 'heredoc'
put heredoc-run/Dockerfile '%s\nRUN <<-EOF\n  true\nEOF\n' "$PINNED_FROM"
run_guard "$TMP/heredoc-run/Dockerfile"
expect_status 'Dockerfile: RUN heredoc' 1
grep -qF 'Dockerfile:2:' <<<"$output" || fail "RUN heredoc: output does not name Dockerfile:2: $output"
grep -qF 'heredoc' <<<"$output" || fail "RUN heredoc: output lacks 'heredoc': $output"

# ---- Compose -----------------------------------------------------------------------------------------------------
pass_case 'Compose: pinned, locally built (single-part name; pull_policy: build), pinned inline Dockerfile, profile' compose.pinned.yml
fail_case 'Compose: tag only' compose.unpinned.yml - 'repo/a:1.0'
fail_case 'Compose: ${A_IMAGE:-tag}' compose.env-unpinned.yml - 'variable'
fail_case 'Compose: ${A_IMAGE} without a default' compose.env-nodefault.yml - 'variable'
fail_case 'Compose: ${IMG:-alpine@sha256:...} (pinned default)' compose.env-pinned-default.yml - 'variable'
fail_case 'Compose: 4-space indentation' compose.indent4.yml - 'alpine:3'
fail_case 'Compose: inline {image: alpine:3}' compose.flow.yml - 'alpine:3'
fail_case 'Compose: quoted "image" key' compose.quoted-key.yml - 'alpine:3'
fail_case 'Compose: YAML anchor and merge key' compose.anchor.yml - 'alpine:3'
fail_case 'Compose: extends from base.yaml' compose.extends.yml - 'alpine:3'
fail_case 'Compose: build: does not exempt someuser/foo:latest' compose.build-userimage.yml - 'someuser/foo:latest'
fail_case 'Compose: dockerfile_inline with an unpinned FROM' compose.inline-unpinned.yml - 'alpine:3'
run_guard "$FIX/compose.dockerfile-unpinned.yml"
expect_status 'Compose: build.dockerfile is fed to the Dockerfile check' 1
grep -qF 'Dockerfile.unpinned-tag:2' <<<"$output" || fail "build.dockerfile: output does not name Dockerfile.unpinned-tag:2: $output"
fail_case 'Compose: unpinned docker-image:// additional context' compose.additional-context.yml - 'alpine:3'
fail_case 'Compose: unpinned image of a service behind a profile' compose.profile-unpinned.yml - 'alpine:3'
fail_case 'Compose: a file Compose cannot parse' compose.broken.yml - 'compose'

# A built image is exempt from the pin only when Compose cannot pull it: pull_policy: build, or a project-prefixed name
# (expense-tracker-*) with pull_policy unset, missing or never.
fail_case 'Compose: build: does not exempt nginx:latest with pull_policy: always' compose.build-pull-always.yml - 'nginx:latest'
fail_case 'Compose: build: does not exempt php:8 (not project-prefixed)' compose.build-official.yml - 'php:8'
fail_case 'Compose: build: does not exempt expense-tracker-x:dev with pull_policy: always' compose.build-project-pull-always.yml - 'expense-tracker-x:dev'
fail_case 'Compose: unpinned image volume source' compose.image-volume.yml - 'alpine:3'
fail_case 'Compose: models: is refused' compose.models.yml - 'models'
fail_case 'Compose: additional context named like a pinned image' compose.context-name.yml - 'busybox@sha256'
for remote in 'oci-layout:///srv/layout' 'http://example.invalid/context.tar' 'git@example.invalid:evil/repo' 'github.com/evil/repo' 'repo.git'; do
  fail_case "Compose: additional context $remote is refused" compose.context-remote.yml - "$remote"
done
fail_case 'Compose: build.cache_from from a registry' compose.cache-registry.yml - 'type=registry'
# No build cache is used, so every entry is refused (a type=local/type=gha prefix proves nothing: the last type= wins,
# and a gha cache can carry its own URL).
for entry in 'type=local,type=registry,ref=evil.example/cache:latest' 'type=gha,url=https://evil.example/' 'type=local,dest=/tmp/cache'; do
  fail_case "Compose: cache entry $entry is refused" compose.cache-bypass.yml - "$entry"
done
# BUILDKIT_* build arguments (BUILDKIT_SYNTAX swaps the frontend image), and SBOM / provenance attestations.
fail_case 'Compose: build.args BUILDKIT_SYNTAX (map form)' compose.buildkit-args.yml - 'BUILDKIT_SYNTAX'
fail_case 'Compose: build.args buildkit_syntax (list form, lower case)' compose.buildkit-args-list.yml - 'buildkit_syntax'
fail_case 'Compose: build.sbom is refused' compose.sbom.yml - 'sbom'
fail_case 'Compose: build.provenance is refused' compose.sbom.yml - 'provenance'

# include / extends.file must be local relative paths (read with yq, before Compose fetches anything).
fail_case 'Compose: include from oci:// (short syntax)' compose.include-oci.yml 3 'oci://registry.invalid'
fail_case 'Compose: include from a git URL (long syntax, path list)' compose.include-long.yml 5 'https://example.invalid'
fail_case 'Compose: remote include in flow style' compose.include-flow.yml 2 'oci://registry.invalid'
fail_case 'Compose: extends.file from git@' compose.extends-remote.yml 5 'git@example.invalid'
fail_case 'Compose: remote extends.file in flow style' compose.extends-flow.yml 4 'git@example.invalid'
# Other YAML spellings of the include key: quoted, escaped, a complex key, an alias used as the key.
spelling=0
for key in '"include":' '"inc\\x6cude":' '? include\n:' 'x-k: &k include\n*k :'; do
  spelling=$((spelling + 1))
  put "spell-$spelling/compose.yml" "$key"'\n  - oci://registry.invalid/evil/stack:latest\n'
  run_guard "$TMP/spell-$spelling/compose.yml"
  expect_status "Compose: include spelled $key" 1
  grep -qF 'local relative path' <<<"$output" || fail "include spelled $key: not refused as non-local: $output"
  if grep -qF 'could not resolve' <<<"$output"; then fail "include spelled $key: handed to Compose: $output"; fi
done
pass_case 'Compose: local include in the long syntax' compose.include-long-ok.yml
run_guard "$FIX/compose.extends.yml"
if grep -qF 'local relative path' <<<"$output"; then fail "local extends.file is refused as non-local: $output"; fi
# A file reached through a local include is read as raw text too.
put inc-nested/compose.yml 'include:\n  - sub/part.yaml\n'
put inc-nested/sub/part.yaml 'include:\n  - oci://registry.invalid/evil/stack:latest\n'
run_guard "$TMP/inc-nested/compose.yml"
expect_status 'Compose: a remote include inside a locally included file' 1
grep -qF 'part.yaml:2' <<<"$output" || fail "nested include: output does not name part.yaml:2: $output"

# The jq image must be pinned; an unpinned one is a usage error (exit 2), not a finding about the scanned file.
JQ_IMAGE=alpine:3 run_guard "$FIX/compose.pinned.yml"
expect_status 'an unpinned JQ_IMAGE is refused before anything runs' 2
grep -qF 'not pinned' <<<"$output" || fail "unpinned JQ_IMAGE: output lacks 'not pinned': $output"
YQ_IMAGE=alpine:3 run_guard "$FIX/compose.pinned.yml"
expect_status 'an unpinned YQ_IMAGE is refused before anything runs' 2
grep -qF 'not pinned' <<<"$output" || fail "unpinned YQ_IMAGE: output lacks 'not pinned': $output"

# Docker actions (runs.using: docker): a docker:// image must be pinned, and a Dockerfile image is checked whatever
# its name. Composite actions have nothing to check here (zizmor audits them).
put act-file/action.yml 'name: x\ndescription: x\nruns:\n  using: docker\n  image: build/image.txt\n'
put act-file/build/image.txt '%s\n' "$UNPINNED_FROM"
run_guard "$TMP/act-file"
expect_status 'docker action: the Dockerfile named by runs.image is checked' 1
grep -qF 'image.txt:1' <<<"$output" || fail "docker action Dockerfile: output does not name image.txt:1: $output"
put act-docker/action.yaml "name: x\ndescription: x\nruns:\n  using: 'Docker'\n  image: docker://alpine:3\n"
run_guard "$TMP/act-docker"
expect_status 'docker action: an unpinned docker:// image' 1
grep -qF 'alpine:3' <<<"$output" || fail "docker action docker://: output lacks alpine:3: $output"
put act-ok/action.yml 'name: x\ndescription: x\nruns:\n  using: docker\n  image: docker://alpine@sha256:%s\n' "$DIGEST"
put act-ok/composite/action.yml 'name: y\ndescription: y\nruns:\n  using: composite\n  steps: []\n'
run_guard "$TMP/act-ok"
expect_status 'docker action: a pinned docker:// image, and a composite action' 0

# The Makefile's pinned images cannot be replaced from the command line or the environment, and `print-` only prints
# the variables the scripts read.
for var in JQ_IMAGE YQ_IMAGE ZIZMOR_IMAGE; do
  pinned="$(make -s --no-print-directory -C "$REPO" "print-$var")"
  [[ "$pinned" =~ @sha256:[0-9a-f]{64}$ ]] || fail "make print-$var: not a pinned image: $pinned"
  got="$(make -s --no-print-directory -C "$REPO" "print-$var" "$var=evil:latest")"
  [ "$got" = "$pinned" ] || fail "make print-$var $var=evil:latest printed '$got'"
  got="$(env "$var=evil:latest" make -s --no-print-directory -C "$REPO" "print-$var")"
  [ "$got" = "$pinned" ] || fail "$var=evil:latest make print-$var printed '$got'"
done
for var in GITLEAKS_IMAGE NODE_IMAGE; do
  grep -qE "^override $var :=" "$REPO/Makefile" || fail "Makefile: $var is not 'override $var :='"
done
if make -s --no-print-directory -C "$REPO" print-RUN_PHP >/dev/null 2>&1; then
  fail 'make print-RUN_PHP works; print- must be limited to the pinned image variables'
fi

# A copy of the guard in a throwaway repository, run with no argument: .env.example and the project as `make up` loads
# it (repo root, no -f, the override file merged) are checked too.
JQ_PINNED="$(make -s --no-print-directory -C "$REPO" print-JQ_IMAGE)"
YQ_PINNED="$(make -s --no-print-directory -C "$REPO" print-YQ_IMAGE)"
fake_repo() { # <dir>: a repository with only the guard in it
  mkdir -p "$TMP/$1/scripts"
  cp "$GUARD" "$TMP/$1/scripts/check-image-pins.sh"
}
run_fake() { # <dir>
  status=0
  output="$(cd "$TMP/$1" && JQ_IMAGE="$JQ_PINNED" YQ_IMAGE="$YQ_PINNED" timeout 120 scripts/check-image-pins.sh 2>&1)" ||
    status=$?
}
fake_repo env-compose-file
put env-compose-file/compose.yml 'services:\n  a:\n    image: alpine@sha256:%s\n' "$DIGEST"
put env-compose-file/stack.yml 'services:\n  evil:\n    image: alpine:3\n'
put env-compose-file/.env.example '# local settings\nCOMPOSE_FILE=stack.yml\n'
run_fake env-compose-file
expect_status '.env.example: a COMPOSE_ line is refused (it would change what make up loads)' 1
grep -qF '.env.example:2' <<<"$output" || fail ".env.example COMPOSE_FILE: output does not name .env.example:2: $output"
# The project is then not loaded at all (COMPOSE_FILE could name a file that includes something remote).
if grep -qF 'make up loads it' <<<"$output"; then fail ".env.example COMPOSE_FILE: the project was loaded anyway: $output"; fi

# A remote include in the root compose file is refused before the project load, which is then skipped (never fetched).
fake_repo project-remote
put project-remote/docker-compose.yml 'include:\n  - oci://registry.invalid/evil/stack:latest\nservices:\n  a:\n    image: alpine@sha256:%s\n' "$DIGEST"
run_fake project-remote
expect_status 'project load: a remote include in the root compose file' 1
grep -qF 'docker-compose.yml:2' <<<"$output" || fail "project remote: output does not name docker-compose.yml:2: $output"
if grep -qF 'could not resolve' <<<"$output"; then fail "project remote: Compose was asked to resolve it: $output"; fi

fake_repo override-merge
put override-merge/docker-compose.yml 'services:\n  a:\n    image: alpine@sha256:%s\n    pull_policy: always\n' "$DIGEST"
put override-merge/docker-compose.override.yml 'services:\n  a:\n    image: expense-tracker-a:dev\n    build:\n      context: .\n'
put override-merge/Dockerfile '%s\n' "$PINNED_FROM"
run_fake override-merge
expect_status 'project load: a problem only visible once the override file is merged' 1
grep -qF 'expense-tracker-a:dev' <<<"$output" || fail "override merge: output lacks expense-tracker-a:dev: $output"

fake_repo project-ok
put project-ok/docker-compose.yml 'services:\n  a:\n    image: alpine@sha256:%s\n' "$DIGEST"
put project-ok/.env.example 'A=1\n'
run_fake project-ok
expect_status 'project load: a pinned project passes' 0

# What Compose itself resolves must not depend on the environment: COMPOSE_FILE and COMPOSE_PROFILES are ignored.
COMPOSE_FILE="$FIX/compose.pinned.yml" COMPOSE_PROFILES=none run_guard "$FIX/compose.profile-unpinned.yml"
expect_status 'Compose: COMPOSE_FILE / COMPOSE_PROFILES in the environment are ignored' 1

# A Dockerfile that only a compose file points at (any name, any directory) is still checked; a symlink is refused.
put ref/compose.yml 'services:\n  a:\n    image: expense-tracker-a:dev\n    build:\n      context: .\n      dockerfile: ci/build.txt\n'
put ref/ci/build.txt '%s\n' "$UNPINNED_FROM"
run_guard "$TMP/ref"
expect_status 'Compose: build.dockerfile with an unusual name is checked' 1
grep -qF 'build.txt' <<<"$output" || fail "build.dockerfile: output does not name build.txt: $output"
put refsym/compose.yml 'services:\n  a:\n    image: expense-tracker-a:dev\n    build:\n      context: .\n      dockerfile: ci/build.txt\n'
put refsym/ci/real.txt '%s\n' "$PINNED_FROM"
ln -s real.txt "$TMP/refsym/ci/build.txt"
run_guard "$TMP/refsym/compose.yml"
expect_status 'Compose: build.dockerfile that is a symlink' 1
grep -qi 'symlink' <<<"$output" || fail "build.dockerfile symlink: output lacks 'symlink': $output"

# ---- Discovery ---------------------------------------------------------------------------------------------------
for name in dockerfile Containerfile DockerFile app.Dockerfile Dockerfile.dev containerfile; do
  put "disc-$name/$name" '%s\n' "$UNPINNED_FROM"
  run_guard "$TMP/disc-$name"
  expect_status "discovery: a file named $name is scanned" 1
done
put nested/docker/build/Dockerfile '%s\n' "$UNPINNED_FROM"
run_guard "$TMP/nested"
expect_status 'discovery: docker/build/Dockerfile is not pruned' 1

put prune-exact/Dockerfile '%s\n' "$PINNED_FROM"
for dep in frontend/node_modules/pkg frontend/dist backend/vendor docker/local-aws/vendor; do
  put "prune-exact/$dep/Dockerfile" '%s\n' "$UNPINNED_FROM"
done
run_guard "$TMP/prune-exact"
expect_status 'discovery: the exact dependency paths are pruned' 0

for other in other/vendor src/node_modules app/dist app/build; do
  put "prune-other-$(tr / - <<<"$other")/Dockerfile" '%s\n' "$PINNED_FROM"
  put "prune-other-$(tr / - <<<"$other")/$other/Dockerfile" '%s\n' "$UNPINNED_FROM"
  run_guard "$TMP/prune-other-$(tr / - <<<"$other")"
  expect_status "discovery: $other is not a dependency path, so it is scanned" 1
done

put sym/real.txt '%s\n' "$PINNED_FROM"
ln -s real.txt "$TMP/sym/Dockerfile"
run_guard "$TMP/sym"
expect_status 'discovery: a symlinked Dockerfile in a directory is refused' 1
grep -qi 'symlink' <<<"$output" || fail "symlink: output lacks 'symlink': $output"
run_guard "$TMP/sym/Dockerfile"
expect_status 'discovery: a symlinked Dockerfile given as a file is refused' 1

# Workflows are zizmor's job now: a repository that has only workflows has nothing for this guard to scan.
put wf/.github/workflows/ci.yml 'on: push\njobs:\n  j:\n    runs-on: x\n    steps:\n      - uses: actions/checkout@v4\n'
run_guard "$TMP/wf"
expect_status 'workflows are not scanned here (zizmor owns them)' 2

# ---- Usage -------------------------------------------------------------------------------------------------------
run_guard "$FIX"
expect_status 'a directory with unpinned fixtures' 1

run_guard "$HERE/nonexistent"
expect_status 'missing path' 2

run_guard -x
expect_status 'an argument that starts with - is not a path' 2
run_guard -- -x
expect_status 'an argument that starts with - after -- is still refused' 2

mkdir -p "$TMP/empty"
run_guard "$TMP/empty"
expect_status 'a directory with nothing to scan is an error, not a pass' 2

run_guard
expect_status 'the real repository' 0
(cd "$REPO" && timeout 120 "$GUARD" >/dev/null 2>&1) || fail 'the real repository from the repo root'

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'check-image-pins: all checks passed'
