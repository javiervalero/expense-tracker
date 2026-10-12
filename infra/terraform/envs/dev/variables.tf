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

# No default: the 12-digit account id goes in an uncommitted tfvars file or in CI variables, never in the repo.
variable "aws_account_id" {
  description = "Id of the AWS account the provider may act on (12 digits). Put the value in an uncommitted tfvars file or in CI variables, never in the repo."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}
