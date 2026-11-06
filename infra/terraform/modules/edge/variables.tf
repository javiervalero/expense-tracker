variable "name" {
  description = "Common name prefix, for example expense-tracker-dev."
  type        = string

  # The site bucket is <name>-site-<8 hex> (6 + 8 characters around the name); S3 allows 63. The function and policy names
  # (<name>-spa-rewrite, <name>-assets) fit CloudFront's 64 characters for the same reason.
  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]*[a-z0-9]$", var.name)) && length(var.name) + 14 <= 63
    error_message = "name must be lowercase letters, digits and hyphens, and short enough for the site bucket name to stay within 63 characters."
  }
}

variable "aws_region" {
  description = "Region of the environment; the CSP hosts of the receipts and thumbnails buckets are built from it (Step 0 R4)."
  type        = string

  validation {
    condition     = can(regex("^[a-z]{2}(-[a-z]+)+-[0-9]$", var.aws_region))
    error_message = "aws_region must look like us-east-1 or eu-west-1."
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

# The CSP names the S3 hosts of the receipt buckets. Names, not computed attributes (D18), so the policy does not wait for
# the buckets and the host form is the one the SDK signs for. No dots: a dotted name would not be a single host label.
variable "receipts_bucket" {
  description = "Name of the receipts bucket (the browser uploads to, and opens receipts from, its S3 host)."
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$", var.receipts_bucket))
    error_message = "receipts_bucket must be a bucket name of 3 to 63 lowercase letters, digits and hyphens (no dots)."
  }
}

variable "thumbnails_bucket" {
  description = "Name of the thumbnails bucket (the browser loads thumbnails from its S3 host)."
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$", var.thumbnails_bucket))
    error_message = "thumbnails_bucket must be a bucket name of 3 to 63 lowercase letters, digits and hyphens (no dots)."
  }
}

variable "api_endpoint" {
  description = "Endpoint of the HTTP API (the api module's api_endpoint), for example https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com. The scheme is removed to build the origin domain."
  type        = string

  validation {
    condition     = can(regex("^https://[a-z0-9]+\\.execute-api\\.[a-z0-9-]+\\.amazonaws\\.com$", var.api_endpoint))
    error_message = "api_endpoint must be an https execute-api endpoint without a path or a trailing slash."
  }
}

# The ACTIVE slot (D16), picked by the composition. The rules are those of ApiConfig::SLOT_PATTERN (at least 32 visible
# ASCII characters: \x21, \x23-\x5B, \x5D-\x7E, so no space, no control character, no double quote and no backslash), plus
# two of this module's own: no comma, because ORIGIN_VERIFY_SECRETS is comma-separated and PHP would split such a slot, so
# the header could never match; and at most 1,783 characters, the CloudFront limit for a header value. The value is a
# secret: the variable is sensitive, only the sensitive output origin_verify_header_value carries it, and no message
# below quotes it.
variable "origin_verify_secret" {
  description = "Value of the X-Origin-Verify header CloudFront adds to every API request: the active slot of ORIGIN_VERIFY_SECRETS. Secret."
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^[\\x21\\x23-\\x2B\\x2D-\\x5B\\x5D-\\x7E]{32,}$", var.origin_verify_secret))
    error_message = "origin_verify_secret must be at least 32 visible ASCII characters with no space, comma, double quote or backslash."
  }

  # RE2 repeats at most 1000 times, so the upper bound is a separate rule.
  validation {
    condition     = length(var.origin_verify_secret) <= 1783
    error_message = "origin_verify_secret must be at most 1783 characters, the CloudFront limit for a header value."
  }
}

# D12 timeout chain: Lambda 25 s < integration 29 s < CloudFront origin read timeout. An HTTP API integration cannot wait
# longer than 30 s (Step 0 R2), so a longer read timeout would only hide a mismatch, and a shorter one (29 or less) would
# make CloudFront answer 504 before the gateway does. The api module owns the number (output
# cloudfront_origin_read_timeout_seconds) and the composition passes it here; this module accepts exactly that value.
variable "api_origin_read_timeout_seconds" {
  description = "origin_read_timeout of the API origin in seconds: the api module's cloudfront_origin_read_timeout_seconds (30)."
  type        = number

  validation {
    condition     = var.api_origin_read_timeout_seconds == 30
    error_message = "api_origin_read_timeout_seconds must be 30: longer than the 29 s integration timeout and no longer than the 30 s HTTP API limit (D12)."
  }
}
