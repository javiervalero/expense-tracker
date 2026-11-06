variable "name" {
  description = "Common name prefix, for example expense-tracker-dev."
  type        = string

  # The longest bucket name is <name>-receipt-thumbnails-<8 hex> (20 + 8 characters around the name); S3 allows 63.
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]*[a-z0-9]$", var.name)) && length(var.name) + 28 <= 63
    error_message = "name must be lowercase letters, digits and hyphens, and short enough for the bucket names to stay within 63 characters."
  }
}

variable "bucket_suffix" {
  description = "The 8 hexadecimal characters appended to every bucket name (one random_id shared by all buckets of the environment)."
  type        = string

  validation {
    condition     = can(regex("^[0-9a-f]{8}$", var.bucket_suffix))
    error_message = "bucket_suffix must be exactly 8 lowercase hexadecimal characters."
  }
}

variable "aws_account_id" {
  description = "Id of the AWS account (12 digits); the queue policy accepts S3 notifications from this account only."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}

# Given by the caller because the distribution does not exist yet when this module is designed (D25: the CORS rule
# follows the distribution). Exactly one origin, https, no path and no wildcard.
variable "cors_allowed_origin" {
  description = "The single origin the receipts bucket accepts in CORS, for example https://d111111abcdef8.cloudfront.net."
  type        = string

  # The same rule as the API module's allowed_origin (the CORS origin and APP_ORIGINS must be the same string), and as
  # strict as ApiConfig (ORIGIN_PATTERN and isValidOrigin, https case): labels of 1 to 63 characters, none empty or
  # starting/ending with a hyphen; a port, when given, has no leading zero, is at most 65535 and is not 443.
  validation {
    condition     = can(regex("^https://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*(:[1-9][0-9]{0,4})?$", var.cors_allowed_origin))
    error_message = "cors_allowed_origin must be one https origin (lower-case DNS labels of 1 to 63 characters, optional port) without a path or a trailing slash, and not a wildcard."
  }

  validation {
    condition = alltrue([
      for p in flatten(regexall(":([0-9]+)$", var.cors_allowed_origin)) : tonumber(p) <= 65535 && tonumber(p) != 443
    ])
    error_message = "cors_allowed_origin must not carry the default https port 443, nor a port above 65535."
  }
}

variable "worker_role_arn" {
  description = "ARN of the worker Lambda role; the receipts bucket policy denies s3:PutObject to it (defence in depth on top of IAM)."
  type        = string

  validation {
    condition     = can(regex("^arn:aws:iam::[0-9]{12}:role/[A-Za-z0-9+=,.@_/-]+$", var.worker_role_arn))
    error_message = "worker_role_arn must be the ARN of one IAM role."
  }
}
