# Offline tests of modules/receipts (D7 and D8 of the Phase 7 plan): the two receipt buckets, the queue with its
# dead-letter queue, the queue policy and the one bucket notification. The AWS provider is mocked, so nothing here can
# reach an account (scripts/check-tftest-mocks.sh enforces the mock in every test file).
# `command = apply`: under the mock, ARNs are unknown at plan time and are random strings afterwards, so the policies
# are asserted through their JSON structure, and wherever an ARN matters the test compares the policy with the very
# attribute it should have been built from (a policy aimed at some other resource would protect nothing).
# Not assertable here: the ABSENCE of a resource (a second notification, CORS on the thumbnails bucket) and
# `prevent_destroy`; the module has no such resources, and code review covers that.
mock_provider "aws" {}

variables {
  name                = "expense-tracker-dev"
  bucket_suffix       = "abcd1234"
  aws_account_id      = "123456789012" # the AWS documentation example account
  cors_allowed_origin = "https://d111111abcdef8.cloudfront.net"
  worker_role_arn     = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
}

run "names_follow_the_naming_scheme" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_s3_bucket.receipts.bucket == "expense-tracker-dev-receipts-abcd1234"
    error_message = "The receipts bucket must be <name>-receipts-<suffix>."
  }

  assert {
    condition     = aws_s3_bucket.thumbnails.bucket == "expense-tracker-dev-receipt-thumbnails-abcd1234"
    error_message = "The thumbnails bucket must be <name>-receipt-thumbnails-<suffix>."
  }

  assert {
    condition     = aws_sqs_queue.receipts.name == "expense-tracker-dev-receipts"
    error_message = "The queue must be <name>-receipts."
  }

  assert {
    condition     = aws_sqs_queue.dlq.name == "expense-tracker-dev-receipts-dlq"
    error_message = "The dead-letter queue must be <name>-receipts-dlq."
  }
}

run "buckets_are_destroyable_for_dev" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_s3_bucket.receipts.force_destroy == true && aws_s3_bucket.thumbnails.force_destroy == true
    error_message = "Both buckets must set force_destroy (owner decision 5)."
  }
}

run "versioning_is_off_on_both_buckets" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = one(aws_s3_bucket_versioning.receipts.versioning_configuration).status == "Disabled"
    error_message = "The receipts bucket must not be versioned."
  }

  assert {
    condition     = one(aws_s3_bucket_versioning.thumbnails.versioning_configuration).status == "Disabled"
    error_message = "The thumbnails bucket must not be versioned."
  }
}

run "encryption_is_sse_s3_on_both_buckets" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = one(one(aws_s3_bucket_server_side_encryption_configuration.receipts.rule).apply_server_side_encryption_by_default).sse_algorithm == "AES256"
    error_message = "The receipts bucket must use AES256 (SSE-S3)."
  }

  assert {
    condition     = one(one(aws_s3_bucket_server_side_encryption_configuration.thumbnails.rule).apply_server_side_encryption_by_default).sse_algorithm == "AES256"
    error_message = "The thumbnails bucket must use AES256 (SSE-S3)."
  }
}

run "public_access_is_fully_blocked_on_both_buckets" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition = alltrue([
      aws_s3_bucket_public_access_block.receipts.block_public_acls,
      aws_s3_bucket_public_access_block.receipts.block_public_policy,
      aws_s3_bucket_public_access_block.receipts.ignore_public_acls,
      aws_s3_bucket_public_access_block.receipts.restrict_public_buckets,
    ])
    error_message = "All four public access block settings must be true on the receipts bucket."
  }

  assert {
    condition = alltrue([
      aws_s3_bucket_public_access_block.thumbnails.block_public_acls,
      aws_s3_bucket_public_access_block.thumbnails.block_public_policy,
      aws_s3_bucket_public_access_block.thumbnails.ignore_public_acls,
      aws_s3_bucket_public_access_block.thumbnails.restrict_public_buckets,
    ])
    error_message = "All four public access block settings must be true on the thumbnails bucket."
  }
}

run "ownership_is_bucket_owner_enforced_on_both_buckets" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = one(aws_s3_bucket_ownership_controls.receipts.rule).object_ownership == "BucketOwnerEnforced"
    error_message = "The receipts bucket must enforce bucket-owner ownership."
  }

  assert {
    condition     = one(aws_s3_bucket_ownership_controls.thumbnails.rule).object_ownership == "BucketOwnerEnforced"
    error_message = "The thumbnails bucket must enforce bucket-owner ownership."
  }
}

