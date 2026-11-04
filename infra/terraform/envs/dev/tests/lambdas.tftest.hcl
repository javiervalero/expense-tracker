# Offline tests of modules/lambda-function (the function, its role, the inline policy and the log group; used twice) and
# modules/worker (the receipt worker and its event source mapping): D3, D8, D9, D10, D13, D21 and D23 of the Phase 7 plan.
# The AWS provider is mocked, so nothing here can reach an account (scripts/check-tftest-mocks.sh enforces the mock in
# every test file). `command = apply`: under the mock, computed values are only known after apply and ARNs are random
# strings, so the assertions compare attributes with the inputs they were built from, or with the attribute of the
# resource they should point at. The worker's policy is tested in iam_worker.tftest.hcl.
# Not assertable here: the ABSENCE of a resource (a function URL, a managed-policy attachment, a second event source
# mapping) and `depends_on`; the modules have no such resources, and code review covers that.
mock_provider "aws" {}

variables {
  # modules/worker
  name                  = "expense-tracker-dev"
  aws_account_id        = "123456789012" # the AWS documentation example account
  table_name            = "expense-tracker-dev"
  table_arn             = "arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev"
  receipts_bucket       = "expense-tracker-dev-receipts-abcd1234"
  receipts_bucket_arn   = "arn:aws:s3:::expense-tracker-dev-receipts-abcd1234"
  thumbnails_bucket     = "expense-tracker-dev-receipt-thumbnails-abcd1234"
  thumbnails_bucket_arn = "arn:aws:s3:::expense-tracker-dev-receipt-thumbnails-abcd1234"
  queue_arn             = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"

  # shared by both modules
  aws_region        = "us-east-1"
  package_path      = "../../../../build/lambda/backend.zip"
  package_sha256    = "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855" # sha256 of the empty input
  php_layer_version = 20
  gd_layer_version  = 1

  # modules/lambda-function
  function_name = "expense-tracker-dev-worker"
  handler       = "entrypoints/lambda/worker.php"
  memory_size   = 1024
  timeout       = 30
  environment_variables = {
    BREF_RUNTIME    = "function"
    DYNAMODB_TABLE  = "expense-tracker-dev"
    RECEIPTS_BUCKET = "expense-tracker-dev-receipts-abcd1234"
  }
  policy_json = "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"dynamodb:GetItem\"],\"Resource\":[\"arn:aws:dynamodb:us-east-1:123456789012:table/expense-tracker-dev\"]}]}"
}

# The mock returns a random string for the role ARN, and the provider's own validation of aws_lambda_function.role
# (it must be an ARN) still runs. The role is pinned to a realistic ARN at each place the module can sit: directly under
# a run (lambda-function), inside the worker, and inside the worker inside the wiring fixture.
override_resource {
  target = aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

override_resource {
  target = module.function.aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

override_resource {
  target = module.worker.module.function.aws_iam_role.this
  values = {
    arn = "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
  }
}

# ---------------------------------------------------------------- modules/lambda-function

run "function_runs_provided_al2023_on_x86_64" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = aws_lambda_function.this.runtime == "provided.al2023"
    error_message = "The runtime must be provided.al2023 (Bref layers run on it)."
  }

  assert {
    condition     = aws_lambda_function.this.architectures == tolist(["x86_64"])
    error_message = "The architecture must be x86_64 (the Bref layers pinned in D10 are the x86_64 ones)."
  }

  assert {
    condition     = aws_lambda_function.this.package_type == "Zip"
    error_message = "The function must be deployed as a zip."
  }
}

run "function_takes_its_name_handler_memory_and_timeout_from_the_inputs" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    function_name = "expense-tracker-dev-api"
    handler       = "public/index.php"
    memory_size   = 512
    timeout       = 25
  }

  assert {
    condition     = aws_lambda_function.this.function_name == "expense-tracker-dev-api"
    error_message = "The function must take the given name."
  }

  assert {
    condition     = aws_lambda_function.this.handler == "public/index.php"
    error_message = "The function must take the given handler."
  }

  assert {
    condition     = aws_lambda_function.this.memory_size == 512 && aws_lambda_function.this.timeout == 25
    error_message = "The function must take the given memory and timeout."
  }
}

