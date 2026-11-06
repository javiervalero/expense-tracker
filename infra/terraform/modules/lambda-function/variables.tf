variable "function_name" {
  description = "Name of the function, for example expense-tracker-dev-worker. The role and the log group (/aws/lambda/<name>) are named after it."
  type        = string

  # Lambda allows 64 characters of letters, digits, hyphens and underscores; the role name has the same limit.
  validation {
    condition     = can(regex("^[A-Za-z0-9_-]{1,64}$", var.function_name))
    error_message = "function_name must be 1 to 64 letters, digits, hyphens or underscores."
  }
}

variable "handler" {
  description = "Handler of the function: the PHP file Bref loads, for example entrypoints/lambda/worker.php."
  type        = string

  validation {
    condition     = length(var.handler) > 0
    error_message = "handler must not be empty."
  }
}

variable "memory_size" {
  description = "Memory of the function in MB (CPU scales with it)."
  type        = number

  validation {
    condition     = var.memory_size >= 128 && var.memory_size <= 10240 && var.memory_size == floor(var.memory_size)
    error_message = "memory_size must be a whole number of MB from 128 to 10240."
  }
}

variable "timeout" {
  description = "Timeout of the function in seconds."
  type        = number

  validation {
    condition     = var.timeout >= 1 && var.timeout <= 900 && var.timeout == floor(var.timeout)
    error_message = "timeout must be a whole number of seconds from 1 to 900."
  }
}

variable "aws_region" {
  description = "Region of the function; the Bref layers are published per region."
  type        = string
}

variable "php_layer_version" {
  description = "Version of the Bref php-85 layer in aws_region (from bref-layers.auto.tfvars.json)."
  type        = number

  validation {
    condition     = var.php_layer_version >= 1 && var.php_layer_version == floor(var.php_layer_version)
    error_message = "php_layer_version must be a positive integer."
  }
}

variable "gd_layer_version" {
  description = "Version of the gd-php-85 layer (bref/extra-php-extensions) in aws_region, or null for a function that does not need GD."
  type        = number
  default     = null

  validation {
    condition     = var.gd_layer_version == null || (var.gd_layer_version >= 1 && var.gd_layer_version == floor(var.gd_layer_version))
    error_message = "gd_layer_version must be null or a positive integer."
  }
}

# BREF_PING_DISABLE is added by the module itself and cannot be overridden. D23 forbids the keys below in every
# function: AWS_* keys are reserved by Lambda or would carry credentials, an *_ENDPOINT key would redirect the SDK to a
# local stack, and APP_ENV is a local-stack switch.
variable "environment_variables" {
  description = "Environment of the function, without BREF_PING_DISABLE (added by the module). No AWS_* key, no *_ENDPOINT key and no APP_ENV."
  type        = map(string)

  validation {
    condition     = length([for k in keys(var.environment_variables) : k if startswith(k, "AWS_") || endswith(k, "_ENDPOINT") || k == "APP_ENV"]) == 0
    error_message = "environment_variables must not hold an AWS_* key, an *_ENDPOINT key or APP_ENV (plan D23)."
  }
}

# The caller builds it with jsonencode() (no aws_iam_policy_document, no managed policy; D2, D20). The checks below are a
# second barrier for a policy that is too broad:
#   - an Action must not contain "*" anywhere ("s3:*", "s3:Get*", "s3:*Object", "*");
#   - a Resource must not be "*" or end with ":::*" (every bucket of S3, or the same shape in any service) or ":::*/*"
#     (every object of every bucket).
# What stays valid on purpose: a log group's streams (arn:...:log-group:<name>:*, which ends with ":*" but not ":::*")
# and the objects under one bucket or prefix (arn:aws:s3:::<bucket>/<prefix>/*). A statement with no Resource (or with
# NotAction/NotResource instead) fails the whole check.
variable "policy_json" {
  description = "The inline IAM policy of the function's role, as JSON. No wildcard inside an action, and no resource that is \"*\" or ends with \":::*\"."
  type        = string

  validation {
    condition     = can(jsondecode(var.policy_json).Statement)
    error_message = "policy_json must be a JSON policy document with a Statement list."
  }

  validation {
    condition = try(alltrue([
      for s in jsondecode(var.policy_json).Statement :
      !anytrue([for a in flatten([s.Action]) : strcontains(a, "*")])
      && !anytrue([for r in flatten([s.Resource]) : r == "*" || endswith(r, ":::*") || endswith(r, ":::*/*")])
    ]), false)
    error_message = "policy_json must not grant a wildcard action (for example s3:* or s3:Get*), the resource \"*\", or a resource that ends with \":::*\" (for example arn:aws:s3:::*)."
  }
}

variable "package_path" {
  description = "Path of the deployment zip (built by `make lambda-build`)."
  type        = string

  validation {
    condition     = endswith(var.package_path, ".zip")
    error_message = "package_path must name a .zip file."
  }
}

variable "package_sha256" {
  description = "SHA-256 of the deployment zip as 64 lower-case hex digits (as written by `make lambda-build`); the module converts it to the base64 form Lambda compares."
  type        = string

  validation {
    condition     = can(regex("^[0-9a-f]{64}$", var.package_sha256))
    error_message = "package_sha256 must be exactly 64 lower-case hexadecimal digits."
  }
}

variable "reserved_concurrent_executions" {
  description = "Reserved concurrency of the function; -1 leaves it unreserved. Phase 8 reads the account quota before choosing a value."
  type        = number
  default     = -1

  validation {
    condition     = var.reserved_concurrent_executions >= -1 && var.reserved_concurrent_executions == floor(var.reserved_concurrent_executions)
    error_message = "reserved_concurrent_executions must be -1 or a whole number of at least 0."
  }
}
