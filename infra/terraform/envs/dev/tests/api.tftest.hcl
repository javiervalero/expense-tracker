# Offline tests of modules/api (D12, D14, D16 slot format, D21, D23 and D25 of the Phase 7 plan): the HTTP API, its one
# Lambda proxy integration, the routes that exist only to carry a throttle, the `$default` stage with its throttles and
# access log, the invoke permission, and the API Lambda (size, timeouts, exact environment). The IAM policy is tested in
# iam_api.tftest.hcl.
# The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in
# every test file). `command = apply`: under the mock, computed values are only known after apply and ARNs are random
# strings, so the ARNs the provider or the module validates are pinned below with override_resource.
# Not assertable here: `depends_on` (the stage waits for the routes, Step 0 R2; the code reviewer checks it) and the
# ABSENCE of a resource (a second integration, a function URL, a managed-policy attachment).
# The origin-verify secrets in this file are made-up examples, not real values. The module's environment output is
# sensitive, so the tests read the key set through keys() and compare the secret as a boolean; they never print it.
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
  allowed_origin        = "https://d111111abcdef8.cloudfront.net" # the AWS documentation example domain

  # Two slots of 47 visible ASCII characters (slot A, slot B); neither contains the other.
  origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,example-slot-b-9876543210zyxwvutsrqponmlkjihgfe"
}

# The function's postcondition compares the reported code hash with the one computed from package_sha256 (the hash of the
# empty input here); the mock returns a random string for it. Pinned wherever the function can sit.
override_resource {
  target = module.function.aws_lambda_function.this
  values = {
    code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
  }
}

override_resource {
  target = module.api.module.function.aws_lambda_function.this
  values = {
    code_sha256 = "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
  }
}

override_resource {
  target = module.worker.module.function.aws_lambda_function.this
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

override_resource {
  target = module.api.module.function.aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-api"
  }
}

override_resource {
  target = module.worker.module.function.aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

# The invoke permission is scoped by the API's execution ARN, and the CloudFront origin takes its endpoint.
override_resource {
  target = aws_apigatewayv2_api.this
  values = {
    api_endpoint  = "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
    execution_arn = "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5"
  }
}

override_resource {
  target = module.api.aws_apigatewayv2_api.this
  values = {
    api_endpoint  = "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com"
    execution_arn = "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5"
  }
}

override_resource {
  target = aws_cloudwatch_log_group.access
  values = {
    arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/apigateway/expense-tracker-dev"
  }
}

override_resource {
  target = module.api.aws_cloudwatch_log_group.access
  values = {
    arn = "arn:aws:logs:us-east-1:123456789012:log-group:/aws/apigateway/expense-tracker-dev"
  }
}

# ---------------------------------------------------------------- the HTTP API and its integration

run "the_api_is_an_http_api_on_the_default_endpoint_without_cors" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = aws_apigatewayv2_api.this.protocol_type == "HTTP" && aws_apigatewayv2_api.this.name == "expense-tracker-dev"
    error_message = "The API must be an HTTP API named after the environment."
  }

  # No custom domain, so the execute-api endpoint stays on; PHP's origin check answers direct callers with 403 (D14).
  assert {
    condition     = aws_apigatewayv2_api.this.disable_execute_api_endpoint == false
    error_message = "disable_execute_api_endpoint must be false (D14)."
  }

  # PHP answers every request, including the CORS decisions (D14); a gateway CORS block would answer before PHP.
  assert {
    condition     = length(aws_apigatewayv2_api.this.cors_configuration) == 0
    error_message = "The API must have no CORS configuration."
  }
}

run "one_proxy_integration_with_payload_2_and_a_29_second_timeout" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = aws_apigatewayv2_integration.this.integration_type == "AWS_PROXY"
    error_message = "The integration must be an AWS_PROXY Lambda integration."
  }

  # Payload 2.0: duplicate cookies and the header handling PHP relies on (Phase 2).
  assert {
    condition     = aws_apigatewayv2_integration.this.payload_format_version == "2.0"
    error_message = "payload_format_version must be 2.0."
  }

  assert {
    condition     = aws_apigatewayv2_integration.this.timeout_milliseconds == 29000
    error_message = "The integration timeout must be 29000 ms (D12)."
  }

  assert {
    condition     = aws_apigatewayv2_integration.this.integration_uri == module.function.invoke_arn && aws_apigatewayv2_integration.this.integration_method == "POST"
    error_message = "The integration must invoke the API function (POST, as Lambda proxies require)."
  }

  assert {
    condition     = aws_apigatewayv2_integration.this.api_id == aws_apigatewayv2_api.this.id
    error_message = "The integration must belong to this API."
  }
}

