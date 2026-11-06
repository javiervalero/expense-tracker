# Test fixture, not a deployable root: edge.tftest.hcl applies it under a mocked AWS provider to prove that modules/table,
# modules/receipts, modules/worker, modules/api and modules/edge wire together without a dependency cycle (D25). The real
# composition (WP10) wires them the same way.
#
# The cycles to rule out: the API Lambda's APP_ORIGINS needs the CloudFront domain, the receipts CORS rule needs it too,
# and the distribution needs the API endpoint and the receipt bucket names. There is none: the endpoint belongs to the API
# resource and not to the Lambda, the bucket names to the bucket resources and not to the CORS rule, and the domain is read
# only by the Lambda environment and the CORS rule. Unlike api-wiring, nothing stands in for the distribution here.

terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "6.68.0"
    }
  }
}

variable "name" {
  type = string
}

variable "aws_region" {
  type = string
}

variable "aws_account_id" {
  type = string
}

variable "bucket_suffix" {
  type = string
}

# Two comma-separated slots, as in ORIGIN_VERIFY_SECRETS; the distribution gets the first (the active slot).
variable "origin_verify_secrets" {
  type      = string
  sensitive = true
}

variable "package_path" {
  type = string
}

variable "package_sha256" {
  type = string
}

variable "php_layer_version" {
  type = number
}

variable "gd_layer_version" {
  type = number
}

module "table" {
  source = "../../../../../modules/table"

  table_name              = var.name
  max_read_request_units  = 400
  max_write_request_units = 100
}

module "receipts" {
  source = "../../../../../modules/receipts"

  name                = var.name
  bucket_suffix       = var.bucket_suffix
  aws_account_id      = var.aws_account_id
  cors_allowed_origin = local.allowed_origin
  worker_role_arn     = module.worker.role_arn
}

module "worker" {
  source = "../../../../../modules/worker"

  name                  = var.name
  aws_region            = var.aws_region
  aws_account_id        = var.aws_account_id
  package_path          = var.package_path
  package_sha256        = var.package_sha256
  php_layer_version     = var.php_layer_version
  gd_layer_version      = var.gd_layer_version
  table_name            = module.table.table_name
  table_arn             = module.table.table_arn
  receipts_bucket       = module.receipts.receipts_bucket
  receipts_bucket_arn   = module.receipts.receipts_bucket_arn
  thumbnails_bucket     = module.receipts.thumbnails_bucket
  thumbnails_bucket_arn = module.receipts.thumbnails_bucket_arn
  queue_arn             = module.receipts.queue_arn
}

module "edge" {
  source = "../../../../../modules/edge"

  name                            = var.name
  aws_region                      = var.aws_region
  bucket_suffix                   = var.bucket_suffix
  receipts_bucket                 = module.receipts.receipts_bucket
  thumbnails_bucket               = module.receipts.thumbnails_bucket
  api_endpoint                    = module.api.api_endpoint
  origin_verify_secret            = split(",", var.origin_verify_secrets)[0]
  api_origin_read_timeout_seconds = module.api.cloudfront_origin_read_timeout_seconds
}

locals {
  allowed_origin = "https://${module.edge.domain_name}"
}

module "api" {
  source = "../../../../../modules/api"

  name                  = var.name
  aws_region            = var.aws_region
  aws_account_id        = var.aws_account_id
  package_path          = var.package_path
  package_sha256        = var.package_sha256
  php_layer_version     = var.php_layer_version
  table_name            = module.table.table_name
  table_arn             = module.table.table_arn
  receipts_bucket       = module.receipts.receipts_bucket
  receipts_bucket_arn   = module.receipts.receipts_bucket_arn
  thumbnails_bucket     = module.receipts.thumbnails_bucket
  thumbnails_bucket_arn = module.receipts.thumbnails_bucket_arn
  origin_verify_secrets = var.origin_verify_secrets
  allowed_origin        = local.allowed_origin
}

output "api_app_origins" {
  value = module.api.app_origins
}

output "cors_origin" {
  value = local.allowed_origin
}

output "edge_domain_name" {
  value = module.edge.domain_name
}

output "edge_api_origin_domain" {
  value = module.edge.api_origin_domain
}

output "edge_csp_mentions_both_buckets" {
  value = (
    strcontains(module.edge.content_security_policy, "connect-src 'self' https://${module.receipts.receipts_bucket}.")
    && strcontains(module.edge.content_security_policy, "img-src 'self' https://${module.receipts.thumbnails_bucket}.")
  )
}
