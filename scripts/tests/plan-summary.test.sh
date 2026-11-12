#!/usr/bin/env bash
# Tests for scripts/plan-summary.php and the `make deploy-plan-summary` wiring. Run: bash scripts/tests/plan-summary.test.sh
# The script runs in the pinned php tools image (make php-image) with no network, as the host user; PHP_RUN can replace
# that command. The Make wiring uses a fake terraform wrapper (TERRAFORM_AWS): no AWS, credentials or network.
# Exit codes asserted: 0 = summary printed, 1 = bad input.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
FIX="$HERE/fixtures/plan-summary"
cd "$ROOT"

PHP_IMAGE="${PHP_IMAGE:-expense-tracker-php:dev}"
PHP_RUN="${PHP_RUN:-docker run --rm -i --network none --cap-drop ALL --security-opt no-new-privileges --read-only -u $(id -u):$(id -g) --mount type=bind,src=$ROOT/scripts/plan-summary.php,dst=/plan-summary.php,readonly $PHP_IMAGE php /plan-summary.php}"

docker image inspect "$PHP_IMAGE" >/dev/null 2>&1 || { echo "plan-summary.test.sh: run make php-image first" >&2; exit 1; }

mkdir -p build
WORK="$(mktemp -d "$ROOT/build/plan-summary-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

SENTINEL='planSentinel-7f3a9c2e'
SENTINEL_PREFIX='planSentinel'
FAILURE_MESSAGE="plan-summary: input is not a usable 'terraform show -json' plan (empty, truncated or not a plan)"
USAGE_MESSAGE='usage: plan-summary.php NORMAL|DESTROY (plan JSON on stdin)'
failures=0
status=0
out=''
err=''

fail() {
  failures=$((failures + 1))
  printf 'FAIL %s\n' "$1"
}

run_summary() { # <header> <stdin file>
  status=0
  # shellcheck disable=SC2086 # PHP_RUN is a command line on purpose
  timeout 120 $PHP_RUN "$1" <"$2" >"$WORK/stdout" 2>"$WORK/stderr" || status=$?
  out="$(cat "$WORK/stdout")"
  err="$(cat "$WORK/stderr")"
}

expect_status() { # <label> <expected>
  [ "$status" -eq "$2" ] || fail "$1: expected exit $2, got $status: $out / $err"
}

expect_out() { # <label> <expected stdout>
  [ "$out" = "$2" ] || fail "$1: unexpected output:
$out
--- wanted:
$2"
}

# The prefix is checked, not the whole value, so a partial leak is caught too. The sentinel can never match the type
# pattern, so the leak fixture proves only that no other field is printed; the type filter itself is tested by the
# odd-types fixture below.
expect_no_sentinel() { # <label>
  if grep -qF "$SENTINEL_PREFIX" "$WORK/stdout" "$WORK/stderr"; then fail "$1: the sentinel leaked into the output"; fi
}

expect_bad_input() { # <label>: exit 1, empty stdout, exactly the fixed message on stderr, no sentinel
  expect_status "$1" 1
  expect_no_sentinel "$1"
  [ -z "$out" ] || fail "$1: stdout is not empty: $out"
  [ "$err" = "$FAILURE_MESSAGE" ] || fail "$1: stderr is not the fixed message: $err"
}

# (1) Normal plan: exact output, identical across runs.
want_normal='plan summary: NORMAL
counts: create 2, update 1, delete 0, replace 0, other 0, no-op 2, read 1, forget 0, import 0
create aws_s3_bucket x2
read data.aws_caller_identity x1
update aws_lambda_function x1
no delete, no replace'
run_summary NORMAL "$FIX/normal.json"
expect_status 'normal plan' 0
expect_out 'normal plan' "$want_normal"
first="$out"
run_summary NORMAL "$FIX/normal.json"
[ "$out" = "$first" ] || fail 'normal plan: two runs gave different output'

# (3) No delete and no replace: the plain line, no warning. No-op is counted but gets no per-type line.
grep -q 'WARNING' <<<"$first" && fail 'normal plan: a WARNING without delete or replace'
grep -q 'aws_iam_role\|aws_iam_policy' <<<"$first" && fail 'normal plan: a no-op type got a line'