# ---------------------------------------------------------------- routes

run "routes_are_the_default_route_plus_the_five_throttled_ones" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = toset([for r in aws_apigatewayv2_route.this : r.route_key]) == toset([
      "$default",
      "GET /api/v1/reports/monthly",
      "POST /api/v1/expenses/{id}/receipt/upload",
      "GET /api/v1/expenses",
      "POST /api/v1/auth/login",
      "POST /api/v1/auth/register",
    ])
    error_message = "The routes must be $default and exactly the five throttled routes of D14."
  }

  assert {
    condition     = length(aws_apigatewayv2_route.this) == 6
    error_message = "There must be exactly six routes (no duplicates)."
  }
}

run "every_route_goes_to_the_one_integration_without_an_authorizer" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = alltrue([for r in aws_apigatewayv2_route.this : r.target == "integrations/${aws_apigatewayv2_integration.this.id}"])
    error_message = "Every route must target the one Lambda integration."
  }

  assert {
    condition     = alltrue([for r in aws_apigatewayv2_route.this : r.api_id == aws_apigatewayv2_api.this.id])
    error_message = "Every route must belong to this API."
  }

  # PHP authenticates; a gateway authorizer would be a second, unreviewed decision point.
  assert {
    condition     = alltrue([for r in aws_apigatewayv2_route.this : r.authorization_type == "NONE"])
    error_message = "No route may have an authorizer (the application authenticates)."
  }
}

# ---------------------------------------------------------------- stage and throttles

run "the_stage_is_default_and_deploys_automatically" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = aws_apigatewayv2_stage.this.name == "$default" && aws_apigatewayv2_stage.this.auto_deploy == true
    error_message = "The stage must be $default with auto_deploy."
  }

  assert {
    condition     = aws_apigatewayv2_stage.this.api_id == aws_apigatewayv2_api.this.id
    error_message = "The stage must belong to this API."
  }
}

run "the_stage_default_throttle_is_10_per_second_with_a_burst_of_20" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = one(aws_apigatewayv2_stage.this.default_route_settings).throttling_rate_limit == 10
    error_message = "The default rate limit must be 10 requests per second."
  }

  assert {
    condition     = one(aws_apigatewayv2_stage.this.default_route_settings).throttling_burst_limit == 20
    error_message = "The default burst limit must be 20."
  }
}

run "each_throttled_route_has_its_own_limits" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = {
      for rs in aws_apigatewayv2_stage.this.route_settings : rs.route_key => [rs.throttling_rate_limit, rs.throttling_burst_limit]
      } == {
      "GET /api/v1/reports/monthly"               = [1, 2]
      "POST /api/v1/expenses/{id}/receipt/upload" = [1, 3]
      "GET /api/v1/expenses"                      = [5, 10]
      "POST /api/v1/auth/login"                   = [2, 5]
      "POST /api/v1/auth/register"                = [1, 3]
    }
    error_message = "The route throttles must be reports 1/2, upload 1/3, list expenses 5/10, login 2/5 and register 1/3 (rate/burst, owner decision 7)."
  }
}

run "route_throttles_are_lower_than_the_default_and_belong_to_existing_routes" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = alltrue([
      for rs in aws_apigatewayv2_stage.this.route_settings :
      rs.throttling_rate_limit < one(aws_apigatewayv2_stage.this.default_route_settings).throttling_rate_limit
      && rs.throttling_burst_limit < one(aws_apigatewayv2_stage.this.default_route_settings).throttling_burst_limit
    ])
    error_message = "Every route throttle must be lower than the stage default (it exists to tighten it)."
  }

  assert {
    condition     = length(aws_apigatewayv2_stage.this.route_settings) == 5
    error_message = "There must be exactly five route settings ($default keeps the stage default)."
  }

  # Step 0 R2: the docs do not say whether route_settings need an existing route, so every key must name a route we create.
  assert {
    condition     = alltrue([for rs in aws_apigatewayv2_stage.this.route_settings : contains([for r in aws_apigatewayv2_route.this : r.route_key], rs.route_key)])
    error_message = "Every route setting must name a route of this API."
  }

  assert {
    condition     = alltrue([for rs in aws_apigatewayv2_stage.this.route_settings : rs.route_key != "$default"])
    error_message = "$default carries the stage default, not a route setting of its own."
  }
}

