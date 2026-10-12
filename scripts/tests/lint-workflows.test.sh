#!/usr/bin/env bash
# Tests for scripts/lint-workflows.sh (zizmor over workflows and actions). Run: bash scripts/tests/lint-workflows.test.sh
# Each fixture is a tiny repository under fixtures/pins/zizmor/: `bad-*` must be reported (exit 1, naming the file),
# `ok-*` (the pinned equivalent) must pass. Needs Docker (the pinned zizmor image); runs offline, so no token is used.
# How the script calls docker (network, token, image pin) is checked with a stand-in `docker` that only records its
# arguments; the configuration guard runs against a copy of the script in a throwaway repository (mktemp). The last
# section proves that the repository's .github/zizmor.yml is actually applied.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LINT="$HERE/../lint-workflows.sh"
FIX="$HERE/fixtures/pins/zizmor"

failures=0
status=0
output=''

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

run_lint() { # <fixture-root>
  status=0
  # No token: the script must run offline, whatever the developer's environment holds.
  output="$(env -u GH_TOKEN -u GITHUB_TOKEN -u ZIZMOR_GITHUB_TOKEN -u ZIZMOR_ONLINE timeout 300 "$LINT" "$1" 2>&1)" || status=$?
}

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
ZIZMOR_PINNED="$(make -s --no-print-directory -C "$HERE/../.." print-ZIZMOR_IMAGE)"
YQ_PINNED="$(make -s --no-print-directory -C "$HERE/../.." print-YQ_IMAGE)"

# A stand-in for `docker` that, for the zizmor run (the only call with --pedantic), records its arguments (one per
# line) and succeeds, so the tests can see how the script would run zizmor without running it. Other calls (the yq
# parse of the workflows) go to the real docker.
mkdir -p "$TMP/stub"
REAL_DOCKER="$(command -v docker)"
STUB_LOG="$TMP/stub.log"
export REAL_DOCKER STUB_LOG
printf '#!/usr/bin/env bash\nfor a in "$@"; do\n  if [ "$a" = --pedantic ]; then printf "%%s\\n" "$@" >"$STUB_LOG"; exit 0; fi\ndone\nexec "$REAL_DOCKER" "$@"\n' \
  >"$TMP/stub/docker"
chmod +x "$TMP/stub/docker"

run_stub() { # <script> <root> [VAR=value ...]: runs <script> with the docker stand-in first in PATH
  local script="$1" root="$2"
  shift 2
  status=0
  rm -f "$STUB_LOG"
  output="$(env -u GH_TOKEN -u GITHUB_TOKEN -u ZIZMOR_GITHUB_TOKEN -u ZIZMOR_ONLINE PATH="$TMP/stub:$PATH" \
    ZIZMOR_IMAGE="$ZIZMOR_PINNED" YQ_IMAGE="$YQ_PINNED" "$@" timeout 120 "$script" "$root" 2>&1)" || status=$?
}

stub_has() { # <argument>: the stand-in received exactly this argument
  [ -f "$STUB_LOG" ] && grep -qxF -- "$1" "$STUB_LOG"
}

bad_case() { # <label> <fixture> <file that must be named> <text>
  run_lint "$FIX/$2"
  [ "$status" -eq 1 ] || fail "$1: expected exit 1, got $status: $output"
  grep -qF "$3" <<<"$output" || fail "$1: output does not name $3: $output"
  grep -qF "$4" <<<"$output" || fail "$1: output lacks '$4': $output"
}

ok_case() { # <label> <fixture>
  run_lint "$FIX/$2"
  [ "$status" -eq 0 ] || fail "$1: expected exit 0, got $status: $output"
}

bad_case 'unpinned container image' bad-container .github/workflows/ci.yml 'unpinned-images'
bad_case 'unpinned services.*.image' bad-service .github/workflows/ci.yml 'unpinned-images'
bad_case 'unpinned inline {uses: ...}' bad-inline .github/workflows/ci.yml 'unpinned-uses'
bad_case 'unpinned uses in compose.yml (a workflow, not a Compose file)' bad-compose-yml .github/workflows/compose.yml 'unpinned-uses'
bad_case 'unpinned uses in an action.yml' bad-action .github/actions/x/action.yml 'unpinned-uses'
bad_case 'unpinned reusable workflow at job level' bad-reusable .github/workflows/ci.yml 'unpinned-uses'

