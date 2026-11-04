# The receipt storage pipeline (Phase 7 plan D7 and D8): the receipts bucket (uploads from the browser), the thumbnails
# bucket (written by the worker), the queue S3 notifies about new uploads, and its dead-letter queue.
#   - Neither bucket is versioned (a receipt is replaced or deleted, never restored), both are encrypted with SSE-S3,
#     private, ACL-free and TLS-only; incomplete multipart uploads are aborted after 1 day.
#   - Only the receipts bucket notifies, and only for `receipts/` uploads. The thumbnails bucket has no notification,
#     otherwise the worker's own output would feed the queue.
#   - The CORS rule is a separate resource and the origin is a variable: the distribution does not exist when this
#     module is built (D25). The worker role is a variable for the same reason; the bucket policy denies it PutObject
#     as a second barrier behind its IAM policy.
#   - The redrive policies are separate resources: each queue names the other, so inline attributes would be a cycle.

locals {
  receipts_bucket_name   = "${var.name}-receipts-${var.bucket_suffix}"
  thumbnails_bucket_name = "${var.name}-receipt-thumbnails-${var.bucket_suffix}"

  # Both buckets and the queue refuse plain HTTP; the same statement is built for each resource list.
  deny_insecure_transport = {
    Sid       = "DenyInsecureTransport"
    Effect    = "Deny"
    Principal = "*"
    Condition = { Bool = { "aws:SecureTransport" = "false" } }
  }

  seconds_per_day = 86400

  # The worker times out after 30 s, and Lambda requires the visibility timeout to be at least that; six times it
  # leaves room for the retries of one invocation (D8).
  visibility_timeout_seconds = 180
  max_receive_count          = 3
}

# ---------------------------------------------------------------- receipts bucket

resource "aws_s3_bucket" "receipts" {
  bucket        = local.receipts_bucket_name
  force_destroy = true # dev: Phase 8 destroys the environment, objects included (owner decision 5)
}

resource "aws_s3_bucket_versioning" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  versioning_configuration {
    status = "Disabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  rule {
    id     = "abort-incomplete-multipart-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

resource "aws_s3_bucket_policy" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      merge(local.deny_insecure_transport, {
        Action   = "s3:*"
        Resource = [aws_s3_bucket.receipts.arn, "${aws_s3_bucket.receipts.arn}/*"]
      }),
      {
        Sid       = "DenyWorkerPutObject"
        Effect    = "Deny"
        Principal = { AWS = var.worker_role_arn }
        Action    = "s3:PutObject"
        Resource  = "${aws_s3_bucket.receipts.arn}/*"
      }
    ]
  })

  # S3 rejects a policy update that runs concurrently with a public access block update.
  depends_on = [aws_s3_bucket_public_access_block.receipts]
}

resource "aws_s3_bucket_cors_configuration" "receipts" {
  bucket = aws_s3_bucket.receipts.id

  cors_rule {
    allowed_origins = [var.cors_allowed_origin]
    allowed_methods = ["POST", "GET", "HEAD"]
    allowed_headers = ["*"]
    expose_headers  = ["ETag"]
    max_age_seconds = 600
  }
}

# ---------------------------------------------------------------- thumbnails bucket

resource "aws_s3_bucket" "thumbnails" {
  bucket        = local.thumbnails_bucket_name
  force_destroy = true
}

resource "aws_s3_bucket_versioning" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  versioning_configuration {
    status = "Disabled"
  }
}

resource "aws_s3_bucket_server_side_encryption_configuration" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  rule {
    apply_server_side_encryption_by_default {
      sse_algorithm = "AES256"
    }
  }
}

resource "aws_s3_bucket_public_access_block" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  block_public_acls       = true
  block_public_policy     = true
  ignore_public_acls      = true
  restrict_public_buckets = true
}

resource "aws_s3_bucket_ownership_controls" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  rule {
    object_ownership = "BucketOwnerEnforced"
  }
}

resource "aws_s3_bucket_lifecycle_configuration" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  rule {
    id     = "abort-incomplete-multipart-uploads"
    status = "Enabled"

    filter {}

    abort_incomplete_multipart_upload {
      days_after_initiation = 1
    }
  }
}

resource "aws_s3_bucket_policy" "thumbnails" {
  bucket = aws_s3_bucket.thumbnails.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      merge(local.deny_insecure_transport, {
        Action   = "s3:*"
        Resource = [aws_s3_bucket.thumbnails.arn, "${aws_s3_bucket.thumbnails.arn}/*"]
      })
    ]
  })

  depends_on = [aws_s3_bucket_public_access_block.thumbnails]
}

# ---------------------------------------------------------------- queues

resource "aws_sqs_queue" "dlq" {
  name                      = "${var.name}-receipts-dlq"
  message_retention_seconds = 14 * local.seconds_per_day
  sqs_managed_sse_enabled   = true
}

resource "aws_sqs_queue" "receipts" {
  name                       = "${var.name}-receipts"
  fifo_queue                 = false # S3 cannot notify a FIFO queue (Step 0 R9); explicit so the test can pin it
  visibility_timeout_seconds = local.visibility_timeout_seconds
  message_retention_seconds  = 4 * local.seconds_per_day
  sqs_managed_sse_enabled    = true
}

resource "aws_sqs_queue_redrive_policy" "receipts" {
  queue_url = aws_sqs_queue.receipts.id

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dlq.arn
    maxReceiveCount     = local.max_receive_count
  })
}

resource "aws_sqs_queue_redrive_allow_policy" "dlq" {
  queue_url = aws_sqs_queue.dlq.id

  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue"
    sourceQueueArns   = [aws_sqs_queue.receipts.arn]
  })
}

# The dead-letter queue is TLS-only as well. Nothing sends to it with a policy of its own: the main queue's redrive
# does not need one, so this policy holds the deny alone.
resource "aws_sqs_queue_policy" "dlq" {
  queue_url = aws_sqs_queue.dlq.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      merge(local.deny_insecure_transport, {
        Action   = "sqs:*"
        Resource = aws_sqs_queue.dlq.arn
      })
    ]
  })
}

resource "aws_sqs_queue_policy" "receipts" {
  queue_url = aws_sqs_queue.receipts.id

  policy = jsonencode({
    Version = "2012-10-17"
    Statement = [
      {
        Sid       = "AllowReceiptsBucketSendMessage"
        Effect    = "Allow"
        Principal = { Service = "s3.amazonaws.com" }
        Action    = "sqs:SendMessage"
        Resource  = aws_sqs_queue.receipts.arn
        Condition = {
          ArnEquals    = { "aws:SourceArn" = aws_s3_bucket.receipts.arn }
          StringEquals = { "aws:SourceAccount" = var.aws_account_id }
        }
      },
      merge(local.deny_insecure_transport, {
        Action   = "sqs:*"
        Resource = aws_sqs_queue.receipts.arn
      })
    ]
  })
}

# ---------------------------------------------------------------- the one notification

resource "aws_s3_bucket_notification" "receipts" {
  bucket      = aws_s3_bucket.receipts.id
  eventbridge = false

  queue {
    queue_arn     = aws_sqs_queue.receipts.arn
    events        = ["s3:ObjectCreated:*"]
    filter_prefix = "receipts/"
  }

  # S3 validates the destination when the notification is created, so the queue must already accept S3.
  depends_on = [aws_sqs_queue_policy.receipts]
}