# ---------------------------------------------------------------- access log

run "access_log_group_is_named_after_the_api_with_30_days_of_retention" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = aws_cloudwatch_log_group.access.name == "/aws/apigateway/expense-tracker-dev"
    error_message = "The access log group must be /aws/apigateway/<name> (D21)."
  }

  assert {
    condition     = aws_cloudwatch_log_group.access.retention_in_days == 30
    error_message = "Retention must be 30 days."
  }

  assert {
    condition     = one(aws_apigatewayv2_stage.this.access_log_settings).destination_arn == aws_cloudwatch_log_group.access.arn
    error_message = "The stage must write its access log to that group."
  }
}

run "access_log_format_has_exactly_the_planned_fields" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = jsondecode(one(aws_apigatewayv2_stage.this.access_log_settings).format) == {
      requestId               = "$context.requestId"
      requestTime             = "$context.requestTime"
      httpMethod              = "$context.httpMethod"
      routeKey                = "$context.routeKey"
      path                    = "$context.path"
      status                  = "$context.status"
      responseLength          = "$context.responseLength"
      integrationLatency      = "$context.integrationLatency"
      integrationErrorMessage = "$context.integrationErrorMessage"
    }
    error_message = "The access log must hold exactly requestId, requestTime, httpMethod, routeKey, path, status, responseLength, integrationLatency and integrationErrorMessage (D21)."
  }
}

# Phase 3 B23: queries (q, cursors) can hold personal data and must never reach a log.
run "access_log_format_has_no_query_string_variable" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = !strcontains(lower(one(aws_apigatewayv2_stage.this.access_log_settings).format), "query")
    error_message = "The access log must not contain a query string variable."
  }

  # $context.path is the path without the query string; $request.querystring and $context.requestUrl-like variables are not used.
  assert {
    condition     = alltrue([for v in values(jsondecode(one(aws_apigatewayv2_stage.this.access_log_settings).format)) : startswith(v, "$context.") && !strcontains(v, "?")])
    error_message = "Every access log field must be a plain $context variable."
  }

  assert {
    condition     = !strcontains(one(aws_apigatewayv2_stage.this.access_log_settings).format, "$request") && !strcontains(one(aws_apigatewayv2_stage.this.access_log_settings).format, "$input")
    error_message = "The access log must not read request data ($request, $input)."
  }
}

# ---------------------------------------------------------------- invoke permission

run "permission_lets_this_api_invoke_this_function_only" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = aws_lambda_permission.api.source_arn == "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5/*/*"
    error_message = "source_arn must be this API's execution ARN followed by /*/* (any stage, any route), and nothing broader."
  }

  assert {
    condition     = aws_lambda_permission.api.source_arn == "${aws_apigatewayv2_api.this.execution_arn}/*/*"
    error_message = "source_arn must be built from this API's execution ARN."
  }

  assert {
    condition     = can(regex("^arn:aws:execute-api:us-east-1:123456789012:[a-z0-9]+/\\*/\\*$", aws_lambda_permission.api.source_arn))
    error_message = "source_arn must name one region, one account and one API id, then /*/* (no wildcard in the API part)."
  }

  assert {
    condition     = aws_lambda_permission.api.principal == "apigateway.amazonaws.com" && aws_lambda_permission.api.action == "lambda:InvokeFunction"
    error_message = "Only API Gateway may invoke the function, with lambda:InvokeFunction."
  }

  assert {
    condition     = aws_lambda_permission.api.function_name == module.function.function_name
    error_message = "The permission must be on the API function."
  }

  assert {
    condition     = aws_lambda_permission.api.source_account == null
    error_message = "source_account is not needed: the source ARN already names the account."
  }
}

# ---------------------------------------------------------------- the API Lambda