ok_case 'pinned container image' ok-container
ok_case 'pinned services.*.image' ok-service
ok_case 'pinned inline {uses: ...}' ok-inline
ok_case 'pinned uses in compose.yml' ok-compose-yml
ok_case 'pinned uses in an action.yml' ok-action
ok_case 'pinned reusable workflow' ok-reusable

# ---- Network and token: offline unless ZIZMOR_ONLINE=1 -----------------------------------------------------------
run_stub "$LINT" "$FIX/ok-inline" GH_TOKEN=fake-value-for-test
[ "$status" -eq 0 ] || fail "GH_TOKEN without ZIZMOR_ONLINE: expected exit 0, got $status: $output"
stub_has --offline || fail 'GH_TOKEN without ZIZMOR_ONLINE: zizmor is not run with --offline'
stub_has none || fail 'GH_TOKEN without ZIZMOR_ONLINE: the container is not run with --network none'
if stub_has GH_TOKEN; then fail 'GH_TOKEN without ZIZMOR_ONLINE: the token is forwarded'; fi

run_stub "$LINT" "$FIX/ok-inline" GH_TOKEN=fake-value-for-test ZIZMOR_ONLINE=1
[ "$status" -eq 0 ] || fail "ZIZMOR_ONLINE=1: expected exit 0, got $status: $output"
stub_has GH_TOKEN || fail 'ZIZMOR_ONLINE=1: the token is not forwarded by name'
if stub_has --offline; then fail 'ZIZMOR_ONLINE=1: zizmor still runs --offline'; fi
if grep -qF fake-value-for-test "$STUB_LOG"; then fail 'ZIZMOR_ONLINE=1: the token value is in the argument list'; fi

run_stub "$LINT" "$FIX/ok-inline" ZIZMOR_ONLINE=1
[ "$status" -eq 2 ] || fail "ZIZMOR_ONLINE=1 without GH_TOKEN: expected exit 2, got $status: $output"

# ---- The zizmor image must be pinned ------------------------------------------------------------------------------
run_stub "$LINT" "$FIX/ok-inline" ZIZMOR_IMAGE=zizmor.invalid/zizmor:1
[ "$status" -eq 2 ] || fail "unpinned ZIZMOR_IMAGE: expected exit 2, got $status: $output"
grep -qF 'not pinned' <<<"$output" || fail "unpinned ZIZMOR_IMAGE: output lacks 'not pinned': $output"
[ ! -f "$STUB_LOG" ] || fail 'unpinned ZIZMOR_IMAGE: docker was run anyway'

# ---- The configuration cannot switch off the pin audits ----------------------------------------------------------
# A copy of the script in a throwaway repository, with the real configuration plus one change.
config_repo() { # <dir>: copies the script and the real config; prints nothing
  mkdir -p "$TMP/$1/scripts" "$TMP/$1/.github/workflows"
  cp "$LINT" "$TMP/$1/scripts/lint-workflows.sh"
  cp "$HERE/../../.github/zizmor.yml" "$TMP/$1/.github/zizmor.yml"
  cp "$FIX/ok-inline/.github/workflows/ci.yml" "$TMP/$1/.github/workflows/ci.yml"
}
config_refused() { # <label> <dir> <text>
  run_stub "$TMP/$2/scripts/lint-workflows.sh" "$TMP/$2"
  [ "$status" -eq 1 ] || fail "$1: expected exit 1, got $status: $output"
  grep -qF -- "$3" <<<"$output" || fail "$1: output lacks '$3': $output"
  [ ! -f "$STUB_LOG" ] || fail "$1: zizmor was run anyway"
}

config_repo cfg-control
run_stub "$TMP/cfg-control/scripts/lint-workflows.sh" "$TMP/cfg-control"
[ "$status" -eq 0 ] || fail "config control (the real config, copied): expected exit 0, got $status: $output"
stub_has --config || fail 'config control: zizmor is not given --config'

config_repo cfg-uses-off
sed -i 's/^  unpinned-uses:$/  unpinned-uses:\n    disable: true/' "$TMP/cfg-uses-off/.github/zizmor.yml"
config_refused 'config: unpinned-uses disabled' cfg-uses-off 'unpinned-uses'

config_repo cfg-images-off
sed -i 's/^    disable: false$/    disable: true/' "$TMP/cfg-images-off/.github/zizmor.yml"
config_refused 'config: unpinned-images disabled' cfg-images-off 'unpinned-images'

