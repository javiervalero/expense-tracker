#!/usr/bin/env bash
# Tests for scripts/terraform-aws.sh. Run: bash scripts/tests/terraform-aws.test.sh
# A fake `docker` on PATH records its argv; no real Docker, network or AWS is used.
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$HERE/../.." && pwd)"
SCRIPT="$REPO/scripts/terraform-aws.sh"
PLAIN="$REPO/scripts/terraform.sh"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/bin"
cat >"$WORK/bin/docker" <<'EOF'
#!/usr/bin/env bash
set +x  # the xtrace case must not make this fake print the values it receives
printf '%s\n' "$@" >"$FAKE_DOCKER_DIR/argv"
# Record only whether the credential reached docker's environment, not its value.
[ "${AWS_SESSION_TOKEN:-}" = "${EXPECTED_TOKEN:-}" ] && : >"$FAKE_DOCKER_DIR/env_token_ok"
exit 0
EOF
chmod +x "$WORK/bin/docker"

KEY=AKIAFAKEKEYVALUE01
SECRET=fakeSecretValue/abc123XYZ
TOKEN=fakeSessionTokenValue987

failures=0
status=0
stderr_text=''

fail() { failures=$((failures + 1)); printf 'FAIL %s\n' "$1"; }

# run <label> [VAR=value ...] -- <args...>: runs the script with a clean environment plus the given variables.
run() {
  local vars=()
  while [ "$1" != -- ]; do vars+=("$1"); shift; done
  shift
  rm -f "$WORK/argv" "$WORK/env_token_ok"
  status=0
  stderr_text="$(env -i PATH="$WORK/bin:$PATH" HOME="$WORK" FAKE_DOCKER_DIR="$WORK" EXPECTED_TOKEN="$TOKEN" "${vars[@]}" \
    bash "$SCRIPT" "$@" 2>&1 >/dev/null)" || status=$?
}

docker_called() { [ -f "$WORK/argv" ]; }
argv_has() { grep -qxF -- "$1" "$WORK/argv"; }

expect_refused() { # <label>
  [ "$status" -eq 1 ] || fail "$1: expected exit 1, got $status"
  ! docker_called || fail "$1: docker was called"
  [ -n "$stderr_text" ] || fail "$1: no message on stderr"
  for v in "$KEY" "$SECRET" "$TOKEN"; do
    case "$stderr_text" in *"$v"*) fail "$1: a secret value leaked to stderr" ;; esac
  done
}

ALL=(AWS_ACCESS_KEY_ID=$KEY AWS_SECRET_ACCESS_KEY=$SECRET AWS_SESSION_TOKEN=$TOKEN)

run -- version
expect_refused "nothing set"
run AWS_ACCESS_KEY_ID=$KEY AWS_SECRET_ACCESS_KEY=$SECRET -- version
expect_refused "token unset"
run AWS_ACCESS_KEY_ID=$KEY AWS_SECRET_ACCESS_KEY=$SECRET AWS_SESSION_TOKEN= -- version
expect_refused "token empty"
run AWS_SECRET_ACCESS_KEY=$SECRET AWS_SESSION_TOKEN=$TOKEN -- version
expect_refused "key unset"
run AWS_ACCESS_KEY_ID=$KEY AWS_SESSION_TOKEN=$TOKEN -- version
expect_refused "secret unset"
run AWS_ACCESS_KEY_ID= AWS_SECRET_ACCESS_KEY=$SECRET AWS_SESSION_TOKEN=$TOKEN -- version
expect_refused "key empty"

# All three set: names forwarded without values.
run "${ALL[@]}" -- -chdir=infra/terraform/envs/dev plan -out=x
[ "$status" -eq 0 ] || fail "all set: expected exit 0, got $status"
docker_called || fail "all set: docker not called"
if docker_called; then
  for n in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN; do
    grep -A1 -xF -- '-e' "$WORK/argv" | grep -qxF -- "$n" || fail "all set: $n not forwarded as '-e $n'"
  done
  [ -f "$WORK/env_token_ok" ] || fail "the session token did not reach docker's environment"
  if grep -qF -e "$KEY" -e "$SECRET" -e "$TOKEN" "$WORK/argv"; then fail "secret value in argv"; fi
  if grep -q '^AWS_[A-Z_]*=' "$WORK/argv" && ! grep -qx 'AWS_EC2_METADATA_DISABLED=true' "$WORK/argv"; then fail "unexpected AWS_*=value"; fi
  [ "$(grep -c '^AWS_.*=' "$WORK/argv")" -eq 1 ] || fail "only AWS_EC2_METADATA_DISABLED may carry a value"
  for flag in --cap-drop=ALL --security-opt=no-new-privileges TF_IN_AUTOMATION=1 TF_INPUT=0 \
    TF_PLUGIN_CACHE_DIR=/app/.terraform/plugin-cache "$REPO:/app" HOME=/tmp /app "$(id -u):$(id -g)"; do
    argv_has "$flag" || fail "hardening flag missing: $flag"
  done
  if grep -q '\.aws' "$WORK/argv"; then fail "~/.aws mounted"; fi
  # Arguments pass through unchanged, in order, at the end.
  [ "$(tail -n 3 "$WORK/argv" | paste -sd' ')" = "-chdir=infra/terraform/envs/dev plan -out=x" ] || fail "args not passed through"
fi

# Tracing (bash -x or SHELLOPTS=xtrace) must not print the credential values.
run "${ALL[@]}" SHELLOPTS=xtrace -- version
for v in "$KEY" "$SECRET" "$TOKEN"; do
  case "$stderr_text" in *"$v"*) fail "xtrace: a secret value leaked to stderr" ;; esac
done

# Other AWS_* variables are not forwarded.
run "${ALL[@]}" AWS_PROFILE=someprofile AWS_REGION=eu-west-1 AWS_DEFAULT_REGION=eu-west-1 -- version
if docker_called; then
  if grep -q -e 'AWS_PROFILE' -e 'AWS_REGION' -e 'AWS_DEFAULT_REGION' -e 'someprofile' -e 'eu-west-1' "$WORK/argv"; then fail "extra AWS_* forwarded"; fi
else fail "extra AWS_*: docker not called"; fi

# TERRAFORM_IMAGE override ignored; digest equals terraform.sh's.
PINNED="$(grep -o 'hashicorp/terraform:[^"}]*@sha256:[0-9a-f]\{64\}' "$PLAIN" | head -n1)"
[ -n "$PINNED" ] || fail "no pinned image found in terraform.sh"
run "${ALL[@]}" TERRAFORM_IMAGE=evil/image:latest -- version
if docker_called; then
  argv_has "$PINNED" || fail "pinned image not used"
  if grep -q 'evil/image' "$WORK/argv"; then fail "TERRAFORM_IMAGE override honoured"; fi
else fail "image check: docker not called"; fi

# terraform.sh stays offline: no credentials in it.
if grep -q -e AWS_ACCESS_KEY_ID -e AWS_SESSION_TOKEN -e AWS_SECRET_ACCESS_KEY "$PLAIN"; then fail "terraform.sh mentions AWS credentials"; fi

if [ "$failures" -ne 0 ]; then printf '%s failure(s)\n' "$failures"; exit 1; fi
echo "terraform-aws.test.sh: all checks passed"
