variable "name" {
  description = "Common name prefix, for example expense-tracker-dev; the HTTP API is named <name>, the function and its role <name>-api, and the access log group is /aws/apigateway/<name>."
  type        = string

  # The role name is <name>-api and IAM allows 64 characters.
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]*[a-z0-9]$", var.name)) && length(var.name) + 4 <= 64
    error_message = "name must be lowercase letters, digits and hyphens, and short enough for <name>-api to fit 64 characters."
  }
}

variable "aws_region" {
  description = "Region of the environment (the log group ARN and the Bref layer ARNs are built from it)."
  type        = string
}

variable "aws_account_id" {
  description = "Id of the AWS account (12 digits); part of the API function's log group ARN in its policy."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}

variable "package_path" {
  description = "Path of the deployment zip (built by `make lambda-build`), shared with the worker."
  type        = string
}

variable "package_sha256" {
  description = "SHA-256 of the deployment zip as 64 lower-case hex digits."
  type        = string
}

variable "php_layer_version" {
  description = "Version of the Bref php-85 layer in aws_region. The API has no gd layer: it only signs URLs."
  type        = number
}

variable "reserved_concurrent_executions" {
  description = "Reserved concurrency of the API function; -1 leaves it unreserved. Phase 8 reads the account quota before choosing a value."
  type        = number
  default     = -1
}

variable "table_name" {
  description = "Name of the DynamoDB table (DYNAMODB_TABLE)."
  type        = string
}

variable "table_arn" {
  description = "ARN of the DynamoDB table; the policy adds /index/LSI1 for the index."
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

# ORIGIN_VERIFY_SECRETS (ApiConfig): one or two comma-separated slots. A slot is at least 32 visible ASCII characters
# (SLOT_PATTERN: \x21, \x23-\x5B, \x5D-\x7E, so no space, no control character, no double quote and no backslash). PHP also
# refuses a repeated slot and a slot that contains the other. The value is a secret: the variable is sensitive, only the
# sensitive output environment_variables carries it, and no message below quotes it. The random_password resources and the CloudFront header are WP8/WP10's.
variable "origin_verify_secrets" {
  description = "Value of ORIGIN_VERIFY_SECRETS: the accepted X-Origin-Verify slots, comma-separated (1 or 2 slots, each at least 32 visible ASCII characters). Secret."
  type        = string
  sensitive   = true

  validation {
    condition = alltrue([
      for slot in split(",", var.origin_verify_secrets) : can(regex("^[\\x21\\x23-\\x5B\\x5D-\\x7E]{32,}$", slot))
    ])
    error_message = "origin_verify_secrets must hold comma-separated slots, each at least 32 visible ASCII characters with no space, double quote or backslash."
  }

  validation {
    condition     = length(split(",", var.origin_verify_secrets)) <= 2
    error_message = "origin_verify_secrets must hold at most 2 slots."
  }

  validation {
    condition = length(split(",", var.origin_verify_secrets)) != 2 ? true : (
      split(",", var.origin_verify_secrets)[0] != split(",", var.origin_verify_secrets)[1]
      && !strcontains(split(",", var.origin_verify_secrets)[0], split(",", var.origin_verify_secrets)[1])
      && !strcontains(split(",", var.origin_verify_secrets)[1], split(",", var.origin_verify_secrets)[0])
    )
    error_message = "The two slots of origin_verify_secrets must differ, and neither may contain the other."
  }
}

# Given by the caller because the distribution does not exist yet when this module is designed (D25: the Lambda's
# environment follows the distribution). The same rule as the receipts module's cors_allowed_origin: exactly one origin,
# https, no path, no trailing slash and no wildcard.
variable "allowed_origin" {
  description = "The single browser origin the API accepts for state-changing calls (APP_ORIGINS), for example https://d111111abcdef8.cloudfront.net."
  type        = string

  # Exactly as strict as ApiConfig (ORIGIN_PATTERN and isValidOrigin, https case), and identical to the receipts module's
  # cors_allowed_origin: labels of 1 to 63 characters, none empty or starting/ending with a hyphen; a port, when given,
  # has no leading zero, is at most 65535 and is not 443 (a browser never sends the default port).
  validation {
    condition     = can(regex("^https://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*(:[1-9][0-9]{0,4})?$", var.allowed_origin))
    error_message = "allowed_origin must be one https origin (lower-case DNS labels of 1 to 63 characters, optional port) without a path or a trailing slash, and not a wildcard."
  }

  validation {
    condition = alltrue([
      for p in flatten(regexall(":([0-9]+)$", var.allowed_origin)) : tonumber(p) <= 65535 && tonumber(p) != 443
    ])
    error_message = "allowed_origin must not carry the default https port 443, nor a port above 65535."
  }
}