config_repo cfg-ref-pin
sed -i 's/"\*": hash-pin/"*": ref-pin/' "$TMP/cfg-ref-pin/.github/zizmor.yml"
config_refused 'config: a weaker unpinned-uses policy' cfg-ref-pin 'unpinned-uses'

config_repo cfg-ignore
printf '    ignore:\n      - ci.yml\n' >>"$TMP/cfg-ignore/.github/zizmor.yml"
config_refused 'config: an ignore list under unpinned-images' cfg-ignore 'unpinned-images'

config_repo cfg-flow
printf '  "unpinned-uses": {disable: true}\n' >>"$TMP/cfg-flow/.github/zizmor.yml"
config_refused 'config: a second, quoted unpinned-uses entry in flow style' cfg-flow 'unpinned-uses'

config_repo cfg-inline-ignore
printf '      - run: "true" # zizmor: ignore[unpinned-uses]\n' >>"$TMP/cfg-inline-ignore/.github/workflows/ci.yml"
config_refused 'an inline zizmor: ignore[unpinned-...] comment in a workflow' cfg-inline-ignore 'ignore[unpinned-'

config_repo cfg-action-ignore
mkdir -p "$TMP/cfg-action-ignore/tools/x"
printf 'name: x\ndescription: x\nruns:\n  using: composite\n  steps:\n    - uses: actions/checkout@v4 # zizmor: ignore[template-injection,unpinned-uses]\n' \
  >"$TMP/cfg-action-ignore/tools/x/action.yml"
config_refused 'an inline ignore of unpinned-uses in an action.yml outside .github' cfg-action-ignore 'ignore['

# ---- Local actions (uses: ./X or $/X) must be ones this audit covers ---------------------------------------------
# A local action in a skipped path (spikes/, the test fixtures), outside the repository or missing would never be
# audited, so it is refused. zizmor still runs (its own findings are reported too).
composite() { # <path of action.yml> [uses of its one step]
  mkdir -p "$(dirname "$1")"
  if [ -n "${2:-}" ]; then
    printf 'name: x\ndescription: x\nruns:\n  using: composite\n  steps:\n    - uses: %s\n' "$2" >"$1"
  else
    printf 'name: x\ndescription: x\nruns:\n  using: composite\n  steps:\n    - run: "true"\n      shell: bash\n' >"$1"
  fi
}
local_uses() { # <dir> <uses value>: a copied repository whose workflow has one more step using the value
  config_repo "$1"
  printf '      - uses: %s\n' "$2" >>"$TMP/$1/.github/workflows/ci.yml"
}
uses_refused() { # <label> <dir> <text>
  run_stub "$TMP/$2/scripts/lint-workflows.sh" "$TMP/$2"
  [ "$status" -eq 1 ] || fail "$1: expected exit 1, got $status: $output"
  grep -qF -- "$3" <<<"$output" || fail "$1: output lacks '$3': $output"
  grep -qF 'not audited' <<<"$output" || fail "$1: output lacks 'not audited': $output"
}

local_uses uses-spikes-dollar '$/spikes/x'
composite "$TMP/uses-spikes-dollar/spikes/x/action.yml"
uses_refused 'uses: $/spikes/x (a skipped path)' uses-spikes-dollar 'spikes/x'

local_uses uses-spikes-dot ./spikes/x
composite "$TMP/uses-spikes-dot/spikes/x/action.yml"
uses_refused 'uses: ./spikes/x (a skipped path)' uses-spikes-dot 'spikes/x'

fixture_action=scripts/tests/fixtures/pins/zizmor/bad-action/.github/actions/x
local_uses uses-fixture "./$fixture_action"
mkdir -p "$TMP/uses-fixture/$fixture_action"
cp "$FIX/bad-action/.github/actions/x/action.yml" "$TMP/uses-fixture/$fixture_action/action.yml"
uses_refused 'uses: a tracked test fixture action' uses-fixture "$fixture_action"

local_uses uses-missing ./tools/missing
uses_refused 'uses: a local action that does not exist' uses-missing 'tools/missing'

local_uses uses-outside ./../outside
mkdir -p "$TMP/outside"
composite "$TMP/outside/action.yml"
uses_refused 'uses: a local path outside the repository' uses-outside '../outside'

local_uses uses-nested ./tools/a
composite "$TMP/uses-nested/tools/a/action.yml" '$/spikes/x'
composite "$TMP/uses-nested/spikes/x/action.yml"
uses_refused 'uses: an audited local action that itself uses a skipped one' uses-nested 'tools/a/action.yml'