run "function_has_no_vpc_no_tracing_and_no_reserved_concurrency_by_default" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = length(aws_lambda_function.this.vpc_config) == 0
    error_message = "The function must not be in a VPC (D9)."
  }

  assert {
    condition     = one(aws_lambda_function.this.tracing_config).mode == "PassThrough"
    error_message = "X-Ray must be off (PassThrough, D9)."
  }

  assert {
    condition     = aws_lambda_function.this.reserved_concurrent_executions == -1
    error_message = "Reserved concurrency must default to -1 (unreserved; Phase 8 reads the account quota first)."
  }
}

run "reserved_concurrency_follows_the_variable" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    reserved_concurrent_executions = 5
  }

  assert {
    condition     = aws_lambda_function.this.reserved_concurrent_executions == 5
    error_message = "reserved_concurrent_executions must come from the variable."
  }
}

run "function_code_is_the_package_with_a_base64_hash_of_the_hex_sha256" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = aws_lambda_function.this.filename == "../../../../build/lambda/backend.zip"
    error_message = "The code must be the package at package_path."
  }

  # The package build writes the sha256 as 64 hex digits; Lambda wants it base64. This is the well-known vector of the
  # empty input: sha256("") in base64.
  assert {
    condition     = aws_lambda_function.this.source_code_hash == "47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU="
    error_message = "source_code_hash must be the base64 form of the hex sha256 (padded, 44 characters)."
  }
}

run "the_hash_conversion_is_right_for_a_second_vector" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    package_sha256 = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad" # sha256("abc")
  }

  assert {
    condition     = aws_lambda_function.this.source_code_hash == "ungWv48Bz+pBQUDeXa4iI7ADYaOWF3qctBD/YfIAFa0="
    error_message = "source_code_hash must be the base64 form of the hex sha256 of \"abc\"."
  }
}

run "layers_are_php_85_then_gd_php_85_from_the_publisher_accounts" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition = aws_lambda_function.this.layers == tolist([
      "arn:aws:lambda:us-east-1:873528684822:layer:php-85:20",
      "arn:aws:lambda:us-east-1:403367587399:layer:gd-php-85:1",
    ])
    error_message = "The layers must be the Bref php-85 and the extra-php-extensions gd-php-85 ARNs of the region and versions given, in that order."
  }

  assert {
    condition     = output.layers == tolist(["arn:aws:lambda:us-east-1:873528684822:layer:php-85:20", "arn:aws:lambda:us-east-1:403367587399:layer:gd-php-85:1"])
    error_message = "php-85 must come first and gd-php-85 after it (Bref layers are applied in order)."
  }
}

run "layers_follow_the_region_and_the_versions" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    aws_region        = "eu-west-1"
    php_layer_version = 21
    gd_layer_version  = 3
  }

  assert {
    condition     = output.layers == tolist(["arn:aws:lambda:eu-west-1:873528684822:layer:php-85:21", "arn:aws:lambda:eu-west-1:403367587399:layer:gd-php-85:3"])
    error_message = "The layer ARNs must be built from the region and the two versions."
  }
}

run "the_gd_layer_is_optional" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    gd_layer_version = null
  }

  assert {
    condition     = output.layers == tolist(["arn:aws:lambda:us-east-1:873528684822:layer:php-85:20"])
    error_message = "Without a gd version the function must have the php-85 layer only (the API does not need GD)."
  }
}

run "bref_ping_is_always_disabled_on_top_of_the_given_environment" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition = one(aws_lambda_function.this.environment).variables == tomap({
      BREF_RUNTIME      = "function"
      BREF_PING_DISABLE = "1"
      DYNAMODB_TABLE    = "expense-tracker-dev"
      RECEIPTS_BUCKET   = "expense-tracker-dev-receipts-abcd1234"
    })
    error_message = "The environment must be the given variables plus BREF_PING_DISABLE=1, and nothing else."
  }
}

run "the_caller_cannot_turn_the_bref_ping_back_on" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    environment_variables = {
      BREF_RUNTIME      = "function"
      BREF_PING_DISABLE = "0"
    }
  }

  assert {
    condition     = one(aws_lambda_function.this.environment).variables["BREF_PING_DISABLE"] == "1"
    error_message = "BREF_PING_DISABLE must stay 1 whatever the caller passes."
  }
}

