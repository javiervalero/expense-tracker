#!/usr/bin/env bash
# Tests for scripts/publish-site.sh and the owner-run deploy-* Make targets.
# Run: bash scripts/tests/publish-site.test.sh
# A fake `aws` on PATH and a fake terraform-aws.sh (via TERRAFORM_AWS) record their calls. No Docker, AWS or network.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SCRIPT="$REPO/scripts/publish-site.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/bin" "$WORK/dist/assets"
: >"$WORK/dist/index.html"
: >"$WORK/dist/favicon.ico"
: >"$WORK/dist/assets/app.js"

cat >"$WORK/bin/aws" <<'EOF'
#!/usr/bin/env bash
set +x
printf '%s\n' "$*" >>"$FAKE_DIR/aws.log"
# Records only non-secret settings, never a credential value.
printf 'region=%s default_region=%s pager=[%s] pager_set=%s profile_set=%s imds_disabled=%s\n' \
  "${AWS_REGION-}" "${AWS_DEFAULT_REGION-}" "${AWS_PAGER-}" "${AWS_PAGER+yes}" "${AWS_PROFILE+yes}" "${AWS_EC2_METADATA_DISABLED-}" >>"$FAKE_DIR/aws.env.log"
exit "${FAKE_AWS_EXIT:-0}"
EOF
cat >"$WORK/bin/terraform-aws.sh" <<'EOF'
#!/usr/bin/env bash
set +x
printf '%s\n' "$*" >>"$FAKE_DIR/tf.log"
case "$*" in
  *"output -raw site_bucket") echo fake-site-bucket ;;
  *"output -raw distribution_id") echo EFAKEDIST123 ;;
  *) exit 9 ;;
esac
EOF
chmod +x "$WORK/bin/aws" "$WORK/bin/terraform-aws.sh"

TOKEN=fakeSessionTokenValue987
failures=0
status=0
out=''

run_script() { # run_script <token-mode: set|empty|unset> [extra env assignments via env]
  local mode="$1"
  export FAKE_DIR="$WORK/run"
  rm -rf "$FAKE_DIR"
  mkdir -p "$FAKE_DIR"
  : >"$FAKE_DIR/aws.log"
  status=0
  case "$mode" in
    set) out="$(PATH="$WORK/bin:$PATH" AWS_PROFILE=someprofile AWS_SESSION_TOKEN="$TOKEN" TERRAFORM_AWS="$WORK/bin/terraform-aws.sh" FRONTEND_DIST="$WORK/dist" bash "$SCRIPT" 2>&1)" || status=$? ;;
    empty) out="$(PATH="$WORK/bin:$PATH" AWS_SESSION_TOKEN= TERRAFORM_AWS="$WORK/bin/terraform-aws.sh" FRONTEND_DIST="$WORK/dist" bash "$SCRIPT" 2>&1)" || status=$? ;;
    unset) out="$(env -u AWS_SESSION_TOKEN PATH="$WORK/bin:$PATH" TERRAFORM_AWS="$WORK/bin/terraform-aws.sh" FRONTEND_DIST="$WORK/dist" bash "$SCRIPT" 2>&1)" || status=$? ;;
  esac
}

check() { # check <name> <condition exit code>
  if [ "$2" -eq 0 ]; then echo "ok - $1"; else echo "not ok - $1"; failures=$((failures + 1)); fi
}

for mode in empty unset; do
  run_script "$mode"
  check "refuses with exit 1 when the session token is $mode" "$([ "$status" -eq 1 ] && echo 0 || echo 1)"
  check "makes no aws call when the token is $mode" "$([ ! -s "$FAKE_DIR/aws.log" ] && echo 0 || echo 1)"
  check "does not read terraform output when the token is $mode" "$([ ! -e "$FAKE_DIR/tf.log" ] && echo 0 || echo 1)"
done

run_script set
log="$FAKE_DIR/aws.log"
check "succeeds with a token" "$([ "$status" -eq 0 ] && echo 0 || echo 1)"
check "reads site_bucket and distribution_id from terraform output" \
  "$(grep -q 'output -raw site_bucket' "$FAKE_DIR/tf.log" && grep -q 'output -raw distribution_id' "$FAKE_DIR/tf.log" && echo 0 || echo 1)"

