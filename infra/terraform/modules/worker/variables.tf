variable "name" {
  description = "Common name prefix, for example expense-tracker-dev; the function and its role are <name>-worker."
  type        = string

  # The role name is <name>-worker and IAM allows 64 characters.
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]*[a-z0-9]$", var.name)) && length(var.name) + 7 <= 64
    error_message = "name must be lowercase letters, digits and hyphens, and short enough for <name>-worker to fit 64 characters."
  }
}

variable "aws_region" {
  description = "Region of the environment (the log group ARN and the Bref layer ARNs are built from it)."
  type        = string
}

variable "aws_account_id" {
  description = "Id of the AWS account (12 digits); part of the worker's log group ARN in its policy."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}

variable "package_path" {
  description = "Path of the deployment zip (built by `make lambda-build`), shared with the API."
  type        = string
}

variable "package_sha256" {
  description = "SHA-256 of the deployment zip as 64 lower-case hex digits."
  type        = string
}

variable "php_layer_version" {
  description = "Version of the Bref php-85 layer in aws_region."
  type        = number
}

variable "gd_layer_version" {
  description = "Version of the gd-php-85 layer in aws_region (the worker makes thumbnails with GD)."
  type        = number
  nullable    = false

  validation {
    condition     = var.gd_layer_version >= 1
    error_message = "gd_layer_version must be a layer version (1 or higher); the worker cannot make thumbnails without GD."
  }
}

variable "reserved_concurrent_executions" {
  description = "Reserved concurrency of the worker; -1 leaves it unreserved."
  type        = number
  default     = -1
}

variable "table_name" {
  description = "Name of the DynamoDB table (DYNAMODB_TABLE)."
  type        = string
}

variable "table_arn" {
  description = "ARN of the DynamoDB table; the worker gets GetItem and UpdateItem on it."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:dynamodb:[a-z0-9-]+:[0-9]{12}:table/[A-Za-z0-9_.-]+$", var.table_arn))
    error_message = "table_arn must be the ARN of one DynamoDB table (no index, no wildcard)."
  }
}

variable "receipts_bucket" {
  description = "Name of the receipts bucket (RECEIPTS_BUCKET)."
  type        = string
}

variable "receipts_bucket_arn" {
  description = "ARN of the receipts bucket (the bucket itself, not its objects)."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:s3:::[a-z0-9][a-z0-9-]*[a-z0-9]$", var.receipts_bucket_arn))
    error_message = "receipts_bucket_arn must be the ARN of one bucket: no object path and no wildcard."
  }
}

variable "thumbnails_bucket" {
  description = "Name of the thumbnails bucket (THUMBNAILS_BUCKET)."
  type        = string
}

variable "thumbnails_bucket_arn" {
  description = "ARN of the thumbnails bucket (the bucket itself, not its objects)."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:s3:::[a-z0-9][a-z0-9-]*[a-z0-9]$", var.thumbnails_bucket_arn))
    error_message = "thumbnails_bucket_arn must be the ARN of one bucket: no object path and no wildcard."
  }
}

variable "queue_arn" {
  description = "ARN of the MAIN receipts queue: the event source, and the only queue the worker may read."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:sqs:[a-z0-9-]+:[0-9]{12}:[A-Za-z0-9_-]+$", var.queue_arn))
    error_message = "queue_arn must be the ARN of one SQS queue."
  }

  validation {
    condition     = !endswith(var.queue_arn, "-dlq")
    error_message = "queue_arn must be the main queue, never the dead-letter queue (a message there has failed three times already)."
  }
}
