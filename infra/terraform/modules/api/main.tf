# The HTTP API and the API Lambda (Phase 7 plan D12, D14, D20, D21, D23 and D25).
#
# Request path: CloudFront (WP8) -> this HTTP API ($default stage) -> one AWS_PROXY integration -> the PHP-FPM Lambda.
# PHP does everything: routing, authentication, the origin check (a direct call to the execute-api endpoint gets 403
# from PHP), CORS decisions. The gateway only adds throttles, so the explicit routes below exist to carry a throttle
# and nothing else; they all go to the same integration, and `$default` catches every other request.
#
# ORDERING (D25, Phase 2 R15). APP_ORIGINS needs the CloudFront domain, and the distribution needs the API endpoint. There
# is no cycle, because the endpoint (output api_endpoint) belongs to aws_apigatewayv2_api.this and depends on nothing
# else here. The order is API -> distribution -> Lambda environment -> integration -> routes. Never make api_endpoint (or
# any output the edge module reads) depend on the function, the integration or the routes, and never put a module-level
# depends_on on this module. The wiring fixture in envs/dev/tests pins this.

locals {
  function_name = "${var.name}-api"

  # Built from the name, not read from the log group resource: the group belongs to the function module, which needs this
  # policy first. A test pins that this string equals the real group's ARN. The ":*" is added in the policy (its streams).
  log_group_arn = "arn:aws:logs:${var.aws_region}:${var.aws_account_id}:log-group:/aws/lambda/${local.function_name}"

  # The timeout chain (D12): Lambda < integration < CloudFront origin read timeout, each layer failing after the one
  # behind it. HTTP API integrations cannot wait longer than 30 s (Step 0 R2). The edge module (WP8) must take
  # cloudfront_origin_read_timeout_seconds from this module's output, and a test pins the chain.
  lambda_timeout_seconds                 = 25
  integration_timeout_milliseconds       = 29000
  cloudfront_origin_read_timeout_seconds = 30

  # Stage defaults (owner decision 7): requests per second, and burst. Shared by every caller.
  default_throttle = { rate = 10, burst = 20 }

  # Routes that exist only to carry a throttle (D14), each lower than the default in both numbers. Reports and the
  # upload policy are the expensive ones (Phase 6, Phase 5); login and register burn Argon2id CPU; the list is the
  # common read. Writes stay under the stage default (each is O(1), Phase 3 B22).
  throttled_routes = {
    "GET /api/v1/reports/monthly"               = { rate = 1, burst = 2 }
    "POST /api/v1/expenses/{id}/receipt/upload" = { rate = 1, burst = 3 }
    "GET /api/v1/expenses"                      = { rate = 5, burst = 10 }
    "POST /api/v1/auth/login"                   = { rate = 2, burst = 5 }
    "POST /api/v1/auth/register"                = { rate = 1, burst = 3 }
  }

  # `$default` first in meaning, then the throttled ones: one for_each so every route is built the same way.
  route_keys = toset(concat(["$default"], keys(local.throttled_routes)))

  # D21. No query string variable (Phase 3 B23: q and cursors can hold personal data); $context.path is the path alone.
  access_log_format = jsonencode({
    requestId               = "$context.requestId"
    requestTime             = "$context.requestTime"
    httpMethod              = "$context.httpMethod"
    routeKey                = "$context.routeKey"
    path                    = "$context.path"
    status                  = "$context.status"
    responseLength          = "$context.responseLength"
    integrationLatency      = "$context.integrationLatency"
    integrationErrorMessage = "$context.integrationErrorMessage"
  })

  # D20 / Step 0 R6. One statement per resource kind, actions sorted, nothing broader than the resource it names:
  #   - DynamoDB: the item calls and Query on the table (TransactWriteItems is authorised through Put/Update/Delete and
  #     ConditionCheckItem), and Query alone on the LSI1 index (nothing else means anything on an index). No Scan, no
  #     batch call, no table administration.
  #   - receipts: the original uploads live under the key prefix receipts/ (ReceiptObjectKey: receipts/<user>/<expense>/
  #     <receipt>), the same prefix the worker policy and the bucket notification use. PutObject is only there so the SDK
  #     can sign presigned POST policies (S3 checks the signer's permission when the browser uploads); GetObject is for
  #     presigned GET URLs, DeleteObject for DeleteObject/DeleteObjects when an expense or a receipt goes away.
  #     ListBucket (ListObjectsV2 before DeleteObjects) is limited to the receipts/ prefix.
  #   - thumbnails: keys have no prefix (<user>/<expense>/<receipt>.jpg). Read and delete only; the worker writes them.
  #   - logs: streams and events of its own group only. No CreateLogGroup: the group is created by Terraform.
  # The API calls no SQS API and no other service.
  policy = {
    Version = "2012-10-17"
    Statement = [
      {
        Sid      = "DynamoDbTable"
        Effect   = "Allow"
        Action   = ["dynamodb:ConditionCheckItem", "dynamodb:DeleteItem", "dynamodb:GetItem", "dynamodb:PutItem", "dynamodb:Query", "dynamodb:UpdateItem"]
        Resource = [var.table_arn]
      },
      {
        Sid      = "DynamoDbLsi1"
        Effect   = "Allow"
        Action   = ["dynamodb:Query"]
        Resource = ["${var.table_arn}/index/LSI1"]
      },
      {
        Sid      = "ReceiptsSignUploads"
        Effect   = "Allow"
        Action   = ["s3:PutObject"]
        Resource = ["${var.receipts_bucket_arn}/receipts/*"]
      },
      {
        Sid      = "ReceiptsReadAndDelete"
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
        Sid      = "ThumbnailsReadAndDelete"
        Effect   = "Allow"
        Action   = ["s3:DeleteObject", "s3:GetObject"]
        Resource = ["${var.thumbnails_bucket_arn}/*"]
      },
      {
        Sid      = "ThumbnailsList"
        Effect   = "Allow"
        Action   = ["s3:ListBucket"]
        Resource = [var.thumbnails_bucket_arn]
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

# ---------------------------------------------------------------- the HTTP API

resource "aws_apigatewayv2_api" "this" {
  name          = var.name
  protocol_type = "HTTP"

  # No custom domain, so the execute-api endpoint stays on; PHP's origin check rejects direct calls (D14).
  disable_execute_api_endpoint = false

  # No cors_configuration: PHP makes every CORS decision, and a gateway CORS block would answer before PHP (D14).
}

# ---------------------------------------------------------------- the API Lambda

module "function" {
  source = "../lambda-function"

  function_name = local.function_name
  handler       = "public/index.php" # the PHP-FPM front controller (ADR 0012)

  # 1024 MB: Argon2id uses 64 MiB per hash and the CPU share scales with memory (D12).
  memory_size = 1024
  timeout     = local.lambda_timeout_seconds

  aws_region        = var.aws_region
  php_layer_version = var.php_layer_version
  gd_layer_version  = null # no gd: the API only signs URLs

  package_path                   = var.package_path
  package_sha256                 = var.package_sha256
  reserved_concurrent_executions = var.reserved_concurrent_executions

  # D23: the API's keys. No *_ENDPOINT, no APP_ENV, no AWS_* key. ORIGIN_VERIFY_SECRETS is the only secret; the function
  # module's environment_variables output is sensitive for that reason.
  environment_variables = {
    BREF_RUNTIME          = "fpm"
    DYNAMODB_TABLE        = var.table_name
    RECEIPTS_BUCKET       = var.receipts_bucket
    THUMBNAILS_BUCKET     = var.thumbnails_bucket
    ORIGIN_VERIFY_SECRETS = var.origin_verify_secrets
    CLIENT_IP_HEADER      = "CloudFront-Viewer-Address" # ApiConfig accepts no other header
    APP_ORIGINS           = var.allowed_origin
  }

  policy_json = jsonencode(local.policy)
}

# ---------------------------------------------------------------- integration and routes

resource "aws_apigatewayv2_integration" "this" {
  api_id           = aws_apigatewayv2_api.this.id
  integration_type = "AWS_PROXY"
  integration_uri  = module.function.invoke_arn

  # A Lambda proxy integration is always invoked with POST, whatever the client method was.
  integration_method = "POST"

  # Payload 2.0: duplicate cookies and header handling that PHP relies on (Phase 2).
  payload_format_version = "2.0"
  timeout_milliseconds   = local.integration_timeout_milliseconds
}

resource "aws_apigatewayv2_route" "this" {
  for_each = local.route_keys

  api_id             = aws_apigatewayv2_api.this.id
  route_key          = each.value
  target             = "integrations/${aws_apigatewayv2_integration.this.id}"
  authorization_type = "NONE" # PHP authenticates
}

# ---------------------------------------------------------------- access log, created before the stage

resource "aws_cloudwatch_log_group" "access" {
  name              = "/aws/apigateway/${var.name}"
  retention_in_days = 30 # Phase 1 decision 2
}

# ---------------------------------------------------------------- stage

resource "aws_apigatewayv2_stage" "this" {
  api_id      = aws_apigatewayv2_api.this.id
  name        = "$default"
  auto_deploy = true

  access_log_settings {
    destination_arn = aws_cloudwatch_log_group.access.arn
    format          = local.access_log_format
  }

  default_route_settings {
    throttling_rate_limit  = local.default_throttle.rate
    throttling_burst_limit = local.default_throttle.burst
  }

  # Each setting names a route that must already exist (Step 0 R2: the docs do not say what happens otherwise). The
  # route_key comes from the route resource, which makes the dependency explicit; depends_on also covers $default.
  dynamic "route_settings" {
    for_each = local.throttled_routes

    content {
      route_key              = aws_apigatewayv2_route.this[route_settings.key].route_key
      throttling_rate_limit  = route_settings.value.rate
      throttling_burst_limit = route_settings.value.burst
    }
  }

  depends_on = [aws_apigatewayv2_route.this]
}

# ---------------------------------------------------------------- invoke permission

# Scoped to THIS API (its execution ARN) and this function; "/*/*" is any stage and any route of it. Never a wildcard
# in the API part of the ARN, which would let any API of the account invoke the function.
resource "aws_lambda_permission" "api" {
  statement_id  = "AllowInvokeFromHttpApi"
  action        = "lambda:InvokeFunction"
  function_name = module.function.function_name
  principal     = "apigateway.amazonaws.com"
  source_arn    = "${aws_apigatewayv2_api.this.execution_arn}/*/*"
}