run "incomplete_multipart_uploads_are_aborted_after_one_day" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = length(aws_s3_bucket_lifecycle_configuration.receipts.rule) == 1
    error_message = "The receipts bucket must have exactly one lifecycle rule."
  }

  assert {
    condition     = one(aws_s3_bucket_lifecycle_configuration.receipts.rule).status == "Enabled"
    error_message = "The receipts lifecycle rule must be Enabled."
  }

  assert {
    condition     = one(one(aws_s3_bucket_lifecycle_configuration.receipts.rule).abort_incomplete_multipart_upload).days_after_initiation == 1
    error_message = "Incomplete multipart uploads must be aborted after 1 day on the receipts bucket."
  }

  assert {
    condition     = length(aws_s3_bucket_lifecycle_configuration.thumbnails.rule) == 1
    error_message = "The thumbnails bucket must have exactly one lifecycle rule."
  }

  assert {
    condition     = one(aws_s3_bucket_lifecycle_configuration.thumbnails.rule).status == "Enabled"
    error_message = "The thumbnails lifecycle rule must be Enabled."
  }

  assert {
    condition     = one(one(aws_s3_bucket_lifecycle_configuration.thumbnails.rule).abort_incomplete_multipart_upload).days_after_initiation == 1
    error_message = "Incomplete multipart uploads must be aborted after 1 day on the thumbnails bucket."
  }
}

run "both_bucket_policies_deny_insecure_transport" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition = [for s in jsondecode(aws_s3_bucket_policy.receipts.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0] == {
      Sid       = "DenyInsecureTransport"
      Effect    = "Deny"
      Principal = "*"
      Action    = "s3:*"
      Resource  = [aws_s3_bucket.receipts.arn, "${aws_s3_bucket.receipts.arn}/*"]
      Condition = { Bool = { "aws:SecureTransport" = "false" } }
    }
    error_message = "The receipts policy must deny s3:* to everyone when aws:SecureTransport is false, on this bucket and its objects."
  }

  assert {
    condition     = length(jsondecode(aws_s3_bucket_policy.thumbnails.policy).Statement) == 1
    error_message = "The thumbnails policy must hold exactly the TLS deny."
  }

  assert {
    condition = jsondecode(aws_s3_bucket_policy.thumbnails.policy).Statement[0] == {
      Sid       = "DenyInsecureTransport"
      Effect    = "Deny"
      Principal = "*"
      Action    = "s3:*"
      Resource  = [aws_s3_bucket.thumbnails.arn, "${aws_s3_bucket.thumbnails.arn}/*"]
      Condition = { Bool = { "aws:SecureTransport" = "false" } }
    }
    error_message = "The thumbnails policy must deny s3:* to everyone when aws:SecureTransport is false, on this bucket and its objects."
  }
}

run "receipts_policy_denies_put_object_to_the_worker_role" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = length(jsondecode(aws_s3_bucket_policy.receipts.policy).Statement) == 2
    error_message = "The receipts policy must hold exactly the TLS deny and the worker deny."
  }

  assert {
    condition = [for s in jsondecode(aws_s3_bucket_policy.receipts.policy).Statement : s if s.Sid == "DenyWorkerPutObject"][0] == {
      Sid       = "DenyWorkerPutObject"
      Effect    = "Deny"
      Principal = { AWS = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker" }
      Action    = "s3:PutObject"
      Resource  = "${aws_s3_bucket.receipts.arn}/*"
    }
    error_message = "The receipts policy must Deny s3:PutObject on the bucket's objects to the worker role ARN."
  }
}

run "cors_is_its_own_resource_on_receipts_with_the_given_origin" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_s3_bucket_cors_configuration.receipts.bucket == aws_s3_bucket.receipts.id
    error_message = "The CORS rule must be on the receipts bucket."
  }

  assert {
    condition     = length(aws_s3_bucket_cors_configuration.receipts.cors_rule) == 1
    error_message = "There must be exactly one CORS rule."
  }

  assert {
    condition     = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).allowed_origins == toset(["https://d111111abcdef8.cloudfront.net"])
    error_message = "The only allowed origin must be the given one."
  }

  assert {
    condition     = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).allowed_methods == toset(["POST", "GET", "HEAD"])
    error_message = "The allowed methods must be POST, GET and HEAD."
  }

  assert {
    condition     = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).allowed_headers == toset(["*"])
    error_message = "The allowed headers must be \"*\"."
  }

  assert {
    condition     = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).expose_headers == toset(["ETag"])
    error_message = "ETag must be the only exposed header."
  }

  assert {
    condition     = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).max_age_seconds == 600
    error_message = "max_age_seconds must be 600."
  }
}

