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
