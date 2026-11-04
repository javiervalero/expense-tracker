# Test fixture, not a deployable root: lambdas.tftest.hcl applies it under a mocked AWS provider to prove that
# modules/receipts and modules/worker wire together without a dependency cycle. The receipts bucket policy needs the
# worker role ARN, and the worker's permissions need the receipts bucket ARNs; that only works while the role is created
# apart from its permissions (modules/lambda-function/main.tf). The real composition (WP10) wires them the same way.

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

variable "table_name" {
  type = string
}

variable "table_arn" {
  type = string
}

variable "bucket_suffix" {
  type = string
}

variable "cors_allowed_origin" {
  type = string
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

module "receipts" {
  source = "../../../../../modules/receipts"

  name                = var.name
  bucket_suffix       = var.bucket_suffix
  aws_account_id      = var.aws_account_id
  cors_allowed_origin = var.cors_allowed_origin
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
  table_name            = var.table_name
  table_arn             = var.table_arn
  receipts_bucket       = module.receipts.receipts_bucket
  receipts_bucket_arn   = module.receipts.receipts_bucket_arn
  thumbnails_bucket     = module.receipts.thumbnails_bucket
  thumbnails_bucket_arn = module.receipts.thumbnails_bucket_arn
  queue_arn             = module.receipts.queue_arn
}

output "worker_role_arn" {
  value = module.worker.role_arn
}

output "worker_event_source_arn" {
  value = module.worker.event_source_arn
}

output "worker_policy" {
  value = module.worker.policy
}

output "queue_arn" {
  value = module.receipts.queue_arn
}

output "dlq_arn" {
  value = module.receipts.dlq_arn
}

output "receipts_bucket_arn" {
  value = module.receipts.receipts_bucket_arn
}