run "queue_has_sse_and_the_planned_timings" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_sqs_queue.receipts.sqs_managed_sse_enabled == true
    error_message = "The queue must use SSE-SQS."
  }

  assert {
    condition     = aws_sqs_queue.receipts.visibility_timeout_seconds == 180
    error_message = "The visibility timeout must be 180 s (6 times the worker timeout of 30 s)."
  }

  assert {
    condition     = aws_sqs_queue.receipts.message_retention_seconds == 345600
    error_message = "The queue retention must be 4 days."
  }

  assert {
    condition     = aws_sqs_queue.receipts.fifo_queue == false
    error_message = "The queue must be standard: S3 cannot notify a FIFO queue."
  }
}

run "dlq_has_sse_and_a_longer_retention_than_the_queue" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_sqs_queue.dlq.sqs_managed_sse_enabled == true
    error_message = "The dead-letter queue must use SSE-SQS."
  }

  assert {
    condition     = aws_sqs_queue.dlq.message_retention_seconds == 1209600
    error_message = "The dead-letter queue retention must be 14 days."
  }

  assert {
    condition     = aws_sqs_queue.dlq.message_retention_seconds > aws_sqs_queue.receipts.message_retention_seconds
    error_message = "Messages must outlive their time in the source queue, so the DLQ retention must be longer."
  }
}

run "redrive_sends_to_the_dlq_after_three_receives" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = jsondecode(aws_sqs_queue_redrive_policy.receipts.redrive_policy).maxReceiveCount == 3
    error_message = "maxReceiveCount must be 3."
  }

  assert {
    condition     = jsondecode(aws_sqs_queue_redrive_policy.receipts.redrive_policy).deadLetterTargetArn == aws_sqs_queue.dlq.arn
    error_message = "The redrive target must be the dead-letter queue."
  }

  assert {
    condition     = aws_sqs_queue_redrive_policy.receipts.queue_url == aws_sqs_queue.receipts.id
    error_message = "The redrive policy must be attached to the main queue."
  }
}

run "dlq_accepts_redrive_only_from_the_main_queue" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = jsondecode(aws_sqs_queue_redrive_allow_policy.dlq.redrive_allow_policy).redrivePermission == "byQueue"
    error_message = "The DLQ must allow redrive byQueue."
  }

  assert {
    condition     = jsondecode(aws_sqs_queue_redrive_allow_policy.dlq.redrive_allow_policy).sourceQueueArns == [aws_sqs_queue.receipts.arn]
    error_message = "The only allowed source queue must be the main queue."
  }

  assert {
    condition     = aws_sqs_queue_redrive_allow_policy.dlq.queue_url == aws_sqs_queue.dlq.id
    error_message = "The redrive allow policy must be attached to the DLQ."
  }
}

run "queue_policy_lets_only_this_bucket_send" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_sqs_queue_policy.receipts.queue_url == aws_sqs_queue.receipts.id
    error_message = "The policy must be attached to the main queue."
  }

  assert {
    condition     = length(jsondecode(aws_sqs_queue_policy.receipts.policy).Statement) == 2
    error_message = "The queue policy must hold exactly the S3 allow and the TLS deny."
  }

  assert {
    condition = [for s in jsondecode(aws_sqs_queue_policy.receipts.policy).Statement : s if s.Sid == "AllowReceiptsBucketSendMessage"][0] == {
      Sid       = "AllowReceiptsBucketSendMessage"
      Effect    = "Allow"
      Principal = { Service = "s3.amazonaws.com" }
      Action    = "sqs:SendMessage"
      Resource  = aws_sqs_queue.receipts.arn
      Condition = {
        ArnEquals    = { "aws:SourceArn" = aws_s3_bucket.receipts.arn }
        StringEquals = { "aws:SourceAccount" = "123456789012" }
      }
    }
    error_message = "S3 may sqs:SendMessage to the queue only for the receipts bucket ARN and the given account."
  }

  assert {
    condition = [for s in jsondecode(aws_sqs_queue_policy.receipts.policy).Statement : s if s.Sid == "DenyInsecureTransport"][0] == {
      Sid       = "DenyInsecureTransport"
      Effect    = "Deny"
      Principal = "*"
      Action    = "sqs:*"
      Resource  = aws_sqs_queue.receipts.arn
      Condition = { Bool = { "aws:SecureTransport" = "false" } }
    }
    error_message = "The queue policy must deny sqs:* over plain HTTP."
  }
}