run "log_group_has_the_function_name_and_30_days_of_retention" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = aws_cloudwatch_log_group.this.name == "/aws/lambda/expense-tracker-dev-worker"
    error_message = "The log group must be /aws/lambda/<function name>."
  }

  assert {
    condition     = aws_cloudwatch_log_group.this.retention_in_days == 30
    error_message = "Retention must be 30 days (Phase 1 decision 2)."
  }

  assert {
    condition     = output.log_group_name == aws_cloudwatch_log_group.this.name && output.log_group_arn == aws_cloudwatch_log_group.this.arn
    error_message = "The outputs must describe the log group."
  }
}

run "function_logs_to_that_explicit_group_in_text_format" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  # Naming the group here (instead of leaving the default) also makes Terraform create the group before the function,
  # so Lambda never creates it first with no retention.
  assert {
    condition     = one(aws_lambda_function.this.logging_config).log_group == aws_cloudwatch_log_group.this.name
    error_message = "logging_config.log_group must be the explicit log group."
  }

  assert {
    condition     = one(aws_lambda_function.this.logging_config).log_format == "Text"
    error_message = "The log format must stay Text (the application already writes JSON lines, D21)."
  }
}

run "role_trusts_lambda_only_and_has_the_function_name" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = aws_iam_role.this.name == "expense-tracker-dev-worker"
    error_message = "The role must have the function's name."
  }

  assert {
    condition = jsondecode(aws_iam_role.this.assume_role_policy) == {
      Version = "2012-10-17"
      Statement = [{
        Effect    = "Allow"
        Principal = { Service = "lambda.amazonaws.com" }
        Action    = "sts:AssumeRole"
      }]
    }
    error_message = "Only lambda.amazonaws.com may assume the role."
  }
}

# The receipts bucket policy names the worker role as a principal, and AWS rejects a policy that names a role that does
# not exist. So the role must be created first, by itself, and the permissions (which name the buckets) are a separate
# resource attached afterwards. These assertions pin that shape; the cycle check is the last run of this file.
run "permissions_are_a_separate_inline_policy_attached_to_the_role" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = aws_iam_role_policy.this.role == aws_iam_role.this.id
    error_message = "The permissions must be an aws_iam_role_policy attached to the role."
  }

  assert {
    condition     = aws_iam_role_policy.this.policy == var.policy_json
    error_message = "The attached policy must be exactly the JSON that was passed in."
  }

  assert {
    condition     = output.policy == var.policy_json
    error_message = "The policy output must be the attached policy."
  }

  assert {
    condition     = output.role_arn == aws_iam_role.this.arn && output.role_name == aws_iam_role.this.name
    error_message = "The role outputs must describe the role."
  }

  assert {
    condition     = aws_lambda_function.this.role == aws_iam_role.this.arn
    error_message = "The function must run as that role."
  }
}

run "outputs_describe_the_function" {
  command = apply

  module {
    source = "../../modules/lambda-function"
  }

  assert {
    condition     = output.function_name == "expense-tracker-dev-worker" && output.function_arn == aws_lambda_function.this.arn
    error_message = "function_name and function_arn must describe the function."
  }
}

run "rejects_a_policy_that_is_not_json" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    policy_json = "not json"
  }

  expect_failures = [var.policy_json]
}

run "rejects_a_policy_with_a_wildcard_action" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    policy_json = "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"s3:*\"],\"Resource\":[\"arn:aws:s3:::example\"]}]}"
  }

  expect_failures = [var.policy_json]
}

run "rejects_a_policy_with_a_wildcard_resource" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    policy_json = "{\"Version\":\"2012-10-17\",\"Statement\":[{\"Effect\":\"Allow\",\"Action\":[\"s3:GetObject\"],\"Resource\":\"*\"}]}"
  }

  expect_failures = [var.policy_json]
}

run "rejects_a_reserved_aws_environment_key" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    environment_variables = { AWS_REGION = "us-east-1" }
  }

  expect_failures = [var.environment_variables]
}

