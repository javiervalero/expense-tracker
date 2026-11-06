output "function_name" {
  description = "Name of the worker function."
  value       = module.function.function_name
}

output "function_arn" {
  description = "ARN of the worker function."
  value       = module.function.function_arn
}

# Depends on the role alone, never on its permissions: the receipts bucket policy takes this ARN (modules/receipts).
output "role_arn" {
  description = "ARN of the worker role (the receipts bucket policy denies it PutObject)."
  value       = module.function.role_arn
}

output "role_name" {
  description = "Name of the worker role."
  value       = module.function.role_name
}

output "log_group_name" {
  description = "Name of the worker log group (the alarms' metric filters)."
  value       = module.function.log_group_name
}

output "event_source_arn" {
  description = "ARN of the queue the worker reads (the main receipts queue)."
  value       = aws_lambda_event_source_mapping.receipts.event_source_arn
}

output "policy" {
  description = "The inline policy attached to the worker role, as JSON."
  value       = module.function.policy
}

# The outputs below let the composition's test see what the worker was given, since a test cannot reach resources
# inside a child module.
output "timeout_seconds" {
  description = "Timeout of the worker function in seconds (the queue's visibility timeout is 6 times it, D8)."
  value       = module.function.timeout
}

output "environment_variable_keys" {
  description = "The keys of the worker function's environment, sorted."
  value       = nonsensitive(keys(module.function.environment_variables))
}

output "environment_variables" {
  description = "Environment of the worker function. Sensitive because the function module's output is (the API's holds a secret); a caller's test compares single values and never prints the map."
  value       = module.function.environment_variables
  sensitive   = true
}