run "api_function_is_php_fpm_on_the_bref_layer_with_1024_mb_and_25_seconds" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = module.function.function_name == "expense-tracker-dev-api" && module.function.handler == "public/index.php"
    error_message = "The function must be <name>-api with the PHP-FPM front controller as handler (ADR 0012)."
  }

  assert {
    condition     = module.function.runtime == "provided.al2023" && module.function.architectures == tolist(["x86_64"])
    error_message = "The API must run provided.al2023 on x86_64."
  }

  assert {
    condition     = module.function.memory_size == 1024
    error_message = "The API needs 1024 MB (Argon2id: 64 MiB per hash, and CPU scales with memory; D12)."
  }

  assert {
    condition     = module.function.timeout == 25
    error_message = "The API timeout must be 25 s (D12)."
  }

  # No GD: the API only signs URLs; thumbnails are the worker's job.
  assert {
    condition     = module.function.layers == tolist(["arn:aws:lambda:us-east-1:873528684822:layer:php-85:20"])
    error_message = "The API must have the php-85 layer only (no gd-php-85)."
  }

  assert {
    condition     = module.function.log_group_name == "/aws/lambda/expense-tracker-dev-api"
    error_message = "The API log group must be /aws/lambda/expense-tracker-dev-api."
  }
}

run "api_environment_is_exactly_the_planned_key_set" {
  command = apply

  module {
    source = "../../modules/api"
  }

  # D23. keys() of a sensitive map is sensitive, so nonsensitive() is needed to compare; the keys are not secret.
  assert {
    condition = nonsensitive(keys(module.function.environment_variables)) == tolist([
      "APP_ORIGINS",
      "BREF_PING_DISABLE",
      "BREF_RUNTIME",
      "CLIENT_IP_HEADER",
      "DYNAMODB_TABLE",
      "ORIGIN_VERIFY_SECRETS",
      "RECEIPTS_BUCKET",
      "THUMBNAILS_BUCKET",
    ])
    error_message = "The API environment must be exactly APP_ORIGINS, BREF_PING_DISABLE, BREF_RUNTIME, CLIENT_IP_HEADER, DYNAMODB_TABLE, ORIGIN_VERIFY_SECRETS, RECEIPTS_BUCKET and THUMBNAILS_BUCKET."
  }

  assert {
    condition     = length([for k in nonsensitive(keys(module.function.environment_variables)) : k if startswith(k, "AWS_") || endswith(k, "_ENDPOINT") || k == "APP_ENV"]) == 0
    error_message = "No AWS_* key, *_ENDPOINT key or APP_ENV may reach the API."
  }
}

# The map holds the secret, so only the other entries are made readable: the filter runs before nonsensitive().
run "api_environment_values_are_the_planned_ones" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = nonsensitive({ for k, v in module.function.environment_variables : k => v if k != "ORIGIN_VERIFY_SECRETS" }) == {
      APP_ORIGINS       = "https://d111111abcdef8.cloudfront.net"
      BREF_PING_DISABLE = "1"
      BREF_RUNTIME      = "fpm"
      CLIENT_IP_HEADER  = "CloudFront-Viewer-Address"
      DYNAMODB_TABLE    = "expense-tracker-dev"
      RECEIPTS_BUCKET   = "expense-tracker-dev-receipts-abcd1234"
      THUMBNAILS_BUCKET = "expense-tracker-dev-receipt-thumbnails-abcd1234"
    }
    error_message = "The non-secret API environment values must be the planned ones (D23)."
  }
}

run "the_secret_reaches_the_function_unchanged_and_stays_sensitive" {
  command = apply

  module {
    source = "../../modules/api"
  }

  # Only the boolean is made readable, never the value.
  assert {
    condition     = nonsensitive(module.function.environment_variables["ORIGIN_VERIFY_SECRETS"] == var.origin_verify_secrets)
    error_message = "ORIGIN_VERIFY_SECRETS must be the origin_verify_secrets input, unchanged."
  }

  assert {
    condition     = issensitive(module.function.environment_variables)
    error_message = "The environment output must be sensitive."
  }
}

run "no_output_holds_the_secret" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition = !strcontains(jsonencode([
      output.api_id,
      output.api_endpoint,
      output.execution_arn,
      output.function_name,
      output.function_arn,
      output.role_arn,
      output.log_group_name,
      output.access_log_group_name,
      output.policy,
      output.app_origins,
      output.environment_variable_keys,
      output.lambda_timeout_seconds,
      output.integration_timeout_milliseconds,
      output.cloudfront_origin_read_timeout_seconds,
    ]), "example-slot-")
    error_message = "No output of the API module may hold the origin-verify secret."
  }
}

# ---------------------------------------------------------------- timeout chain (D12)