run "rejects_an_endpoint_override_in_the_environment" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    environment_variables = { DYNAMODB_ENDPOINT = "http://localhost:8000" }
  }

  expect_failures = [var.environment_variables]
}

run "rejects_app_env_in_the_environment" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    environment_variables = { APP_ENV = "local" }
  }

  expect_failures = [var.environment_variables]
}

run "rejects_a_sha256_that_is_not_64_lower_case_hex_digits" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    package_sha256 = "E3B0C44298FC1C149AFBF4C8996FB92427AE41E4649B934CA495991B7852B855"
  }

  expect_failures = [var.package_sha256]
}

run "rejects_a_memory_size_below_the_lambda_minimum" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    memory_size = 64
  }

  expect_failures = [var.memory_size]
}

run "rejects_a_timeout_above_the_lambda_maximum" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    timeout = 901
  }

  expect_failures = [var.timeout]
}

run "rejects_a_function_name_that_is_not_a_lambda_name" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    function_name = "has spaces/and-slash"
  }

  expect_failures = [var.function_name]
}

run "rejects_a_layer_version_of_zero" {
  command = plan

  module {
    source = "../../modules/lambda-function"
  }

  variables {
    php_layer_version = 0
  }

  expect_failures = [var.php_layer_version]
}

# ---------------------------------------------------------------- modules/worker

run "worker_function_is_named_after_the_environment" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = module.function.function_name == "expense-tracker-dev-worker"
    error_message = "The worker function must be <name>-worker."
  }
}

run "worker_runs_provided_al2023_on_x86_64_with_the_php_handler" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = module.function.runtime == "provided.al2023"
    error_message = "The worker runtime must be provided.al2023."
  }

  assert {
    condition     = module.function.architectures == tolist(["x86_64"])
    error_message = "The worker architecture must be x86_64."
  }

  assert {
    condition     = module.function.handler == "entrypoints/lambda/worker.php"
    error_message = "The worker handler must be entrypoints/lambda/worker.php (the file the Bref function runtime loads)."
  }
}

run "worker_has_1024_mb_and_a_30_second_timeout" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  # 1024 MB is tied to WorkerRuntime::MEMORY_LIMIT ('768M', backend/src/Worker/WorkerRuntime.php): PHP's own limit sits
  # below the Lambda memory so PHP fails with a message instead of the process being killed. Change both together.
  assert {
    condition     = module.function.memory_size == 1024
    error_message = "The worker needs 1024 MB (WorkerRuntime::MEMORY_LIMIT 768M is tied to it)."
  }

  # The queue's visibility timeout (180 s) is 6 times this value (D8).
  assert {
    condition     = module.function.timeout == 30
    error_message = "The worker timeout must be 30 s (the queue's visibility timeout is 6 times it)."
  }
}

run "worker_layers_are_php_85_then_gd_php_85" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = module.function.layers == tolist(["arn:aws:lambda:us-east-1:873528684822:layer:php-85:20", "arn:aws:lambda:us-east-1:403367587399:layer:gd-php-85:1"])
    error_message = "The worker needs the php-85 layer first and the gd-php-85 layer after it (thumbnails are made with GD)."
  }
}

run "worker_environment_is_exactly_the_planned_key_set" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  # D23: no *_ENDPOINT, no APP_ENV, no AWS_* key; the origin-verify keys are the API's only.
  assert {
    condition = module.function.environment_variables == tomap({
      BREF_RUNTIME      = "function"
      BREF_PING_DISABLE = "1"
      DYNAMODB_TABLE    = "expense-tracker-dev"
      RECEIPTS_BUCKET   = "expense-tracker-dev-receipts-abcd1234"
      THUMBNAILS_BUCKET = "expense-tracker-dev-receipt-thumbnails-abcd1234"
    })
    error_message = "The worker environment must be exactly BREF_RUNTIME, BREF_PING_DISABLE, DYNAMODB_TABLE, RECEIPTS_BUCKET and THUMBNAILS_BUCKET."
  }

  assert {
    condition     = length([for k in keys(module.function.environment_variables) : k if startswith(k, "AWS_") || endswith(k, "_ENDPOINT") || k == "APP_ENV"]) == 0
    error_message = "No AWS_* key, *_ENDPOINT key or APP_ENV may reach the worker."
  }
}