# Empty plan (no resource_changes array) is an empty plan, not an error.
run_summary NORMAL "$FIX/empty.json"
expect_status 'empty plan' 0
expect_out 'empty plan' 'plan summary: NORMAL
counts: create 0, update 0, delete 0, replace 0, other 0, no-op 0, read 0, forget 0, import 0
no delete, no replace'

# (3) Destroy plan: DESTROY header and a WARNING naming the types.
run_summary DESTROY "$FIX/destroy.json"
expect_status 'destroy plan' 0
expect_out 'destroy plan' 'plan summary: DESTROY
counts: create 0, update 0, delete 3, replace 0, other 0, no-op 0, read 0, forget 0, import 0
delete aws_dynamodb_table x1
delete aws_s3_bucket x2
!!! WARNING: this plan deletes, replaces or has an unrecognised action for:
!!! aws_dynamodb_table, aws_s3_bucket'

# (2), (4) Both replace orders, forget, import (counted next to its action) and an unknown action.
run_summary NORMAL "$FIX/odd.json"
expect_status 'odd plan' 0
expect_out 'odd plan' 'plan summary: NORMAL
counts: create 1, update 1, delete 0, replace 2, other 2, no-op 0, read 0, forget 1, import 1
create aws_d x1
forget aws_c x1
other aws_e x1
other aws_f x1
replace aws_a x1
replace aws_b x1
update aws_g x1
!!! WARNING: this plan deletes, replaces or has an unrecognised action for:
!!! aws_a, aws_b, aws_e, aws_f'

# Type filter edge cases: newline, ESC, space, uppercase, empty, non-string type, non-string mode.
run_summary NORMAL "$FIX/odd-types.json"
expect_status 'odd types' 0
expect_out 'odd types' 'plan summary: NORMAL
counts: create 10, update 0, delete 1, replace 0, other 0, no-op 0, read 0, forget 0, import 0
create (unrecognised type) x10
delete (unrecognised type) x1
!!! WARNING: this plan deletes, replaces or has an unrecognised action for:
!!! (unrecognised type)'
expect_no_sentinel 'odd types'
if grep -q $'\033' "$WORK/stdout" "$WORK/stderr"; then fail 'odd types: an ESC byte reached the output'; fi
[ "$(wc -l <"$WORK/stdout")" -eq 6 ] || fail 'odd types: unexpected number of output lines'
if grep -q -e 'aws_s3_bucket' -e 'AWS_S3' -e 'aws s3' "$WORK/stdout"; then fail 'odd types: raw type text was printed'; fi

# (5) Secret leak: the sentinel is in every field a real plan keeps secrets in; none of it may be printed.
occurrences="$(grep -o "$SENTINEL" "$FIX/leak.json" | wc -l)"
[ "$occurrences" -ge 15 ] || fail "leak fixture holds the sentinel only $occurrences times (vacuous test)"
for field in before after after_unknown after_sensitive before_sensitive replace_paths action_reason '"address"' '"name"' '"index"' module_address provider_name previous_address deposed output_changes prior_state configuration variables planned_values; do
  grep -q "$field" "$FIX/leak.json" || fail "leak fixture lacks the field $field"
done
grep -q '"type":"planSentinel' "$FIX/leak.json" || fail 'leak fixture: no sentinel in a type value'
run_summary NORMAL "$FIX/leak.json"
expect_status 'leak fixture' 0
expect_no_sentinel 'leak fixture'
[ -z "$err" ] || fail "leak fixture: stderr is not empty: $err"
expect_out 'leak fixture' 'plan summary: NORMAL
counts: create 1, update 1, delete 0, replace 1, other 0, no-op 0, read 0, forget 0, import 0
create (unrecognised type) x1
replace aws_s3_bucket x1
update aws_iam_role x1
!!! WARNING: this plan deletes, replaces or has an unrecognised action for:
!!! aws_s3_bucket'

# (6) Bad input: fixed message, exit 1, nothing from the input in the output.
PLAN_HEAD='"format_version":"1.2","terraform_version":"1.16.5","planned_values":{"root_module":{}}'

printf '{%s,"resource_changes":[{"mode":"managed","type":"%s"' "$PLAN_HEAD" "$SENTINEL" >"$WORK/truncated.json"
run_summary NORMAL "$WORK/truncated.json"
expect_bad_input 'truncated JSON'

printf '{"%s": not json %s' "$SENTINEL" "$SENTINEL" >"$WORK/invalid.json"
run_summary NORMAL "$WORK/invalid.json"
expect_bad_input 'invalid JSON with the sentinel'

