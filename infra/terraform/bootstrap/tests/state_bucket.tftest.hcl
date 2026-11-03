# Offline tests of the remote-state bucket (the D5 checklist of the Phase 7 plan). The AWS provider is mocked, so nothing
# here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in every test file).
# `command = apply`: under the mock the ARNs are unknown at plan time, and they are random strings, so the policy is
# asserted through its JSON structure and not through ARN text. `prevent_destroy` cannot be asserted here (the resource
# has no `lifecycle` attribute); scripts/check-prevent-destroy.sh covers it.
mock_provider "aws" {}

variables {
  aws_region     = "us-east-1"
  aws_account_id = "123456789012" # the AWS documentation example account
}

run "bucket_name_has_the_account_and_region" {
  command = apply

  assert {
    condition     = aws_s3_bucket.state.bucket == "expense-tracker-tfstate-123456789012-us-east-1"
    error_message = "The bucket name must be expense-tracker-tfstate-<account>-<region>."
  }
}

run "versioning_is_enabled" {
  command = apply

  assert {
    condition     = tolist(aws_s3_bucket_versioning.state.versioning_configuration)[0].status == "Enabled"
    error_message = "Versioning must be Enabled."
  }
}

run "encryption_is_sse_s3_without_kms" {
  command = apply

  assert {
    condition     = tolist(tolist(aws_s3_bucket_server_side_encryption_configuration.state.rule)[0].apply_server_side_encryption_by_default)[0].sse_algorithm == "AES256"
    error_message = "The default encryption must be AES256 (SSE-S3)."
  }

  # The mock fills the unset kms_master_key_id with a random string (a computed attribute), so "no key" cannot be
  # asserted on it; the algorithm is what selects KMS, and AES256 above already excludes the KMS algorithms. What the
  # test adds is that there is exactly one rule, so no second rule can bring KMS in.
  assert {
    condition     = length(aws_s3_bucket_server_side_encryption_configuration.state.rule) == 1
    error_message = "There must be exactly one default-encryption rule."
  }
}

run "public_access_is_fully_blocked" {
  command = apply

  assert {
    condition     = aws_s3_bucket_public_access_block.state.block_public_acls == true
    error_message = "block_public_acls must be true."
  }

  assert {
    condition     = aws_s3_bucket_public_access_block.state.block_public_policy == true
    error_message = "block_public_policy must be true."
  }

  assert {
    condition     = aws_s3_bucket_public_access_block.state.ignore_public_acls == true
    error_message = "ignore_public_acls must be true."
  }

  assert {
    condition     = aws_s3_bucket_public_access_block.state.restrict_public_buckets == true
    error_message = "restrict_public_buckets must be true."
  }
}

run "ownership_is_bucket_owner_enforced" {
  command = apply

  assert {
    condition     = tolist(aws_s3_bucket_ownership_controls.state.rule)[0].object_ownership == "BucketOwnerEnforced"
    error_message = "object_ownership must be BucketOwnerEnforced (no ACLs)."
  }
}

run "policy_denies_insecure_transport_only" {
  command = apply

  assert {
    condition     = length(jsondecode(aws_s3_bucket_policy.state.policy).Statement) == 1
    error_message = "The policy must have exactly one statement."
  }

  assert {
    condition     = jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Effect == "Deny"
    error_message = "The statement must Deny."
  }

  assert {
    condition     = jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Principal == "*"
    error_message = "The principal must be \"*\"."
  }

  assert {
    condition     = jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Action == "s3:*"
    error_message = "The action must be s3:*."
  }

  assert {
    condition     = jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Condition == { Bool = { "aws:SecureTransport" = "false" } }
    error_message = "The condition must be Bool aws:SecureTransport = \"false\"."
  }

  # Under `apply` the mock's ARN is a known value, and the policy is built from the same attribute, so the deny can be
  # pinned to THIS bucket (a policy aimed at some other bucket would protect nothing).
  assert {
    condition = toset(jsondecode(aws_s3_bucket_policy.state.policy).Statement[0].Resource) == toset([
      aws_s3_bucket.state.arn,
      "${aws_s3_bucket.state.arn}/*",
    ])
    error_message = "The statement must cover exactly this bucket and its objects (ARN and ARN/*)."
  }

  assert {
    condition     = length(jsondecode(aws_s3_bucket_policy.state.policy).Statement) == 1
    error_message = "The policy must hold exactly the one deny statement."
  }
}

run "lifecycle_expires_noncurrent_versions_and_aborts_multipart" {
  command = apply

  assert {
    condition     = length(aws_s3_bucket_lifecycle_configuration.state.rule) == 1
    error_message = "There must be exactly one lifecycle rule."
  }

  assert {
    condition     = tolist(aws_s3_bucket_lifecycle_configuration.state.rule)[0].status == "Enabled"
    error_message = "The lifecycle rule must be Enabled."
  }

  assert {
    condition     = tolist(tolist(aws_s3_bucket_lifecycle_configuration.state.rule)[0].noncurrent_version_expiration)[0].noncurrent_days == 90
    error_message = "Noncurrent versions must expire after 90 days."
  }

  assert {
    condition     = tolist(tolist(aws_s3_bucket_lifecycle_configuration.state.rule)[0].abort_incomplete_multipart_upload)[0].days_after_initiation == 7
    error_message = "Incomplete multipart uploads must be aborted after 7 days."
  }
}

run "bucket_has_no_force_destroy" {
  command = apply

  assert {
    condition     = aws_s3_bucket.state.force_destroy == false || aws_s3_bucket.state.force_destroy == null
    error_message = "The state bucket must not set force_destroy."
  }
}

run "outputs_expose_only_name_and_arn" {
  command = apply

  assert {
    condition     = output.state_bucket_name == "expense-tracker-tfstate-123456789012-us-east-1"
    error_message = "state_bucket_name must be the bucket name."
  }

  assert {
    condition     = output.state_bucket_arn == aws_s3_bucket.state.arn
    error_message = "state_bucket_arn must be the bucket ARN."
  }
}

run "rejects_bad_region" {
  command = plan
  variables {
    aws_region = "Mars"
  }
  expect_failures = [var.aws_region]
}

run "rejects_bad_account_id" {
  command = plan
  variables {
    aws_account_id = "12345"
  }
  expect_failures = [var.aws_account_id]
}