run "one_notification_on_receipts_only_with_the_receipts_prefix" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = aws_s3_bucket_notification.receipts.bucket == aws_s3_bucket.receipts.id
    error_message = "The notification must be on the receipts bucket."
  }

  assert {
    condition     = aws_s3_bucket_notification.receipts.eventbridge == false
    error_message = "EventBridge delivery must be off."
  }

  assert {
    condition     = length(aws_s3_bucket_notification.receipts.queue) == 1
    error_message = "There must be exactly one queue notification."
  }

  assert {
    condition     = length(aws_s3_bucket_notification.receipts.topic) == 0 && length(aws_s3_bucket_notification.receipts.lambda_function) == 0
    error_message = "There must be no topic or direct Lambda notification."
  }

  assert {
    condition     = one(aws_s3_bucket_notification.receipts.queue).queue_arn == aws_sqs_queue.receipts.arn
    error_message = "The notification must target the main queue."
  }

  assert {
    condition     = one(aws_s3_bucket_notification.receipts.queue).events == toset(["s3:ObjectCreated:*"])
    error_message = "The only event must be s3:ObjectCreated:*."
  }

  assert {
    condition     = one(aws_s3_bucket_notification.receipts.queue).filter_prefix == "receipts/"
    error_message = "The filter prefix must be receipts/."
  }
}

run "outputs_expose_names_arns_and_urls" {
  command = apply

  module {
    source = "../../modules/receipts"
  }

  assert {
    condition     = output.receipts_bucket == "expense-tracker-dev-receipts-abcd1234" && output.receipts_bucket_arn == aws_s3_bucket.receipts.arn
    error_message = "receipts_bucket and receipts_bucket_arn must describe the receipts bucket."
  }

  assert {
    condition     = output.thumbnails_bucket == "expense-tracker-dev-receipt-thumbnails-abcd1234" && output.thumbnails_bucket_arn == aws_s3_bucket.thumbnails.arn
    error_message = "thumbnails_bucket and thumbnails_bucket_arn must describe the thumbnails bucket."
  }

  assert {
    condition     = output.queue_arn == aws_sqs_queue.receipts.arn && output.queue_url == aws_sqs_queue.receipts.url
    error_message = "queue_arn and queue_url must describe the main queue."
  }

  assert {
    condition     = output.dlq_arn == aws_sqs_queue.dlq.arn && output.dlq_url == aws_sqs_queue.dlq.url && output.dlq_name == "expense-tracker-dev-receipts-dlq"
    error_message = "dlq_arn, dlq_url and dlq_name must describe the dead-letter queue."
  }
}

run "rejects_a_bad_bucket_suffix" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    bucket_suffix = "ABCD-123"
  }

  expect_failures = [var.bucket_suffix]
}

run "rejects_a_name_that_makes_a_bucket_name_too_long" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    name = "expense-tracker-dev-with-a-very-long-environment-name-x"
  }

  expect_failures = [var.name]
}

run "rejects_a_wildcard_cors_origin" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    cors_allowed_origin = "*"
  }

  expect_failures = [var.cors_allowed_origin]
}

run "rejects_a_cors_origin_with_a_trailing_slash" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    cors_allowed_origin = "https://d111111abcdef8.cloudfront.net/"
  }

  expect_failures = [var.cors_allowed_origin]
}

run "rejects_a_plain_http_cors_origin" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    cors_allowed_origin = "http://d111111abcdef8.cloudfront.net"
  }

  expect_failures = [var.cors_allowed_origin]
}

run "rejects_a_worker_role_that_is_not_a_role_arn" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    worker_role_arn = "*"
  }

  expect_failures = [var.worker_role_arn]
}

run "rejects_a_bad_account_id" {
  command = plan

  module {
    source = "../../modules/receipts"
  }

  variables {
    aws_account_id = "12345"
  }

  expect_failures = [var.aws_account_id]
}
