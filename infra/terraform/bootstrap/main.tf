# Intentionally empty in Phase 0: no resources, no provider configuration, no credentials.
#
# Phase 7 adds here (ADR 0009):
#   - the S3 bucket that holds the Terraform state of envs/dev, with this checklist:
#       [ ] versioning enabled;
#       [ ] server-side encryption with SSE-S3 (AES256). SSE-KMS would also need kms:Encrypt, kms:Decrypt and
#           kms:GenerateDataKey for the deploy role, including for the .tflock object, so it is not chosen;
#       [ ] public access block with all four settings on;
#       [ ] bucket policy that denies every request where aws:SecureTransport is "false";
#       [ ] ownership controls set to BucketOwnerEnforced (no ACLs);
#       [ ] `lifecycle { prevent_destroy = true }` on the bucket and on its configuration resources;
#       [ ] lifecycle rule: expire noncurrent versions (for example after 90 days) and
#           abort_incomplete_multipart_upload;
#       [ ] after the first apply, migrate the state of this bootstrap root (local until then) into the bucket under
#           its own key (`terraform init -migrate-state` with a backend block for it);
#   - nothing for locking: Terraform >= 1.10 locks the state with an object in the same bucket (`use_lockfile = true`
#     in the backend configuration of envs/dev), so no DynamoDB lock table is needed.
# This root is applied by hand with the owner's explicit approval, never by CI.
