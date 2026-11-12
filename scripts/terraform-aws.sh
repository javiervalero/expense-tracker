#!/usr/bin/env bash
# Usage: scripts/terraform-aws.sh <terraform arguments...>   (for example: scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev plan)
# For the OWNER'S TERMINAL ONLY. Same container as scripts/terraform.sh, but it forwards the owner's temporary AWS
# credentials (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN) from the caller's environment, by name only
# (-e NAME), so the values never appear on the command line or in this file. Agents never hold credentials and must not run it.
# It refuses to run unless all three are set and non-empty, so plain long-lived keys (no session token) are not enough.
# It does not check that the token is valid: an expired token or a wrong account fails at AWS (allowed_account_ids).
# scripts/terraform.sh stays offline and unchanged. The image is pinned here and TERRAFORM_IMAGE is ignored.
set -euo pipefail

# Keep this digest identical to the one in scripts/terraform.sh (scripts/tests/terraform-aws.test.sh checks it).
IMAGE="hashicorp/terraform:1.16.5@sha256:c7926feace05d0f7e73542842bf3945924e955a1f782cf000ccbb8d18fa42d77"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

for name in AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY AWS_SESSION_TOKEN; do
  # ${!name:+set} tests presence without expanding the value, so `bash -x` cannot print the credentials.
  if [ -z "${!name:+set}" ]; then
    echo "terraform-aws.sh: $name is not set or empty; export temporary credentials (including AWS_SESSION_TOKEN) first." >&2
    exit 1
  fi
done

mkdir -p "$REPO/.terraform/plugin-cache"

# --log-driver none: `terraform show -json` prints every generated secret in plain text, and Docker's default log store
# would keep a copy of the container output on disk. --ulimit core=0: a crash must not write memory (secrets) to a core file.
exec docker run --rm \
  --log-driver none --ulimit core=0 \
  --cap-drop=ALL --security-opt=no-new-privileges \
  -u "$(id -u):$(id -g)" \
  -v "$REPO":/app -w /app \
  -e HOME=/tmp \
  -e TF_IN_AUTOMATION=1 \
  -e TF_INPUT=0 \
  -e TF_PLUGIN_CACHE_DIR=/app/.terraform/plugin-cache \
  -e AWS_EC2_METADATA_DISABLED=true \
  -e AWS_ACCESS_KEY_ID -e AWS_SECRET_ACCESS_KEY -e AWS_SESSION_TOKEN \
  "$IMAGE" "$@"
