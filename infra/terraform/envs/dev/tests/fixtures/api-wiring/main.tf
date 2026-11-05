# Test fixture, not a deployable root: api.tftest.hcl applies it under a mocked AWS provider to prove that modules/table,
# modules/receipts, modules/worker and modules/api wire together without a dependency cycle (D25). The real composition
# (WP10) wires them the same way.
#
# The cycle to rule out is the one Phase 2 R15 feared: the API Lambda's APP_ORIGINS needs the CloudFront domain, and the
# distribution needs the API endpoint. There is none, because the endpoint belongs to the API resource and not to the
# Lambda. The real edge module is wired in edge-wiring; here terraform_data stands in for the distribution: it takes the API
# endpoint as input (the real edge dependency) and hands the domain on to the API module as the allowed origin.
# terraform_data is built into Terraform: no extra provider.

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

variable "distribution_domain" {
  type = string
}

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

# Stands in for modules/edge: it needs the API endpoint, and it produces the domain the API and the receipts CORS rule
# are given.
resource "terraform_data" "distribution" {
  input = {
    origin_domain = module.api.api_endpoint
    domain_name   = var.distribution_domain
  }
}

locals {
  allowed_origin = "https://${terraform_data.distribution.output.domain_name}"
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

output "api_policy" {
  value = module.api.policy
}

output "api_role_arn" {
  value = module.api.role_arn
}

output "api_execution_arn" {
  value = module.api.execution_arn
}

output "api_app_origins" {
  value = module.api.app_origins
}

output "api_environment_keys" {
  value = module.api.environment_variable_keys
}

output "worker_policy" {
  value = module.worker.policy
}

output "table_arn" {
  value = module.table.table_arn
}

output "receipts_bucket_arn" {
  value = module.receipts.receipts_bucket_arn
}

output "thumbnails_bucket_arn" {
  value = module.receipts.thumbnails_bucket_arn
}

output "cors_origin" {
  value = local.allowed_origin
}