run "worker_log_group_is_the_function_log_group" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = module.function.log_group_name == "/aws/lambda/expense-tracker-dev-worker"
    error_message = "The worker log group must be /aws/lambda/expense-tracker-dev-worker."
  }
}

run "event_source_mapping_reads_the_main_queue_one_message_at_a_time" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.event_source_arn == "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"
    error_message = "The event source must be the main queue."
  }

  assert {
    condition     = !endswith(aws_lambda_event_source_mapping.receipts.event_source_arn, "-dlq")
    error_message = "The event source must never be the dead-letter queue."
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.function_name == module.function.function_arn
    error_message = "The mapping must invoke the worker function."
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.batch_size == 1
    error_message = "batch_size must be 1 (hard requirement since Phase 5)."
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.maximum_batching_window_in_seconds == 0
    error_message = "maximum_batching_window_in_seconds must be 0."
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.enabled == true
    error_message = "The mapping must be enabled."
  }
}

run "event_source_mapping_reports_batch_item_failures_and_caps_concurrency_at_2" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = aws_lambda_event_source_mapping.receipts.function_response_types == toset(["ReportBatchItemFailures"])
    error_message = "function_response_types must be exactly ReportBatchItemFailures."
  }

  # 2 is the AWS minimum for maximum_concurrency (Step 0 R2).
  assert {
    condition     = one(aws_lambda_event_source_mapping.receipts.scaling_config).maximum_concurrency == 2
    error_message = "maximum_concurrency must be 2."
  }
}

run "worker_outputs_name_the_role_and_the_source" {
  command = apply

  module {
    source = "../../modules/worker"
  }

  assert {
    condition     = output.role_arn == module.function.role_arn && output.function_arn == module.function.function_arn
    error_message = "The worker outputs must be the function module's."
  }

  assert {
    condition     = output.event_source_arn == "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts"
    error_message = "event_source_arn must be the mapping's source queue."
  }
}

run "worker_rejects_the_dead_letter_queue_as_its_source" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    queue_arn = "arn:aws:sqs:us-east-1:123456789012:expense-tracker-dev-receipts-dlq"
  }

  expect_failures = [var.queue_arn]
}

# gd_layer_version is also nullable = false, so Terraform itself refuses null before this rule runs; 0 shows the rule.
run "worker_rejects_a_gd_layer_version_below_one" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    gd_layer_version = 0
  }

  expect_failures = [var.gd_layer_version]
}

run "worker_rejects_a_queue_that_is_not_an_sqs_arn" {
  command = plan

  module {
    source = "../../modules/worker"
  }

  variables {
    queue_arn = "https://sqs.us-east-1.amazonaws.com/123456789012/expense-tracker-dev-receipts"
  }

  expect_failures = [var.queue_arn]
}

# The receipts bucket policy names the worker role, and the worker's permissions name the receipts buckets. With the
# role created apart from its policy (see above) the two modules wire together without a cycle; a cycle, or a role that
# depended on the bucket policy, would make this apply fail. The role ARN is pinned because the mock returns a random
# string, which the receipts module rightly rejects.
run "receipts_and_worker_wire_together_without_a_cycle" {
  command = apply

  module {
    source = "./tests/fixtures/receipts-worker-wiring"
  }

  # The worker module validates the ARNs it is given, and the mock returns random strings for them.
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
    cors_allowed_origin = "https://d111111abcdef8.cloudfront.net"
    bucket_suffix       = "abcd1234"
  }

  assert {
    condition     = output.worker_event_source_arn == output.queue_arn && output.worker_event_source_arn != output.dlq_arn
    error_message = "The worker must read the receipts module's main queue, never its dead-letter queue."
  }

  assert {
    condition     = output.worker_role_arn == "arn:aws:iam::123456789012:role/expense-tracker-dev-worker"
    error_message = "The role ARN handed to the receipts module must be the worker role's."
  }

  assert {
    condition     = contains(flatten([for s in jsondecode(output.worker_policy).Statement : s.Resource]), output.receipts_bucket_arn)
    error_message = "The worker's policy must be built from the receipts module's bucket ARN."
  }
}