local_uses uses-ok ./tools/ok
composite "$TMP/uses-ok/tools/ok/action.yml"
printf 'name: c\non: push\npermissions: {}\njobs:\n  call:\n    uses: ./.github/workflows/ci.yml\n' \
  >"$TMP/uses-ok/.github/workflows/caller.yml"
run_stub "$TMP/uses-ok/scripts/lint-workflows.sh" "$TMP/uses-ok"
[ "$status" -eq 0 ] || fail "uses: an audited local action and a local reusable workflow: expected exit 0, got $status: $output"
stub_has --pedantic || fail 'uses-ok: zizmor was not run'

# The reviewer's lwA3: the referenced $/spikes/x does not exist, and the unpinned action in tools/x is still audited.
local_uses uses-lwa3 '$/spikes/x'
composite "$TMP/uses-lwa3/tools/x/action.yml" actions/setup-node@v4
status=0
output="$(env -u GH_TOKEN -u ZIZMOR_ONLINE ZIZMOR_IMAGE="$ZIZMOR_PINNED" YQ_IMAGE="$YQ_PINNED" timeout 300 \
  "$TMP/uses-lwa3/scripts/lint-workflows.sh" 2>&1)" || status=$?
[ "$status" -eq 1 ] || fail "lwA3: expected exit 1, got $status: $output"
grep -qF 'unpinned-uses' <<<"$output" || fail "lwA3: zizmor's unpinned-uses finding is missing: $output"
grep -qF 'not audited' <<<"$output" || fail "lwA3: the missing local action is not refused: $output"

# ---- Proof that the repository's configuration is loaded ---------------------------------------------------------
# zizmor 1.30.1's own default is already `"*": hash-pin` (a tag-pinned actions/checkout fails with --no-config too),
# so the pin policy alone cannot show that the file is read. Instead: the anon-job fixture has one finding
# (anonymous-definition). With the real config it fails; a copy whose config also disables that audit passes.
run_lint "$FIX/anon-job"
[ "$status" -eq 1 ] || fail "anon-job with the real config: expected exit 1, got $status: $output"
grep -qF 'anonymous-definition' <<<"$output" || fail "anon-job: output lacks 'anonymous-definition': $output"
mkdir -p "$TMP/cfg-loaded/scripts" "$TMP/cfg-loaded/.github"
cp "$LINT" "$TMP/cfg-loaded/scripts/lint-workflows.sh"
cp -r "$FIX/anon-job/.github/workflows" "$TMP/cfg-loaded/.github/workflows"
cp "$HERE/../../.github/zizmor.yml" "$TMP/cfg-loaded/.github/zizmor.yml"
printf '  anonymous-definition:\n    disable: true\n' >>"$TMP/cfg-loaded/.github/zizmor.yml"
status=0
output="$(env -u GH_TOKEN -u ZIZMOR_ONLINE ZIZMOR_IMAGE="$ZIZMOR_PINNED" YQ_IMAGE="$YQ_PINNED" timeout 300 "$TMP/cfg-loaded/scripts/lint-workflows.sh" 2>&1)" ||
  status=$?
[ "$status" -eq 0 ] || fail "config loaded: a copy whose .github/zizmor.yml disables anonymous-definition should pass, got $status: $output"
# And the pin policy holds even without any configuration (zizmor's default), so a config that failed to load
# could not silently allow tags.
status=0
output="$(timeout 300 docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges --read-only \
  -u "$(id -u):$(id -g)" -v "$FIX/bad-inline":/repo:ro -w /repo "$ZIZMOR_PINNED" --pedantic --offline --no-progress \
  --no-config .github 2>&1)" || status=$?
[ "$status" -ge 10 ] && grep -qF 'unpinned-uses' <<<"$output" ||
  fail "zizmor's default (no config) no longer refuses a tag-pinned action; the config is then load-bearing: $status $output"

run_lint "$HERE/nonexistent"
[ "$status" -eq 2 ] || fail "missing directory: expected exit 2, got $status: $output"

mkdir -p "$HERE/fixtures/pins/zizmor-empty-$$"
run_lint "$HERE/fixtures/pins/zizmor-empty-$$"
rmdir "$HERE/fixtures/pins/zizmor-empty-$$"
[ "$status" -eq 2 ] || fail "a directory without workflows is an error, not a pass: got $status: $output"

if [ "$failures" -ne 0 ]; then
  printf '%s check(s) failed\n' "$failures"
  exit 1
fi
echo 'lint-workflows: all checks passed'
