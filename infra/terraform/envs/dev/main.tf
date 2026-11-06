# The dev environment: every module wired together (D3). The wiring is the one the test fixtures
# (tests/fixtures/edge-wiring) prove has no resource cycle (D25):
#
#   api (HTTP API) -> edge (distribution) -> allowed origin -> API Lambda environment -> integration -> routes
#   edge (distribution) -> receipts CORS rule
#   worker role -> receipts bucket policy; receipts bucket ARNs -> worker policy (the role is created apart from its policy)
#
# Modules never configure providers; the provider is in providers.tf.

# One suffix for the three buckets (D4): no account id in a public bucket host, and a fresh name after a destroy.
resource "random_id" "bucket_suffix" {
  byte_length = 4
}

module "table" {
  source = "../../modules/table"

  table_name              = local.name
  max_read_request_units  = local.dynamodb_max_read_request_units
  max_write_request_units = local.dynamodb_max_write_request_units
}

module "receipts" {
  source = "../../modules/receipts"

  name                = local.name
  bucket_suffix       = random_id.bucket_suffix.hex
  aws_account_id      = var.aws_account_id
  cors_allowed_origin = local.allowed_origin
  worker_role_arn     = module.worker.role_arn
}

module "worker" {
  source = "../../modules/worker"

  name                           = local.name
  aws_region                     = var.aws_region
  aws_account_id                 = var.aws_account_id
  package_path                   = var.lambda_package_path
  package_sha256                 = var.lambda_package_sha256
  php_layer_version              = var.bref_php_layer_versions[var.aws_region]
  gd_layer_version               = var.bref_gd_layer_versions[var.aws_region]
  reserved_concurrent_executions = var.worker_reserved_concurrent_executions
  table_name                     = module.table.table_name
  table_arn                      = module.table.table_arn
  receipts_bucket                = module.receipts.receipts_bucket
  receipts_bucket_arn            = module.receipts.receipts_bucket_arn
  thumbnails_bucket              = module.receipts.thumbnails_bucket
  thumbnails_bucket_arn          = module.receipts.thumbnails_bucket_arn
  queue_arn                      = module.receipts.queue_arn
}

module "api" {
  source = "../../modules/api"

  name                           = local.name
  aws_region                     = var.aws_region
  aws_account_id                 = var.aws_account_id
  package_path                   = var.lambda_package_path
  package_sha256                 = var.lambda_package_sha256
  php_layer_version              = var.bref_php_layer_versions[var.aws_region]
  reserved_concurrent_executions = var.api_reserved_concurrent_executions
  table_name                     = module.table.table_name
  table_arn                      = module.table.table_arn
  receipts_bucket                = module.receipts.receipts_bucket
  receipts_bucket_arn            = module.receipts.receipts_bucket_arn
  thumbnails_bucket              = module.receipts.thumbnails_bucket
  thumbnails_bucket_arn          = module.receipts.thumbnails_bucket_arn
  origin_verify_secrets          = local.origin_verify_secrets
  allowed_origin                 = local.allowed_origin
}

module "edge" {
  source = "../../modules/edge"

  name                            = local.name
  aws_region                      = var.aws_region
  bucket_suffix                   = random_id.bucket_suffix.hex
  receipts_bucket                 = module.receipts.receipts_bucket
  thumbnails_bucket               = module.receipts.thumbnails_bucket
  api_endpoint                    = module.api.api_endpoint
  origin_verify_secret            = local.origin_verify_active_secret
  api_origin_read_timeout_seconds = module.api.cloudfront_origin_read_timeout_seconds
}

module "observability" {
  source = "../../modules/observability"

  name                = local.name
  environment         = var.environment
  api_log_group_name  = module.api.log_group_name
  receipts_queue_name = module.receipts.queue_name
  dlq_name            = module.receipts.dlq_name
  alert_email         = var.alert_email
  monthly_budget_usd  = var.monthly_budget_usd
}
