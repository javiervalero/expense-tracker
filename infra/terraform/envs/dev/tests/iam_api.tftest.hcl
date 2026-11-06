# Offline tests of the API Lambda's IAM policy (D20 of the Phase 7 plan; the actions come from the SDK inventory of Step 0
# R6: the API calls DynamoDB GetItem, PutItem, UpdateItem, DeleteItem, Query (base table and LSI1) and TransactWriteItems,
# which IAM authorises through the item actions and ConditionCheckItem; S3 DeleteObject, ListObjectsV2 and DeleteObjects
# on both buckets; and it SIGNS presigned POST and GET requests, which S3 checks against the API role when they are
# used, so it needs PutObject on the receipt uploads and GetObject on both buckets. It calls no SQS API).
# The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in
# every test file). The policy is JSON built in the module (no data source), so each test decodes the real document and
# compares it whole with the expected one, written out here from the plan rather than computed with the module's code.
#
# Receipts key layout (backend/src/Receipts/ReceiptObjectKey.php): the original upload is
# `receipts/<userId>/<expenseId>/<receiptId>` in the RECEIPTS bucket, so the object ARN is
# `<receipts bucket ARN>/receipts/*`. (The plan's "receipts/receipts/*" reads bucket/prefix.) Thumbnails are
# `<userId>/<expenseId>/<receiptId>.jpg` in the THUMBNAILS bucket, with no prefix, hence `<thumbnails bucket ARN>/*`.
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
  package_path          = "../../../../build/lambda/backend.zip"
  package_sha256        = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" # sha256 of the empty input
  php_layer_version     = 20
  allowed_origin        = "https://d111111abcdef8.cloudfront.net"
  origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,example-slot-b-9876543210zyxwvutsrqponmlkjihgfe"
}

# The mock returns a random string for the log group ARN; this pins the real form, so the test can check that the
# policy names the group the function really logs to.
override_resource {
  target = module.function.aws_cloudwatch_log_group.this
  values = {
    arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-api"
  }
}

# The provider validates these ARNs (the stage's log destination, the invoke permission's source), and the mock returns
# random strings. Neither matters for the policy; they only let the module apply.
override_resource {
  target = aws_apigatewayv2_api.this
  values = {
    execution_arn = "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5"
  }
}

override_resource {
  target = aws_cloudwatch_log_group.access
  values = {
    arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/apigateway/expense-tracker-dev"
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
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-api"
  }
}

