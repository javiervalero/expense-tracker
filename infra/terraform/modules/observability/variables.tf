variable "name" {
  description = "Common name prefix, for example expense-tracker-dev."
  type        = string

  validation {
    condition     = can(regex("^[a-z0-9][a-z0-9-]*[a-z0-9]$", var.name)) && length(var.name) <= 100
    error_message = "name must be lowercase letters, digits and hyphens, at most 100 characters (alarm names allow 255)."
  }
}

variable "environment" {
  description = "Environment name, for example dev; the custom metrics go to the namespace ExpenseTracker/<environment>."
  type        = string

  validation {
    condition     = can(regex("^[a-z][a-z0-9-]{0,30}$", var.environment))
    error_message = "environment must be lowercase letters, digits and hyphens, starting with a letter (a CloudWatch namespace has no slash or space)."
  }
}

variable "api_log_group_name" {
  description = "Name of the API Lambda log group, for example /aws/lambda/expense-tracker-dev-api. Step 0 R6: the API alone logs these messages, so every metric filter sits here."
  type        = string

  validation {
    condition     = can(regex("^/aws/lambda/[A-Za-z0-9_-]+$", var.api_log_group_name))
    error_message = "api_log_group_name must be a Lambda log group name (/aws/lambda/<function>)."
  }
}

variable "receipts_queue_name" {
  description = "Name of the main receipts queue (the QueueName dimension of the queue-age alarm)."
  type        = string

  validation {
    condition     = can(regex("^[A-Za-z0-9_-]{1,80}$", var.receipts_queue_name))
    error_message = "receipts_queue_name must be a queue name (letters, digits, hyphens and underscores, at most 80)."
  }
}

variable "dlq_name" {
  description = "Name of the dead-letter queue (the QueueName dimension of the DLQ alarm)."
  type        = string

  validation {
    condition     = can(regex("^[A-Za-z0-9_-]{1,80}$", var.dlq_name))
    error_message = "dlq_name must be a queue name (letters, digits, hyphens and underscores, at most 80)."
  }
}

# Never committed: the real address lives in the git-ignored terraform.tfvars (D24); tests use alerts@example.com.
variable "alert_email" {
  description = "Address that receives the alarms and the budget notifications. SNS emails a confirmation link the owner must click once."
  type        = string
  sensitive   = true

  validation {
    condition     = can(regex("^[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(\\.[A-Za-z0-9-]+)*\\.[A-Za-z]{2,}$", var.alert_email)) && length(var.alert_email) <= 254
    error_message = "alert_email must be a plausible email address (name@domain.tld, no spaces)."
  }
}

variable "monthly_budget_usd" {
  description = "Monthly cost budget for the whole account, in US dollars. The budget only notifies; it does not stop anything."
  type        = number

  validation {
    condition     = var.monthly_budget_usd > 0
    error_message = "monthly_budget_usd must be greater than 0."
  }
}
