variable "table_name" {
  description = "Name of the DynamoDB table, for example expense-tracker-dev."
  type        = string
}

# The ceiling of D15: with on-demand billing it is the only limit on spend. Reads beyond it are throttled, and the
# application maps that to 503 + Retry-After. -1 would remove the ceiling, so it is rejected.
variable "max_read_request_units" {
  description = "Ceiling of read request units per second (on-demand throughput limit). At least 1."
  type        = number

  validation {
    condition     = var.max_read_request_units >= 1 && var.max_read_request_units == floor(var.max_read_request_units)
    error_message = "max_read_request_units must be a positive integer (-1, which removes the ceiling, is not allowed)."
  }
}

variable "max_write_request_units" {
  description = "Ceiling of write request units per second (on-demand throughput limit). At least 1."
  type        = number

  validation {
    condition     = var.max_write_request_units >= 1 && var.max_write_request_units == floor(var.max_write_request_units)
    error_message = "max_write_request_units must be a positive integer (-1, which removes the ceiling, is not allowed)."
  }
}