# Lambda 25 s < integration 29000 ms < CloudFront origin_read_timeout 30 s. The edge module does not exist yet (WP8); the
# API module owns the constants and exports the origin read timeout the edge module must use, so the chain is pinned here
# and WP8 only has to consume the output.
run "timeouts_form_a_strictly_increasing_chain" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = output.lambda_timeout_seconds == 25 && output.integration_timeout_milliseconds == 29000 && output.cloudfront_origin_read_timeout_seconds == 30
    error_message = "The chain must be Lambda 25 s, integration 29000 ms, CloudFront origin read timeout 30 s."
  }

  assert {
    condition     = output.lambda_timeout_seconds * 1000 < output.integration_timeout_milliseconds && output.integration_timeout_milliseconds < output.cloudfront_origin_read_timeout_seconds * 1000
    error_message = "Lambda timeout < integration timeout < CloudFront origin read timeout must hold, so each layer fails after the one behind it."
  }

  assert {
    condition     = module.function.timeout == output.lambda_timeout_seconds && aws_apigatewayv2_integration.this.timeout_milliseconds == output.integration_timeout_milliseconds
    error_message = "The real resources must use the chain constants."
  }

  # HTTP API integrations cannot wait longer than 30 s (Step 0 R2).
  assert {
    condition     = output.integration_timeout_milliseconds <= 30000
    error_message = "An HTTP API integration timeout is at most 30000 ms."
  }
}

run "outputs_describe_the_api" {
  command = apply

  module {
    source = "../../modules/api"
  }

  assert {
    condition     = output.api_endpoint == "https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com" && output.execution_arn == "arn:aws:execute-api:us-east-1:123456789012:a1b2c3d4e5"
    error_message = "The endpoint and execution ARN outputs must describe the API."
  }

  assert {
    condition     = output.app_origins == "https://d111111abcdef8.cloudfront.net"
    error_message = "app_origins must be the allowed origin given."
  }

  assert {
    condition     = output.environment_variable_keys == tolist(["APP_ORIGINS", "BREF_PING_DISABLE", "BREF_RUNTIME", "CLIENT_IP_HEADER", "DYNAMODB_TABLE", "ORIGIN_VERIFY_SECRETS", "RECEIPTS_BUCKET", "THUMBNAILS_BUCKET"])
    error_message = "environment_variable_keys must list the environment's keys (not its values)."
  }
}

# ---------------------------------------------------------------- input validation

run "a_single_valid_slot_is_accepted" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv"
  }

  assert {
    condition     = nonsensitive(module.function.environment_variables["ORIGIN_VERIFY_SECRETS"] == var.origin_verify_secrets)
    error_message = "One slot is valid (the other slot may be empty while no rotation is in progress)."
  }
}

run "rejects_a_slot_shorter_than_32_characters" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "0123456789012345678901234567890"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "accepts_a_slot_of_exactly_32_characters" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "01234567890123456789012345678901"
  }

  assert {
    condition     = nonsensitive(module.function.environment_variables["ORIGIN_VERIFY_SECRETS"] == var.origin_verify_secrets)
    error_message = "A slot of exactly 32 characters is valid (ApiConfig::MIN_SLOT_LENGTH)."
  }
}

run "rejects_an_empty_secret" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = ""
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_an_empty_second_slot" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_three_slots" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,example-slot-b-9876543210zyxwvutsrqponmlkjihgfe,example-slot-c-abcdefghijklmnopqrstuv0123456789"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_the_same_slot_twice" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,example-slot-a-0123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_one_slot_inside_the_other" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv,xxexample-slot-a-0123456789abcdefghijklmnopqrstuvxx"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_a_slot_with_a_space" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example slot a 0123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_a_slot_with_a_double_quote" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-\"123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_a_slot_with_a_backslash" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-\\123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_a_slot_with_a_non_ascii_character" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-é123456789abcdefghijklmnopqrstuv"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_a_slot_with_a_line_break" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    origin_verify_secrets = "example-slot-a-0123456789abcdefghijklmnopqrstuv\n"
  }

  expect_failures = [var.origin_verify_secrets]
}

run "rejects_an_allowed_origin_over_http" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "http://d111111abcdef8.cloudfront.net"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_trailing_slash" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net/"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_wildcard" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://*.cloudfront.net"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_path" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net/app"
  }

  expect_failures = [var.allowed_origin]
}

