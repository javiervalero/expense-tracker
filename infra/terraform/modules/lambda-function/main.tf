# One Lambda function with its role, its inline policy and its log group (Phase 7 plan D3, D9, D10, D20 and D21).
# Used twice: the API (WP7) and the receipt worker (modules/worker).
#
# ORDERING (read this before changing the role). The receipts bucket policy (modules/receipts) names the worker role as
# a principal, and AWS rejects a bucket policy that names a role that does not exist yet. The worker's permissions in
# turn name the receipts buckets. So the role is a resource of its own that depends on nothing but its trust policy, and
# the permissions are a separate aws_iam_role_policy attached afterwards. Never inline the permissions into the role
# (`inline_policy` or `managed_policy_arns`), and never make the role (or its ARN output) depend on the policy: that
# would close the cycle role -> policy -> bucket ARN -> bucket policy -> role.
#   role  ->  bucket policy (needs the role ARN)         and        bucket  ->  role policy (needs the bucket ARN)

locals {
  # Lambda layers are published per region by the Bref project; the accounts are the publishers' (D10).
  bref_layer_account = "873528684822" # bref/bref php-85
  gd_layer_account   = "403367587399" # bref/extra-php-extensions gd-php-85

  php_layer_arn = "arn:aws:lambda:${var.aws_region}:${local.bref_layer_account}:layer:php-85:${var.php_layer_version}"
  gd_layer_arn  = var.gd_layer_version == null ? null : "arn:aws:lambda:${var.aws_region}:${local.gd_layer_account}:layer:gd-php-85:${var.gd_layer_version}"

  # php first, then gd: Bref extension layers are applied on top of the php layer.
  layers = var.gd_layer_version == null ? [local.php_layer_arn] : [local.php_layer_arn, local.gd_layer_arn]

  log_retention_days = 30 # Phase 1 decision 2

  # The package build writes the sha256 as 64 hex digits, and Lambda compares source_code_hash as base64 of the raw 32
  # bytes. Terraform has no hex-to-base64 function (filebase64sha256 would read the zip, which a test must not need), so
  # the conversion is done here: every 6 hex digits (3 bytes) become 4 base64 characters, and the last 4 digits
  # (2 bytes, padded with two zero bits) become 3 characters and "=". Tested against known sha256 vectors.
  base64_alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/"
  base64_groups = [
    for i in range(0, 10) : join("", [
      for divisor in [262144, 4096, 64, 1] :
      substr(local.base64_alphabet, floor(parseint(substr(var.package_sha256, i * 6, 6), 16) / divisor) % 64, 1)
    ])
  ]
  base64_tail = join("", [
    for divisor in [4096, 64, 1] :
    substr(local.base64_alphabet, floor(parseint(substr(var.package_sha256, 60, 4), 16) * 4 / divisor) % 64, 1)
  ])
  package_hash = "${join("", local.base64_groups)}${local.base64_tail}="
}

# ---------------------------------------------------------------- role (trust only) and its inline permissions

resource "aws_iam_role" "this" {
  name = var.function_name

  assume_role_policy = jsonencode({
    Version = "2012-10-17"
    Statement = [{
      Effect    = "Allow"
      Principal = { Service = "lambda.amazonaws.com" }
      Action    = "sts:AssumeRole"
    }]
  })
}

resource "aws_iam_role_policy" "this" {
  name   = "${var.function_name}-permissions"
  role   = aws_iam_role.this.id
  policy = var.policy_json
}

# ---------------------------------------------------------------- log group, created before the function

resource "aws_cloudwatch_log_group" "this" {
  name              = "/aws/lambda/${var.function_name}"
  retention_in_days = local.log_retention_days
}

# ---------------------------------------------------------------- the function

resource "aws_lambda_function" "this" {
  function_name = var.function_name
  role          = aws_iam_role.this.arn

  package_type  = "Zip"
  runtime       = "provided.al2023"
  architectures = ["x86_64"]
  handler       = var.handler
  memory_size   = var.memory_size
  timeout       = var.timeout
  layers        = local.layers

  filename         = var.package_path
  source_code_hash = local.package_hash

  reserved_concurrent_executions = var.reserved_concurrent_executions

  tracing_config {
    mode = "PassThrough" # X-Ray off (D9)
  }

  # Naming the group makes Terraform create it (with its retention) before the function, and Lambda writes to it.
  logging_config {
    log_group  = aws_cloudwatch_log_group.this.name
    log_format = "Text"
  }

  environment {
    variables = merge(var.environment_variables, {
      BREF_PING_DISABLE = "1"
    })
  }

  # The function must not start (or be invoked by a mapping) before its permissions exist.
  depends_on = [aws_iam_role_policy.this]

  # The uploaded zip must be the zip that was hashed: Lambda reports the base64 sha256 of the code it stored, and it must
  # equal the one computed from package_sha256 (which `make lambda-build` wrote). A mismatch means the file at
  # package_path changed after the hash was taken.
  lifecycle {
    postcondition {
      condition     = self.code_sha256 == local.package_hash
      error_message = "The code hash Lambda reports does not match package_sha256: the zip at package_path is not the zip that was hashed. Run `make lambda-build` again."
    }
  }
}
