#!/usr/bin/env bash
# Usage: scripts/publish-site.sh   (run by `make publish-site`)
# For the OWNER'S TERMINAL ONLY: uploads frontend/dist to the site bucket and invalidates CloudFront. Needs the host AWS
# CLI v2 and the owner's temporary credentials (AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_SESSION_TOKEN) exported.
# Agents never hold credentials and must not run it.
# Order matters: hashed assets first, other root files next, index.html LAST (it is the file that names the new assets),
# then the invalidation. No --delete anywhere: old hashed assets stay so open tabs keep working.
# Cache-Control is set to match the CloudFront response-headers policies (modules/edge): assets are immutable for a year
# (their names are content hashes); index.html and the other root files are no-cache (always revalidated).
# It pins us-east-1 (ADR 0013), an empty AWS_PAGER (no `less` hang), no AWS_PROFILE (env credentials only) and no IMDS lookups.
# TERRAFORM_AWS and FRONTEND_DIST can be overridden (scripts/tests/publish-site.test.sh does, with fakes).
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TERRAFORM_AWS="${TERRAFORM_AWS:-$REPO/scripts/terraform-aws.sh}"
DIST="${FRONTEND_DIST:-$REPO/frontend/dist}"
TF_DIR=infra/terraform/envs/dev

# ${VAR:+set} tests presence without expanding the value, so `bash -x` cannot print the credential.
if [ -z "${AWS_SESSION_TOKEN:+set}" ]; then
  echo "publish-site.sh: AWS_SESSION_TOKEN is not set or empty; export temporary credentials first." >&2
  exit 1
fi
export AWS_DEFAULT_REGION=us-east-1 AWS_REGION=us-east-1 AWS_PAGER="" AWS_EC2_METADATA_DISABLED=true
unset AWS_PROFILE
if [ ! -f "$DIST/index.html" ] || [ ! -d "$DIST/assets" ]; then
  echo "publish-site.sh: $DIST has no index.html and assets/; run 'make deploy-build' first." >&2
  exit 1
fi

cd "$REPO"
bucket="$("$TERRAFORM_AWS" -chdir="$TF_DIR" output -raw site_bucket)"
distribution_id="$("$TERRAFORM_AWS" -chdir="$TF_DIR" output -raw distribution_id)"
if [ -z "$bucket" ] || [ -z "$distribution_id" ]; then
  echo "publish-site.sh: terraform output gave an empty site_bucket or distribution_id (is the stack applied?)." >&2
  exit 1
fi

IMMUTABLE="public, max-age=31536000, immutable"

echo "publish-site: assets/ -> s3://$bucket/assets/"
aws s3 sync "$DIST/assets" "s3://$bucket/assets/" --cache-control "$IMMUTABLE"

echo "publish-site: other root files -> s3://$bucket/"
aws s3 sync "$DIST" "s3://$bucket/" --exclude "assets/*" --exclude index.html --cache-control no-cache

echo "publish-site: index.html (last)"
aws s3 cp "$DIST/index.html" "s3://$bucket/index.html" --content-type "text/html; charset=utf-8" --cache-control no-cache

echo "publish-site: invalidating /*"
aws cloudfront create-invalidation --distribution-id "$distribution_id" --paths '/*'
echo "publish-site: done"
