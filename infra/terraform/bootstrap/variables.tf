# No default for the region or the account: the owner chooses them explicitly (in an uncommitted tfvars file or with
# -var), so nothing can end up in an unintended region or account.
variable "aws_region" {
  description = "AWS region of the state bucket, for example us-east-1."
  type        = string

  validation {
    condition     = can(regex("^[a-z]{2}(-[a-z]+)+-[0-9]$", var.aws_region))
    error_message = "aws_region must look like an AWS region name, for example us-east-1."
  }
}

variable "aws_account_id" {
  description = "Id of the AWS account the provider may act on (12 digits). Put the value in an uncommitted tfvars file, never in the repo."
  type        = string

  validation {
    condition     = can(regex("^[0-9]{12}$", var.aws_account_id))
    error_message = "aws_account_id must be exactly 12 digits."
  }
}
