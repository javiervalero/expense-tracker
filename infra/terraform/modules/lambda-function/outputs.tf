output "function_name" {
  description = "Name of the function."
  value       = aws_lambda_function.this.function_name
}

output "function_arn" {
  description = "ARN of the function."
  value       = aws_lambda_function.this.arn
}

# Depends on the role alone, never on the inline policy: the receipts bucket policy takes this ARN (see main.tf).
output "role_arn" {
  description = "ARN of the function's role."
  value       = aws_iam_role.this.arn
}

output "role_name" {
  description = "Name of the function's role."
  value       = aws_iam_role.this.name
}

output "log_group_name" {
  description = "Name of the function's log group (/aws/lambda/<function name>)."
  value       = aws_cloudwatch_log_group.this.name
}

output "log_group_arn" {
  description = "ARN of the function's log group (without the :* suffix of its streams)."
  value       = aws_cloudwatch_log_group.this.arn
}

# The outputs below let a caller's test see what the function was given, since a test cannot reach resources inside a
# child module. They carry no secret today; the API (WP7) passes a sensitive value in its environment, and then
# environment_variables must be marked sensitive here.
output "runtime" {
  description = "Runtime of the function."
  value       = aws_lambda_function.this.runtime
}

output "architectures" {
  description = "Architectures of the function."
  value       = aws_lambda_function.this.architectures
}

output "handler" {
  description = "Handler of the function."
  value       = aws_lambda_function.this.handler
}

output "memory_size" {
  description = "Memory of the function in MB."
  value       = aws_lambda_function.this.memory_size
}

output "timeout" {
  description = "Timeout of the function in seconds."
  value       = aws_lambda_function.this.timeout
}

output "layers" {
  description = "Layer ARNs of the function, in order."
  value       = aws_lambda_function.this.layers
}

output "environment_variables" {
  description = "Environment of the function."
  value       = one(aws_lambda_function.this.environment).variables
}

output "policy" {
  description = "The inline policy attached to the role, as JSON."
  value       = aws_iam_role_policy.this.policy
}