assets_line="$(grep -n 's3 sync .*dist/assets s3://fake-site-bucket/assets/' "$log" | head -1 | cut -d: -f1 || true)"
others_line="$(grep -n 's3 sync .*dist s3://fake-site-bucket/ ' "$log" | head -1 | cut -d: -f1 || true)"
index_line="$(grep -n 's3 cp .*index.html s3://fake-site-bucket/index.html' "$log" | head -1 | cut -d: -f1 || true)"
inval_line="$(grep -n 'cloudfront create-invalidation' "$log" | head -1 | cut -d: -f1 || true)"
check "syncs assets/ to the assets/ prefix" "$([ -n "$assets_line" ] && echo 0 || echo 1)"
check "uploads the other root files, excluding assets/ and index.html" \
  "$([ -n "$others_line" ] && sed -n "${others_line}p" "$log" | grep -q -- "--exclude assets/\*" && sed -n "${others_line}p" "$log" | grep -q -- "--exclude index.html" && echo 0 || echo 1)"
check "uploads index.html" "$([ -n "$index_line" ] && echo 0 || echo 1)"
IMMUTABLE_HEADER='--cache-control public, max-age=31536000, immutable'
check "assets sync is immutable for a year" \
  "$([ -n "$assets_line" ] && sed -n "${assets_line}p" "$log" | grep -qF -- "$IMMUTABLE_HEADER" && echo 0 || echo 1)"
check "other-root-files sync is no-cache" \
  "$([ -n "$others_line" ] && sed -n "${others_line}p" "$log" | grep -qE -- '--cache-control no-cache$' && echo 0 || echo 1)"
check "index.html cp is no-cache" \
  "$([ -n "$index_line" ] && sed -n "${index_line}p" "$log" | grep -qE -- '--cache-control no-cache$' && echo 0 || echo 1)"
env_log="$FAKE_DIR/aws.env.log"
check "every aws call runs in us-east-1 (AWS_REGION and AWS_DEFAULT_REGION)" \
  "$([ -s "$env_log" ] && [ "$(grep -c 'region=us-east-1 default_region=us-east-1 ' "$env_log")" -eq "$(wc -l <"$env_log")" ] && echo 0 || echo 1)"
check "every aws call has an empty AWS_PAGER set" \
  "$([ -s "$env_log" ] && [ "$(grep -c 'pager=\[\] pager_set=yes ' "$env_log")" -eq "$(wc -l <"$env_log")" ] && echo 0 || echo 1)"
check "AWS_PROFILE is unset for every aws call even if the caller set it" \
  "$([ -s "$env_log" ] && ! grep -q 'profile_set=yes' "$env_log" && echo 0 || echo 1)"
check "instance metadata lookups are disabled" \
  "$([ -s "$env_log" ] && ! grep -qv 'imds_disabled=true$' "$env_log" && echo 0 || echo 1)"
check "order: assets, other files, index.html, invalidation" \
  "$([ -n "$assets_line$others_line$index_line$inval_line" ] && [ "${assets_line:-99}" -lt "${others_line:-0}" ] && [ "${others_line:-99}" -lt "${index_line:-0}" ] && [ "${index_line:-99}" -lt "${inval_line:-0}" ] && echo 0 || echo 1)"
check "index.html is the last upload" "$([ "$(grep -c '^s3 ' "$log")" -eq "$index_line" ] && echo 0 || echo 1)"
check "index.html carries text/html; charset=utf-8" \
  "$([ -n "$index_line" ] && sed -n "${index_line}p" "$log" | grep -q -- '--content-type text/html; charset=utf-8' && echo 0 || echo 1)"
check "invalidates /* of the distribution" \
  "$([ -n "$inval_line" ] && sed -n "${inval_line}p" "$log" | grep -q -- '--distribution-id EFAKEDIST123 --paths /\*' && echo 0 || echo 1)"
check "no aws call uses --delete" "$(grep -q -- '--delete' "$log" && echo 1 || echo 0)"
check "the session token value is never printed" "$(printf '%s' "$out" | grep -qF "$TOKEN" && echo 1 || echo 0)"
check "the session token value is never in an aws argument" "$(grep -qF "$TOKEN" "$log" && echo 1 || echo 0)"

