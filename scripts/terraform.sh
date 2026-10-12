#!/usr/bin/env bash
# Usage: scripts/terraform.sh <terraform arguments...>   (for example: scripts/terraform.sh -chdir=infra/terraform/envs/dev validate)
# Runs the pinned Terraform image with the repository mounted at /app, as the host user, with all capabilities dropped.
# Phase 0 has no AWS resources and no credentials: no AWS credentials reach the container because none are passed
# (the container only gets the -e variables below), ~/.aws is not mounted, and the instance-metadata lookup is off. The only network access is the provider download from the Terraform registry.
# The plugin cache lives in .terraform/ (git-ignored), so providers are downloaded once.
set -euo pipefail

# Bump the image tag, required_version in both versions.tf files and the provider pin together, then rerun `make tf-lock`.
TERRAFORM_IMAGE="${TERRAFORM_IMAGE:-hashicorp/terraform:1.16.5@sha256:c7926feace05d0f7e73542842bf3945924e955a1f782cf000ccbb8d18fa42d77}"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

mkdir -p "$REPO/.terraform/plugin-cache"

exec docker run --rm \
  --cap-drop=ALL --security-opt=no-new-privileges \
  -u "$(id -u):$(id -g)" \
  -v "$REPO":/app -w /app \
  -e HOME=/tmp \
  -e TF_IN_AUTOMATION=1 \
  -e TF_INPUT=0 \
  -e TF_PLUGIN_CACHE_DIR=/app/.terraform/plugin-cache \
  -e AWS_EC2_METADATA_DISABLED=true \
  "$TERRAFORM_IMAGE" "$@"
