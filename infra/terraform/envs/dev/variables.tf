# No default for the region: the owner chooses it explicitly (in a tfvars file or -var), so nothing can end up in
# an unintended region.
variable "aws_region" {
  description = "AWS region where the environment is deployed, for example eu-west-1."
  type        = string

  validation {
    condition     = can(regex("^[a-z]{2}(-[a-z]+)+-[0-9]$", var.aws_region))
    error_message = "aws_region must look like an AWS region name, for example eu-west-1."
  }
}

variable "environment" {
  description = "Name of the environment; used in the environment tag and in resource names."
  type        = string
  default     = "dev"

  validation {
    condition     = contains(["dev"], var.environment)
    error_message = "environment must be \"dev\" (the only environment so far)."
  }
}

# Bref layer versions per region. Both maps come from the generated, committed bref-layers.auto.tfvars.json
# (`make tf-layers`, read from composer's installed layers.json files; D10). A region Bref does not publish to fails here,
# at validation, instead of at deploy.
variable "bref_php_layer_versions" {
  description = "Version of the Bref php-85 layer per region (generated file bref-layers.auto.tfvars.json)."
  type        = map(number)

  validation {
    condition     = alltrue([for v in values(var.bref_php_layer_versions) : v >= 1 && v == floor(v)])
    error_message = "Every bref_php_layer_versions value must be a positive integer."
  }

  validation {
    condition     = contains(keys(var.bref_php_layer_versions), var.aws_region)
    error_message = "bref_php_layer_versions has no entry for aws_region: Bref does not publish the php-85 layer there."
  }
}

variable "bref_gd_layer_versions" {
  description = "Version of the gd-php-85 layer (bref/extra-php-extensions) per region (generated file bref-layers.auto.tfvars.json)."
  type        = map(number)

  validation {
    condition     = alltrue([for v in values(var.bref_gd_layer_versions) : v >= 1 && v == floor(v)])
    error_message = "Every bref_gd_layer_versions value must be a positive integer."
  }

  validation {
    condition     = contains(keys(var.bref_gd_layer_versions), var.aws_region)
    error_message = "bref_gd_layer_versions has no entry for aws_region: the gd-php-85 layer is not published there."
  }
}

# The Lambda package, shared by both functions. Both values come from the generated, git-ignored
# package.auto.tfvars.json (`make lambda-build`, D11); there is no default, so a plan without a built package fails
# here instead of deploying nothing.
variable "lambda_package_path" {
  description = "Path of the Lambda deployment zip, relative to this directory (generated file package.auto.tfvars.json)."
  type        = string

  validation {
    condition     = endswith(var.lambda_package_path, ".zip") && length(var.lambda_package_path) > length(".zip")
    error_message = "lambda_package_path must name a .zip file (run `make lambda-build`)."
  }
}

variable "lambda_package_sha256" {
  description = "SHA-256 of the Lambda deployment zip as 64 lower-case hex digits (generated file package.auto.tfvars.json)."
  type        = string

  validation {
    condition     = can(regex("^[0-9a-f]{64}$", var.lambda_package_sha256))
    error_message = "lambda_package_sha256 must be exactly 64 lower-case hexadecimal digits (run `make lambda-build`)."
  }
}

# No default: the owner picks the slot explicitly. Rotation (ADR 0012) flips it after the other slot is renewed.
variable "origin_verify_active_slot" {
  description = "Which origin-verify secret slot CloudFront sends: \"a\" or \"b\"."
  type        = string

  validation {
    condition     = contains(["a", "b"], var.origin_verify_active_slot)
    error_message = "origin_verify_active_slot must be \"a\" or \"b\"."
  }
}

# Changing a rotation value renews that slot's secret (random_password keepers; ADR 0012). Any non-empty text works, for
# example a date.
variable "origin_verify_rotation_a" {
  description = "Rotation marker of origin-verify slot A: change the value to generate a new secret for the slot."
  type        = string
  default     = "1"

  validation {
    condition     = length(var.origin_verify_rotation_a) > 0
    error_message = "origin_verify_rotation_a must not be empty."
  }
}

variable "origin_verify_rotation_b" {
  description = "Rotation marker of origin-verify slot B: change the value to generate a new secret for the slot."
  type        = string
  default     = "1"

  validation {
    condition     = length(var.origin_verify_rotation_b) > 0
    error_message = "origin_verify_rotation_b must not be empty."
  }
}

# No default: the address goes in an uncommitted tfvars file, never in the repo (D22, D24). The module validates its shape.
variable "alert_email" {
  description = "Address that receives the alarms and the budget notifications. Put the value in an uncommitted tfvars file."
  type        = string
  sensitive   = true
}

variable "monthly_budget_usd" {
  description = "Monthly cost budget for the whole account, in US dollars (owner decision 11: 5)."
  type        = number
  default     = 5
}

# -1 leaves the function unreserved. Phase 8 reads the account's Lambda concurrency quota before choosing a value; a new
# account's quota can be too low for any reservation (R6), and an API flood could starve the worker.
variable "api_reserved_concurrent_executions" {
  description = "Reserved concurrency of the API function; -1 leaves it unreserved."
  type        = number
  default     = -1
}

variable "worker_reserved_concurrent_executions" {
  description = "Reserved concurrency of the worker function; -1 leaves it unreserved."
  type        = number
  default     = -1
}

# No default: the 12-digit account id goes in an uncommitted tfvars file or in CI variables, never in the repo.
variable "aws_account_id" {
  description = "Id of the AWS account the provider may act on (12 digits). Put the value in an uncommitted tfvars file or in CI variables, never in the repo."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}