# A failing upload stops the run before the invalidation.
export FAKE_AWS_EXIT=1
run_script set
unset FAKE_AWS_EXIT
check "fails when aws fails" "$([ "$status" -ne 0 ] && echo 0 || echo 1)"
check "no invalidation after a failed upload" "$(grep -q cloudfront "$FAKE_DIR/aws.log" && echo 1 || echo 0)"

# Make targets (dry run only; nothing real runs).
cd "$REPO"
plan_destroy="$(make -n deploy-plan DESTROY=1 2>&1)" || true
plan_normal="$(make -n deploy-plan 2>&1)" || true
plan_zero="$(make -n deploy-plan DESTROY=0 2>&1)" || true
plan_false="$(make -n deploy-plan DESTROY=false 2>&1)" || true
check "deploy-plan DESTROY=1 passes -destroy to terraform plan" "$(printf '%s' "$plan_destroy" | grep -q -- 'plan -input=false -destroy ' && echo 0 || echo 1)"
check "deploy-plan without DESTROY has no -destroy flag" "$(printf '%s' "$plan_normal" | grep -q -- ' -destroy ' && echo 1 || echo 0)"
check "deploy-plan DESTROY=0 has no -destroy flag and plans normally" \
  "$(printf '%s' "$plan_zero" | grep -q -- 'plan -input=false' && ! printf '%s' "$plan_zero" | grep -q -- ' -destroy ' && echo 0 || echo 1)"
check "deploy-plan DESTROY=false has no -destroy flag and plans normally" \
  "$(printf '%s' "$plan_false" | grep -q -- 'plan -input=false' && ! printf '%s' "$plan_false" | grep -q -- ' -destroy ' && echo 0 || echo 1)"
check "deploy-plan never uses -auto-approve" "$(printf '%s%s' "$plan_destroy" "$plan_normal" | grep -q -- '-auto-approve' && echo 1 || echo 0)"
build_dry="$(make -n deploy-build 2>&1)" || true
build_order="$(printf '%s\n' "$build_dry" | grep -oE 'make[^ ]* --no-print-directory [a-z-]+' | awk '{print $NF}' | tr '\n' ' ' || true)"
check "deploy-build runs its steps in order" \
  "$([ "$build_order" = "lambda-build lambda-check tf-layers-check frontend-build check-bundle-secrets check-bundle-csp secrets-scan-worktree " ] && echo 0 || echo 1)"
apply_dry="$(make -n deploy-apply 2>&1)" || true
check "deploy-apply never uses -auto-approve" "$(printf '%s' "$apply_dry" | grep -q -- '-auto-approve' && echo 1 || echo 0)"
check "deploy-init uses a read-only lock file and the backend config" \
  "$(make -n deploy-init 2>&1 | grep -q -- '-lockfile=readonly -backend-config=backend.hcl' && echo 0 || echo 1)"
smoke_dry="$(make -n deploy-smoke 2>&1)" || true
check "deploy-smoke runs the cross-user smoke" "$(printf '%s' "$smoke_dry" | grep -q -- 'smoke.sh --cross-user' && echo 0 || echo 1)"
check "deploy-smoke fails when the domain lookup is empty" "$(printf '%s' "$smoke_dry" | grep -q -- 'set -e; d=.*test -n "\$d"' && echo 0 || echo 1)"
lint_targets="$(sed -n 's/^LINT_TARGETS = //p' Makefile)"
phony="$(sed -n 's/^\.PHONY: //p' Makefile | head -1)"
for t in deploy-build deploy-init deploy-plan deploy-apply publish-site deploy-smoke; do
  check "$t is in .PHONY" "$(printf ' %s ' "$phony" | grep -q " $t " && echo 0 || echo 1)"
  check "$t is not in LINT_TARGETS" "$(printf ' %s ' "$lint_targets" | grep -q " $t " && echo 1 || echo 0)"
done

[ "$failures" -eq 0 ] || { echo "$failures check(s) failed" >&2; exit 1; }
echo "all checks passed"