: >"$WORK/empty-stdin"
run_summary NORMAL "$WORK/empty-stdin"
expect_bad_input 'empty stdin'

printf '{"values":{"root_module":{"resources":[{"name":"%s"}]}},"resource_changes":[]}' "$SENTINEL" >"$WORK/state.json"
run_summary NORMAL "$WORK/state.json"
expect_bad_input 'state JSON without format_version'

# A `terraform show -json` STATE document carries format_version and terraform_version but no planned_values.
printf '{"format_version":"1.0","terraform_version":"1.16.5","values":{"root_module":{"resources":[{"name":"%s"}]}}}' "$SENTINEL" >"$WORK/state-doc.json"
run_summary NORMAL "$WORK/state-doc.json"
expect_bad_input 'state document without planned_values'

printf '{"format_version":"1.2","planned_values":{},"resource_changes":[]}' >"$WORK/no-tfversion.json"
run_summary NORMAL "$WORK/no-tfversion.json"
expect_bad_input 'plan without terraform_version'

printf '{"format_version":"1.2","terraform_version":7,"planned_values":{},"resource_changes":[]}' >"$WORK/tfversion-number.json"
run_summary NORMAL "$WORK/tfversion-number.json"
expect_bad_input 'terraform_version that is not a string'

printf '{%s,"resource_changes":"%s"}' "$PLAN_HEAD" "$SENTINEL" >"$WORK/changes-string.json"
run_summary NORMAL "$WORK/changes-string.json"
expect_bad_input 'resource_changes that is not an array'

printf '{%s,"resource_changes":null}' "$PLAN_HEAD" >"$WORK/changes-null.json"
run_summary NORMAL "$WORK/changes-null.json"
expect_bad_input 'resource_changes null'

printf '{%s,"resource_changes":{"a":{"mode":"managed","type":"aws_a","change":{"actions":["create"]}}}}' "$PLAN_HEAD" >"$WORK/changes-object.json"
run_summary NORMAL "$WORK/changes-object.json"
expect_bad_input 'resource_changes as an object'

printf '{%s,"resource_changes":["%s"]}' "$PLAN_HEAD" "$SENTINEL" >"$WORK/entry-string.json"
run_summary NORMAL "$WORK/entry-string.json"
expect_bad_input 'resource_changes entry that is not an object'

# A plan without resource_changes (Terraform omits it when nothing changes) is an empty plan.
printf '{%s}' "$PLAN_HEAD" >"$WORK/no-changes-key.json"
run_summary NORMAL "$WORK/no-changes-key.json"
expect_status 'plan without resource_changes' 0
[ "$(tail -n 1 <<<"$out")" = 'no delete, no replace' ] || fail "plan without resource_changes: unexpected output: $out"

# Deeply nested input (generated here): must fail cleanly, not crash with the input in the message.
depth=200000
{
  printf '{"format_version":"1.2","x":'
  head -c "$depth" /dev/zero | tr '\0' '['
  printf '"%s"' "$SENTINEL"
  head -c "$depth" /dev/zero | tr '\0' ']'
  printf '}'
} >"$WORK/deep.json"
run_summary NORMAL "$WORK/deep.json"
expect_bad_input 'deeply nested JSON'

# A valid plan with 600 levels of nesting in an ignored field is refused too: the depth limit is 512.
{
  printf '{%s,"x":' "$PLAN_HEAD"
  head -c 600 /dev/zero | tr '\0' '['
  head -c 600 /dev/zero | tr '\0' ']'
  printf '}'
} >"$WORK/deep600.json"
run_summary NORMAL "$WORK/deep600.json"
expect_bad_input 'plan nested 600 levels deep'

# A bad or missing header argument is refused.
status=0
# shellcheck disable=SC2086
timeout 120 $PHP_RUN WRONG <"$FIX/normal.json" >"$WORK/stdout" 2>"$WORK/stderr" || status=$?
out="$(cat "$WORK/stdout")"
err="$(cat "$WORK/stderr")"
expect_status 'unknown header' 1
[ -z "$out" ] || fail 'unknown header: printed a summary'
[ "$err" = "$USAGE_MESSAGE" ] || fail "unknown header: stderr is not the usage message: $err"

