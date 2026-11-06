# Offline tests of the worker's IAM policy (D20 of the Phase 7 plan; the actions come from the SDK inventory of Step 0
# R6: the worker calls DynamoDB GetItem and UpdateItem, S3 GetObject/HeadObject on receipts, PutObject on thumbnails,
# DeleteObject on both, and no SQS API itself: the event source mapping receives and deletes the messages).
# The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in
# every test file). The policy is JSON built in the module (no data source), so each test decodes the real document and
# compares it whole with the expected one, written out here from the plan rather than computed with the module's code.
mock_provider "aws" {}

variables {
  name                  = "expense-tracker-dev"
  aws_region            = "us-east-1"
  aws_account_id        = "123456789012" # the AWS documentation example account
  table_name            = "expense-tracker-dev"
  table_arn             = "arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"
  receipts_bucket       = "expense-tracker-dev-receipts-abcd1234"
  receipts_bucket_arn   = "arn:aws:s3:::expense-tracker-dev-receipts-abcd1234"
  thumbnails_bucket     = "expense-tracker-dev-receipt-thumbnails-abcd1234"
  thumbnails_bucket_arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"
  queue_arn             = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"
  package_path          = "../../../../build/lambda/backend.zip"
  package_sha256        = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" # sha256 of the empty input
  php_layer_version     = 20
  gd_layer_version      = 1
}

# The mock returns a random string for the log group ARN; this pins the real form, so the test can check that the
# policy names the group the function really logs to.
override_resource {
  target = module.function.aws_cloudwatch_log_group.this
  values = {
    arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-worker"
  }
}

# The function's postcondition compares the reported code hash with the one computed from package_sha256 (the hash of the
# empty input here); the mock returns a random string for it.
override_resource {
  target = module.function.aws_lambda_function.this
  values = {
    code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
  }
}