# Exactly as strict as ApiConfig (ORIGIN_PATTERN and isValidOrigin, https case) and as the receipts module's CORS origin,
# which must be the same string: labels of 1 to 63 characters, no label starting or ending with a hyphen, a port from 1
# to 65535 without a leading zero and not 443. A value ApiConfig refuses would make every request fail with a 500.
run "rejects_an_allowed_origin_with_the_default_https_port" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net:443"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_port_that_starts_with_zero" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net:0443"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_port_above_65535" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net:70000"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_port_zero" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net:0"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_an_empty_label" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://a..b"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_label_starting_with_a_hyphen" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://a.-b"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_label_ending_with_a_hyphen" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://a-.b"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_allowed_origin_with_a_64_character_label" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.example.com"
  }

  expect_failures = [var.allowed_origin]
}

run "accepts_an_allowed_origin_with_a_63_character_label" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.example.com"
  }

  assert {
    condition     = output.app_origins == "https://aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.example.com"
    error_message = "A label of 63 characters is valid."
  }
}

run "accepts_an_allowed_origin_with_port_8443" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net:8443"
  }

  assert {
    condition     = output.app_origins == "https://d111111abcdef8.cloudfront.net:8443"
    error_message = "A non-default port from 1 to 65535 is valid."
  }
}

run "accepts_a_cloudfront_domain_as_allowed_origin" {
  command = apply

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net"
  }

  assert {
    condition     = output.app_origins == "https://d111111abcdef8.cloudfront.net"
    error_message = "A CloudFront domain is a valid origin."
  }
}

run "rejects_two_allowed_origins" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = "https://d111111abcdef8.cloudfront.net,https://example.com"
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_an_empty_allowed_origin" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    allowed_origin = ""
  }

  expect_failures = [var.allowed_origin]
}

run "rejects_a_name_too_long_for_the_function_and_role" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    name = "expense-tracker-dev-with-a-very-long-name-that-cannot-fit-in-the-role"
  }

  expect_failures = [var.name]
}

run "rejects_an_account_id_that_is_not_12_digits" {
  command = plan

  module {
    source = "../../modules/api"
  }

  variables {
    aws_account_id = "1234"
  }

  expect_failures = [var.aws_account_id]
}

# ---------------------------------------------------------------- wiring without a cycle (D25)

# API -> distribution -> API Lambda environment (APP_ORIGINS) -> integration -> routes. The distribution needs only the
# API endpoint, which belongs to the API resource and not to the Lambda, so the modules wire together. A real cycle would
# make this apply fail. The fixture stands in for the edge module (WP8) and also wires the table, the receipts module
# and the worker, as the composition (WP10) does. The ARNs the modules validate are pinned because the mock returns
# random strings.
run "api_receipts_worker_and_table_wire_together_without_a_cycle" {
  command = apply

  module {
    source = "./tests/fixtures/api-wiring"
  }

  override_resource {
    target = module.table.aws_dynamodb_table.this
    values = {
      arn = "arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"
    }
  }

  override_resource {
    target = module.receipts.aws_s3_bucket.receipts
    values = {
      arn = "arn:aws:s3:::expense-tracker-dev-receipts-abcd1234"
    }
  }

  override_resource {
    target = module.receipts.aws_s3_bucket.thumbnails
    values = {
      arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"
    }
  }

  override_resource {
    target = module.receipts.aws_sqs_queue.receipts
    values = {
      arn = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"
    }
  }

  override_resource {
    target = module.receipts.aws_sqs_queue.dlq
    values = {
      arn = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts-dlq"
    }
  }

  variables {
    bucket_suffix       = "abcd1234"
    distribution_domain = "d111111abcdef8.cloudfront.net"
    gd_layer_version    = 1
  }

  assert {
    condition     = output.api_app_origins == "https://d111111abcdef8.cloudfront.net" && output.api_app_origins == output.cors_origin
    error_message = "APP_ORIGINS and the receipts CORS origin must both be the distribution's domain over https."
  }

  assert {
    condition     = length(output.api_environment_keys) == 8
    error_message = "The API environment keeps its eight keys inside the composition."
  }

  assert {
    condition     = contains(flatten([for s in jsondecode(output.api_policy).Statement : s.Resource]), output.table_arn) && contains(flatten([for s in jsondecode(output.worker_policy).Statement : s.Resource]), output.table_arn)
    error_message = "The API and the worker must be given the same table."
  }

  assert {
    condition     = contains(flatten([for s in jsondecode(output.api_policy).Statement : s.Resource]), output.receipts_bucket_arn) && contains(flatten([for s in jsondecode(output.api_policy).Statement : s.Resource]), output.thumbnails_bucket_arn) && output.receipts_bucket_arn != output.thumbnails_bucket_arn
    error_message = "The API policy must be built from the receipts module's two distinct buckets."
  }
}