run "policy_is_exactly_the_planned_document" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = jsondecode(output.policy) == {
      Version = "2012-10-17"
      Statement = [
        {
          Sid      = "DynamoDbTable"
          Effect   = "Allow"
          Action   = ["dynamodb:ConditionCheckItem", "dynamodb:DeleteItem", "dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:Query", "dynamodb:UpdateItem"]
          Resource = ["arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"]
        },
        {
          Sid      = "DynamoDbLsi1"
          Effect   = "Allow"
          Action   = ["dynamodb:Query"]
          Resource = ["arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev/index/LSI1"]
        },
        {
          Sid      = "ReceiptsSignUploads"
          Effect   = "Allow"
          Action   = ["s3:PutObject"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipts-abcd1234/receipts/*"]
        },
        {
          Sid      = "ReceiptsReadAndDelete"
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
          Sid      = "ThumbnailsReadAndDelete"
          Effect   = "Allow"
          Action   = ["s3:DeleteObject", "s3:GetObject"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/*"]
        },
        {
          Sid      = "ThumbnailsList"
          Effect   = "Allow"
          Action   = ["s3:ListBucket"]
          Resource = ["arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"]
        },
        {
          Sid      = "OwnLogGroup"
          Effect   = "Allow"
          Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
          Resource = ["arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-api:*"]
        },
      ]
    }
    error_message = "The API policy must be exactly the document planned in D20 (actions per resource, nothing more)."
  }
}

run "the_log_statement_names_the_function_s_own_log_group_only" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = [for s in jsondecode(output.policy).Statement : s.Resource if s.Sid == "OwnLogGroup"][0] == ["${module.function.log_group_arn}:*"]
    error_message = "The logs statement must name the streams of the function's own log group and nothing else (no CreateLogGroup, no wildcard)."
  }

  # The access log group belongs to API Gateway, not to the function.
  assert {
    condition     = !anytrue([for r in flatten([for s in jsondecode(output.policy).Statement : s.Resource]) : strcontains(r, "/aws/apigateway")])
    error_message = "The function must have no permission on the API access log group."
  }
}

run "only_the_receipt_uploads_may_be_put_and_only_under_the_receipts_prefix" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = [
      for s in jsondecode(output.policy).Statement : s.Resource if contains(s.Action, "s3:PutObject")
    ] == [["${var.receipts_bucket_arn}/receipts/*"]]
    error_message = "s3:PutObject must be allowed in exactly one statement, on <receipts bucket>/receipts/* (to sign POST policies)."
  }

  assert {
    condition = length([
      for s in jsondecode(output.policy).Statement : s
      if contains(s.Action, "s3:PutObject") && anytrue([for r in s.Resource : startswith(r, var.thumbnails_bucket_arn)])
    ]) == 0
    error_message = "The API must never be able to put an object into the thumbnails bucket."
  }
}

run "dynamodb_actions_are_the_item_calls_and_query_with_the_lsi1_index" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = toset(flatten([for s in jsondecode(output.policy).Statement : s.Action if startswith(s.Sid, "DynamoDb")])) == toset(["dynamodb:ConditionCheckItem", "dynamodb:DeleteItem", "dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:Query", "dynamodb:UpdateItem"])
    error_message = "DynamoDB actions must be Get/Put/Update/Delete/Query/ConditionCheckItem (transactions are authorised through them)."
  }

  assert {
    condition     = toset(flatten([for s in jsondecode(output.policy).Statement : s.Resource if startswith(s.Sid, "DynamoDb")])) == toset([var.table_arn, "${var.table_arn}/index/LSI1"])
    error_message = "DynamoDB resources must be the table and its LSI1 index, and nothing else."
  }

  # Only Query means anything on an index; the index statement grants nothing else.
  assert {
    condition     = [for s in jsondecode(output.policy).Statement : s.Action if s.Sid == "DynamoDbLsi1"][0] == ["dynamodb:Query"]
    error_message = "The index may only be queried."
  }
}

run "list_bucket_on_receipts_is_limited_to_the_receipts_prefix" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = [for s in jsondecode(output.policy).Statement : s.Condition if s.Sid == "ReceiptsList"][0] == { StringLike = { "s3:prefix" = ["receipts/*"] } }
    error_message = "ListBucket on the receipts bucket must carry the s3:prefix condition receipts/*."
  }

  assert {
    condition     = length([for s in jsondecode(output.policy).Statement : s if contains(s.Action, "s3:ListBucket") && !contains(keys(s), "Condition") && contains(s.Resource, var.receipts_bucket_arn)]) == 0
    error_message = "No unconditional ListBucket may be granted on the receipts bucket."
  }
}

run "the_policy_has_no_wildcard_scan_kms_or_administration" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = length([for s in jsondecode(output.policy).Statement : s if s.Effect != "Allow"]) == 0
    error_message = "The policy must only allow (the denies live in the bucket policy)."
  }

  assert {
    condition     = length([for a in flatten([for s in jsondecode(output.policy).Statement : s.Action]) : a if strcontains(a, "*")]) == 0
    error_message = "No action may contain a wildcard."
  }

  assert {
    condition     = length([for r in flatten([for s in jsondecode(output.policy).Statement : s.Resource]) : r if r == "*" || endswith(r, ":::*")]) == 0
    error_message = "No resource may be a bare wildcard."
  }

  assert {
    condition     = length([for a in flatten([for s in jsondecode(output.policy).Statement : s.Action]) : a if a == "dynamodb:Scan" || startswith(a, "kms:") || startswith(a, "iam:") || startswith(a, "sqs:") || startswith(a, "lambda:") || a == "logs:CreateLogGroup"]) == 0
    error_message = "No Scan, KMS, IAM, SQS, Lambda or CreateLogGroup action may be granted (the API calls none of them)."
  }

  assert {
    condition     = length([for a in flatten([for s in jsondecode(output.policy).Statement : s.Action]) : a if startswith(a, "dynamodb:") && !contains(["dynamodb:ConditionCheckItem", "dynamodb:DeleteItem", "dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:Query", "dynamodb:UpdateItem"], a)]) == 0
    error_message = "No table administration or batch/scan action may be granted."
  }

  assert {
    condition     = length(setsubtract(toset(flatten([for s in jsondecode(output.policy).Statement : s.Action if startswith(s.Sid, "Receipts") || startswith(s.Sid, "Thumbnails")])), toset(["s3:DeleteObject", "s3:GetObject", "s3:ListBucket", "s3:PutObject"]))) == 0
    error_message = "S3 actions are limited to Get/Put/Delete object and ListBucket (no bucket policy or ACL changes)."
  }
}

run "the_policy_follows_the_resources_given" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    table_arn           = "arn:aws:dynamodb:us-east-1:123456789012:table/another"
    receipts_bucket_arn = "arn:aws:s3:::another-receipts"
  }

  assert {
    condition = toset(flatten([for s in jsondecode(output.policy).Statement : s.Resource])) == toset([
      "arn:aws:dynamodb:us-east-1:123456789012:table/another",
      "arn:aws:dynamodb:us-east-1:123456789012:table/another/index/LSI1",
      "arn:aws:s3:::another-receipts/receipts/*",
      "arn:aws:s3:::another-receipts",
      "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/*",
      "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234",
      "arn:aws:logs:us-east-1:123456789012:log-group:/aws/lambda/expense-tracker-dev-api:*",
    ])
    error_message = "Every resource in the policy must be built from the ARNs passed in, not from a fixed name."
  }
}

# The module builds the log ARN from the name (the function module needs the policy before the group exists); this pins
# that the string equals the real group's ARN.
run "the_log_arn_in_the_policy_is_the_real_log_group_arn" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = contains(flatten([for s in jsondecode(output.policy).Statement : s.Resource]), "${module.function.log_group_arn}:*")
    error_message = "The policy must name the ARN of the group the function logs to."
  }
}

run "rejects_a_table_arn_that_is_not_a_table" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    table_arn = "*"
  }

  expect_failures = [var.table_arn]
}

run "rejects_a_table_arn_that_names_an_index" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    table_arn = "arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev/index/LSI1"
  }

  expect_failures = [var.table_arn]
}

run "rejects_a_bucket_arn_that_is_not_a_bucket" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    receipts_bucket_arn = "arn:aws:s3:::*"
  }

  expect_failures = [var.receipts_bucket_arn]
}

run "rejects_a_bucket_arn_that_names_an_object" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    thumbnails_bucket_arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234/thumbnails/*"
  }

  expect_failures = [var.thumbnails_bucket_arn]
}
