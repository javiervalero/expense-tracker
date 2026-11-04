# The receipt worker (Phase 7 plan D8, D13, D20 and D23): the Lambda function that makes thumbnails and updates the
# receipt records, and the event source mapping that feeds it from the main receipts queue.
#
# The role is created by modules/lambda-function apart from its permissions, because the receipts bucket policy names
# the role ARN (output role_arn) while the permissions below name the receipts buckets; see the ORDERING note in
# modules/lambda-function/main.tf. Nothing in this module may make role_arn depend on the buckets.

locals {
  function_name = "${var.name}-worker"

  # Built from the name, not read from the log group resource: the group belongs to the function module, which needs
  # this policy first. A test pins that this string equals the real group's ARN. The ":*" covers its log streams.
  log_group_arn = "arn:aws:logs:${var.aws_region}:${var.aws_account_id}:log-group:/aws/lambda/${local.function_name}"

  # D20 / Step 0 R6. One statement per resource kind, actions sorted, nothing broader than the resource it names:
  #   - DynamoDB: the worker only reads and updates receipt records (GetItem, UpdateItem), on the table, not the index.
  #   - receipts: reads the upload (GetObject; HeadObject is authorised by it) and deletes failed, replaced or obsolete
  #     ones under receipts/. NEVER PutObject (the bucket policy denies it as well). ListBucket under receipts/ turns a
  #     missing key into 404 instead of 403.
  #   - thumbnails: writes and deletes thumbnails (keys have no prefix), and lists the bucket for the same 404 reason.
  #   - SQS: the event source mapping needs these on the main queue to poll and delete; the worker code calls no SQS API.
  #   - logs: streams and events of its own group only. No CreateLogGroup: the group is created by Terraform.
  policy = {
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "DynamoDbReceiptRecords"
        Effect   = "Allow"
        Action   = ["dynamodb:GetItem", "dynamodb:UpdateItem"]
        Resource = [var.table_arn]
      },
      {
        Sid      = "ReceiptsObjects"
        Effect   = "Allow"
        Action   = ["s3:DeleteObject", "s3:GetObject"]
        Resource = ["${var.receipts_bucket_arn}/receipts/*"]
      },
      {
        Sid       = "ReceiptsList"
        Effect    = "Allow"
        Action    = ["s3:ListBucket"]
        Resource  = [var.receipts_bucket_arn]
        Condition = { StringLike = { "s3:prefix" = ["receipts/*"] } }
      },
      {
        Sid      = "ThumbnailsObjects"
        Effect   = "Allow"
        Action   = ["s3:DeleteObject", "s3:PutObject"]
        Resource = ["${var.thumbnails_bucket_arn}/*"]
      },
      {
        Sid      = "ThumbnailsList"
        Effect   = "Allow"
        Action   = ["s3:ListBucket"]
        Resource = [var.thumbnails_bucket_arn]
      },
      {
        Sid      = "ReceiptsQueueConsume"
        Effect   = "Allow"
        Action   = ["sqs:ChangeMessageVisibility", "sqs:DeleteMessage", "sqs:GetQueueAttributes", "sqs:ReceiveMessage"]
        Resource = [var.queue_arn]
      },
      {
        Sid      = "OwnLogGroup"
        Effect   = "Allow"
        Action   = ["logs:CreateLogStream", "logs:PutLogEvents"]
        Resource = ["${local.log_group_arn}:*"]
      },
    ]
  }
}

module "function" {
  source = "../lambda-function"

  function_name = local.function_name
  handler       = "entrypoints/lambda/worker.php"

  # 1024 MB is tied to WorkerRuntime::MEMORY_LIMIT ('768M', backend/src/Worker/WorkerRuntime.php): PHP's own limit stays
  # below the Lambda memory so PHP can fail with a message. Change both together (D13).
  memory_size = 1024
  # The queue's visibility timeout (180 s, modules/receipts) is 6 times this value (D8).
  timeout = 30

  aws_region        = var.aws_region
  php_layer_version = var.php_layer_version
  gd_layer_version  = var.gd_layer_version

  package_path                   = var.package_path
  package_sha256                 = var.package_sha256
  reserved_concurrent_executions = var.reserved_concurrent_executions

  # D23: the worker's keys. No *_ENDPOINT, no APP_ENV, no AWS_* key, and none of the API's origin-verify keys.
  environment_variables = {
    BREF_RUNTIME      = "function"
    DYNAMODB_TABLE    = var.table_name
    RECEIPTS_BUCKET   = var.receipts_bucket
    THUMBNAILS_BUCKET = var.thumbnails_bucket
  }

  policy_json = jsonencode(local.policy)
}

resource "aws_lambda_event_source_mapping" "receipts" {
  event_source_arn = var.queue_arn
  function_name    = module.function.function_arn

  # batch_size 1 is a hard requirement (Phase 5): one message per invocation, so one failure never replays others.
  batch_size                         = 1
  maximum_batching_window_in_seconds = 0
  function_response_types            = ["ReportBatchItemFailures"]

  # 2 is the AWS minimum (Step 0 R2); it bounds how many thumbnails are made at once (Phase 5 L4).
  scaling_config {
    maximum_concurrency = 2
  }

  enabled = true
}
