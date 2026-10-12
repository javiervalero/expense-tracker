#!/usr/bin/env bash
# Tests for `make tf-check`. Run: bash scripts/tests/terraform-check.test.sh
# Works on copies of infra/terraform made under .terraform/ (git-ignored, inside the repo so the container sees it):
# the real files are never touched. Needs Docker; the provider download is cached in .terraform/plugin-cache.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
mkdir -p "$REPO/.terraform"
WORK="$(mktemp -d "$REPO/.terraform/tf-check-test.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT
REL="${WORK#"$REPO"/}"

failures=0
status=0
output=''

fresh_copy() { # <name> -> path (relative to the repo) of a copy of the tree, without any .terraform directory
  cp -r "$REPO/infra/terraform" "$WORK/$1"
  find "$WORK/$1" -name .terraform -type d -prune -exec rm -rf {} +
  echo "$REL/$1"
}

run_check() { # <tf dir relative to the repo>
  status=0
  output="$(make -s -C "$REPO" tf-check TF_DIR="$1" 2>&1)" || status=$?
}

expect() { # <0 = passes | fail = make reports a failed recipe (exit 2)> <label>
  local want="$1"
  [ "$want" = fail ] && want=2
  if [ "$status" -ne "$want" ]; then
    failures=$((failures + 1))
    printf 'FAIL %s: expected exit %s, got %s\n%s\n' "$2" "$want" "$status" "$output"
  fi
}

expect_output() { # <fixed string> <label>
  if ! grep -qF -- "$1" <<<"$output"; then
    failures=$((failures + 1))
    printf 'FAIL %s: output does not contain %q\n%s\n' "$2" "$1" "$output"
  fi
}

lock_fingerprint() {
  cat "$REPO"/infra/terraform/bootstrap/.terraform.lock.hcl "$REPO"/infra/terraform/envs/dev/.terraform.lock.hcl | sha256sum
}

dir="$(fresh_copy ok)"
run_check "$dir"
expect 0 'untouched copy passes'

dir="$(fresh_copy misformatted)"
printf 'variable   "x"   {\ntype=string\n}\n' >"$WORK/misformatted/envs/dev/extra.tf"
run_check "$dir"
expect fail 'misformatted .tf fails fmt -check'
expect_output 'extra.tf' 'fmt names the misformatted file'

dir="$(fresh_copy syntax-error)"
printf 'variable "x" {\n  type = string\n' >"$WORK/syntax-error/envs/dev/extra.tf"
run_check "$dir"
expect fail 'syntax error fails the check'
expect_output 'Error:' 'syntax error is reported by Terraform'
expect_output 'Unclosed configuration block' 'syntax error names the unclosed block'

dir="$(fresh_copy bad-reference)"
printf 'output "x" {\n  value = var.does_not_exist\n}\n' >"$WORK/bad-reference/envs/dev/extra.tf"
run_check "$dir"
expect fail 'reference to an undeclared variable fails validate'
expect_output 'does_not_exist' 'validate names the undeclared variable'

dir="$(fresh_copy bad-validation)"
printf 'variable "y" {\n  type = string\n\n  validation {\n    condition = length(var.y) > 5\n  }\n}\n' >"$WORK/bad-validation/envs/dev/extra.tf"
run_check "$dir"
expect fail 'a validation block without error_message fails validate'
expect_output 'Error:' 'validation failure is reported by Terraform'
expect_output 'error_message' 'validation failure names the missing error_message'

before="$(lock_fingerprint)"
dir="$(fresh_copy lock-untouched)"
run_check "$dir"
expect 0 'second pass on a fresh copy passes'
[ "$before" = "$(lock_fingerprint)" ] || { failures=$((failures + 1)); echo 'FAIL real lock files changed'; }

dir="$(fresh_copy missing-lock)"
rm "$WORK/missing-lock/envs/dev/.terraform.lock.hcl"
run_check "$dir"
expect fail 'a missing lock file fails with -lockfile=readonly'
if [ -e "$WORK/missing-lock/envs/dev/.terraform.lock.hcl" ]; then
  failures=$((failures + 1))
  echo 'FAIL a lock file was created'
fi

if [ "$failures" -ne 0 ]; then
  echo "terraform-check: $failures failure(s)"
  exit 1
fi
echo 'terraform-check: all tests passed'