# (7) Make wiring, with a fake terraform wrapper. PHP_IMAGE_DEP= skips the image build step.
cat >"$WORK/fake-tf" <<'EOF'
#!/usr/bin/env bash
set +x
printf '%s\n' "$*" >>"$FAKE_ARGS"
if [ -n "${FAKE_FAIL:-}" ]; then exit 3; fi
cat "$FAKE_JSON"
EOF
chmod +x "$WORK/fake-tf"
export FAKE_ARGS="$WORK/args.log"

run_make() { # runs the target against $WORK as the plan dir
  status=0
  : >"$FAKE_ARGS"
  timeout 180 make --no-print-directory TERRAFORM_AWS="$WORK/fake-tf" DEPLOY_PLAN_DIR="$WORK" PHP_IMAGE_DEP= deploy-plan-summary >"$WORK/stdout" 2>"$WORK/stderr" || status=$?
  out="$(cat "$WORK/stdout")"
  err="$(cat "$WORK/stderr")"
}

export FAKE_JSON="$FIX/normal.json"
run_make
expect_status 'make, no plan file' 2 # make exits 2 when a recipe fails
grep -q 'no plan file' "$WORK/stderr" || fail "make, no plan file: message missing: $err"
[ ! -s "$FAKE_ARGS" ] || fail 'make, no plan file: terraform was called'

: >"$WORK/dev.tfplan"
: >"$WORK/dev-destroy.tfplan"
run_make
expect_status 'make, both plan files' 2
grep -q 'both' "$WORK/stderr" || fail "make, both plan files: message missing: $err"
[ ! -s "$FAKE_ARGS" ] || fail 'make, both plan files: terraform was called'

rm -f "$WORK/dev-destroy.tfplan"
run_make
expect_status 'make, normal plan file' 0
[ "$(cat "$FAKE_ARGS")" = '-chdir=infra/terraform/envs/dev show -json dev.tfplan' ] || fail "make, normal: unexpected terraform args: $(cat "$FAKE_ARGS")"
expect_out 'make, normal plan file' "$want_normal"

rm -f "$WORK/dev.tfplan"
: >"$WORK/dev-destroy.tfplan"
FAKE_JSON="$FIX/destroy.json" run_make
expect_status 'make, destroy plan file' 0
[ "$(cat "$FAKE_ARGS")" = '-chdir=infra/terraform/envs/dev show -json dev-destroy.tfplan' ] || fail "make, destroy: unexpected terraform args: $(cat "$FAKE_ARGS")"
head -n 1 <<<"$out" | grep -qx 'plan summary: DESTROY' || fail "make, destroy: header is not DESTROY: $out"

# A failing `show` with empty output must fail the target (sh may lack pipefail; the empty-input refusal covers it).
FAKE_FAIL=1 run_make
expect_status 'make, terraform show fails' 2
[ -z "$out" ] || fail "make, terraform show fails: printed a summary: $out"
[ -e "$WORK/dev-destroy.tfplan" ] || fail 'make: deploy-plan-summary deleted the plan file'

# Dry-run checks of the Makefile wiring.
phony="$(sed -n 's/^\.PHONY: //p' Makefile | head -1)"
printf ' %s ' "$phony" | grep -q ' deploy-plan-summary ' || fail 'deploy-plan-summary is not in .PHONY'
lint_targets="$(sed -n 's/^LINT_TARGETS = //p' Makefile)"
printf ' %s ' "$lint_targets" | grep -q ' deploy-plan-summary ' && fail 'deploy-plan-summary is in LINT_TARGETS'
make help 2>/dev/null | grep 'deploy-plan-summary' | grep -q 'OWNER TERMINAL, needs temporary AWS credentials (agents never run it)' || fail 'deploy-plan-summary: help text missing or wrong'
dry="$(make -n deploy-plan-summary 2>&1 || true)"
for flag in -i '--network none' '--cap-drop ALL' 'no-new-privileges' '--read-only' '--log-driver none' '--ulimit core=0'; do
  grep -qF -- "$flag" <<<"$dry" || fail "dry run: docker flag $flag missing"
done
grep -q -- '-auto-approve' <<<"$dry" && fail 'dry run: -auto-approve'

if [ "$failures" -ne 0 ]; then
  printf '%d failure(s)\n' "$failures"
  exit 1
fi
echo 'plan-summary: all cases passed'