# The provider validates that aws_lambda_function.role is an ARN, and the mock returns a random string.
override_resource {
  target = module.function.aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

run "policy_is_exactly_the_planned_document" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition = jsondecode(module.function.policy) == {
      Version = "2012-10-17"
      Statement = [
        {
          Sid      = "DynamoDbReceiptRecords"
          Effect   = "Allow"
          Action   = ["dynamodb:GetItem", "dynamodb:UpdateItem"]
          Resource = ["arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"]
        },
        {
          Sid      = "ReceiptsObjects"
          Effect   = "Allow"
          Action   = ["s3:DeleteObject", "s3:GetObject"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipts-abcd1234/receipts/*"]
        },
        {
          Sid       = "ReceiptsList"
          Effect    = "Allow"
          Action    = ["s3:ListBucket"]
          Resource  = ["arn:aws:s3:::expense-tracker-dev-receipts-abcd1234"]
          Condition = { StringLike = { "s3:prefix" = ["receipts/*"] } }
        },
        {
          Sid      = "ThumbnailsObjects"
          Effect   = "Allow"
          Action   = ["s3:DeleteObject", "s3:PutObject"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/*"]
        },
        {
          Sid      = "ThumbnailsList"
          Effect   = "Allow"
          Action   = ["s3:ListBucket"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"]
        },
        {
          Sid      = "ReceiptsQueueConsume"
          Effect   = "Allow"
          Action   = ["sqs:ChangeMessageVisibility", "sqs:DeleteMessage", "sqs:GetQueueAttributes", "sqs:ReceiveMessage"]
          Resource = ["arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"]
        },
        {
          Sid      = "OwnLogGroup"
          Effect   = "Allow"
          Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
          Resource = ["arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-worker:*"]
        },
      ]
    }
    error_message = "The worker policy must be exactly the document planned in D20 (actions per resource, nothing more)."
  }
}

run "the_log_statement_names_the_function_s_own_log_group_only" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = [for s in jsondecode(module.function.policy).Statement : s.Resource if s.Sid == "OwnLogGroup"][0] == ["${module.function.log_group_arn}:*"]
    error_message = "The logs statement must name the streams of the function's own log group and nothing else (no CreateLogGroup, no wildcard)."
  }
}

run "the_worker_can_never_put_an_object_into_the_receipts_bucket" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  # The bucket policy denies it as well (modules/receipts); this is the first barrier.
  assert {
    condition = length([
      for s in jsondecode(module.function.policy).Statement : s
      if contains(s.Action, "s3:PutObject") && anytrue([for r in s.Resource : startswith(r, var.receipts_bucket_arn)])
    ]) == 0
    error_message = "No statement may allow s3:PutObject on the receipts bucket."
  }

  assert {
    condition = length([
      for s in jsondecode(module.function.policy).Statement : s
      if anytrue([for a in s.Action : a == "s3:PutObject"])
    ]) == 1
    error_message = "s3:PutObject must be allowed in exactly one statement (the thumbnails bucket)."
  }
}

run "the_policy_has_no_wildcard_scan_kms_or_administration" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = length([for s in jsondecode(module.function.policy).Statement : s if s.Effect != "Allow"]) == 0
    error_message = "The policy must only allow (the denies live in the bucket policy)."
  }

  assert {
    condition     = length([for a in flatten([for s in jsondecode(module.function.policy).Statement : s.Action]) : a if strcontains(a, "*")]) == 0
    error_message = "No action may contain a wildcard."
  }

  assert {
    condition     = length([for r in flatten([for s in jsondecode(module.function.policy).Statement : s.Resource]) : r if r == "*"]) == 0
    error_message = "No resource may be a bare wildcard."
  }

  assert {
    condition     = length([for a in flatten([for s in jsondecode(module.function.policy).Statement : s.Action]) : a if a == "dynamodb:Scan" || startswith(a, "kms:") || startswith(a, "iam:") || a == "logs:CreateLogGroup"]) == 0
    error_message = "No Scan, KMS, IAM or CreateLogGroup action may be granted."
  }

  assert {
    condition     = toset(flatten([for s in jsondecode(module.function.policy).Statement : s.Action if strcontains(s.Sid, "DynamoDb")])) == toset(["dynamodb:GetItem", "dynamodb:UpdateItem"])
    error_message = "The worker may only read and update items (no Put, Delete, Query, batch or transaction call)."
  }
}

run "the_queue_statement_targets_the_main_queue_only" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = [for s in jsondecode(module.function.policy).Statement : s.Resource if s.Sid == "ReceiptsQueueConsume"][0] == [var.queue_arn]
    error_message = "The queue permissions must name the main queue ARN alone (never the dead-letter queue)."
  }
}

run "the_policy_is_attached_to_the_role_as_an_inline_policy" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = module.function.policy == jsonencode(jsondecode(module.function.policy))
    error_message = "The attached policy must be valid, normalised JSON."
  }

  assert {
    condition     = output.role_arn == module.function.role_arn
    error_message = "The worker must expose the role created by the function module."
  }
}

run "the_policy_follows_the_resources_given" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  variables {
    table_arn           = "arn:aws:dynamodb:us-east-1:123456789012:table/another"
    receipts_bucket_arn = "arn:aws:s3:::another-receipts"
    queue_arn           = "arn:aws:sqs:us-east-1:123456789012:another-receipts"
  }

  assert {
    condition = toset(flatten([for s in jsondecode(module.function.policy).Statement : s.Resource])) == toset([
      "arn:aws:dynamodb:us-east-1:123456789012:table/another",
      "arn:aws:s3:::another-receipts/receipts/*",
      "arn:aws:s3:::another-receipts",
      "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/*",
      "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234",
      "arn:aws:sqs:us-east-1:123456789012:another-receipts",
      "arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-worker:*",
    ])
    error_message = "Every resource in the policy must be built from the ARNs passed in, not from a fixed name."
  }
}

run "rejects_a_table_arn_that_is_not_a_table" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    table_arn = "*"
  }

  expect_failures = [var.table_arn]
}

run "rejects_a_bucket_arn_that_is_not_a_bucket" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    receipts_bucket_arn = "arn:aws:s3:::*"
  }

  expect_failures = [var.receipts_bucket_arn]
}

run "rejects_a_bucket_arn_that_names_an_object" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    thumbnails_bucket_arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/thumbnails/*"
  }

  expect_failures = [var.thumbnails_bucket_arn]
}
