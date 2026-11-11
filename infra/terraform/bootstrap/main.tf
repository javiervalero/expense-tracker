# The S3 bucket that holds the Terraform state of envs/dev (ADR 0009, Phase 7 plan D5).
#   - SSE-S3 (AES256), not SSE-KMS: KMS would also need kms:Encrypt, kms:Decrypt and kms:GenerateDataKey for the deploy
#     role, including for the .tflock object.
#   - Nothing for locking: Terraform >= 1.10 locks the state with an object in the same bucket (`use_lockfile = true`
#     in the backend configuration of envs/dev), so no DynamoDB lock table is needed.
#   - The bucket and every bucket-configuration resource carry `prevent_destroy = true`; `terraform test` cannot assert
#     it, so scripts/check-prevent-destroy.sh does (run by `make tf-unit`).
# This root is applied by hand with the owner's explicit approval, never by CI. Its state stays local
# (terraform.tfstate next to this root), is git-ignored, and the owner must back it up outside the repo: it is the
# only record that the state bucket is managed. It is not migrated into the bucket (ADR 0014).

locals {
  bucket_name = "expense-tracker-tfstate-${var.aws_account_id}-${var.aws_region}"
}

resource "aws_s3_bucket" "state" {
  bucket = local.bucket_name

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_versioning" "state" {
  bucket = aws_s3_bucket.state.id

  versioning_configuration {
    status = "Enabled"
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_public_access_block" "state" {
  bucket = aws_s3_bucket.state.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_ownership_controls" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_policy" "state" {
  bucket = aws_s3_bucket.state.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "DenyInsecureTransport"
        Effect    = "Deny"
        Principal = "*"
        Action    = "s3:*"
        Resource  = [aws_s3_bucket.state.arn, "${aws_s3_bucket.state.arn}/*"]
        Condition = {
          Bool = { "aws:SecureTransport" = "false" }
        }
      }
    ]
  })

  # The public access block must exist first: S3 rejects concurrent policy and block updates.
  depends_on = [aws_s3_bucket_public_access_block.state]

  lifecycle {
    prevent_destroy = true
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "state" {
  bucket = aws_s3_bucket.state.id

  rule {
    id     = "expire-old-versions-and-abort-multipart"
    status = "Enabled"

    filter {}

    noncurrent_version_expiration {
      noncurrent_days = 90
    }

    abort_incomplete_multipart_upload {
      days_after_initiation = 7
    }
  }

  # Lifecycle rules need versioning to be configured first.
  depends_on = [aws_s3_bucket_versioning.state]

  lifecycle {
    prevent_destroy = true
  }
}
