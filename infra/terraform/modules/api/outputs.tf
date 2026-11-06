# No output carries the origin-verify secret (a test pins it): the environment is exposed by key only.

# The edge module (WP8) reads api_endpoint to build the API origin. It belongs to the API resource alone, so the
# distribution can be created before the function's environment (APP_ORIGINS) is known; see the ORDERING note in main.tf.
output "api_id" {
  description = "Id of the HTTP API."
  value       = aws_apigatewayv2_api.this.id
}

output "api_endpoint" {
  description = "Endpoint of the HTTP API, for example https://a1b2c3d4e5.execute-api.us-east-1.amazonaws.com (the CloudFront API origin without the scheme)."
  value       = aws_apigatewayv2_api.this.api_endpoint
}

output "execution_arn" {
  description = "Execution ARN of the HTTP API (the invoke permission is scoped by it)."
  value       = aws_apigatewayv2_api.this.execution_arn
}

output "function_name" {
  description = "Name of the API function."
  value       = module.function.function_name
}

output "function_arn" {
  description = "ARN of the API function."
  value       = module.function.function_arn
}

output "role_arn" {
  description = "ARN of the API function's role."
  value       = module.function.role_arn
}

output "log_group_name" {
  description = "Name of the API function's log group (the alarms' metric filters: receipt cleanup, client address, login throttle)."
  value       = module.function.log_group_name
}

output "access_log_group_name" {
  description = "Name of the HTTP API access log group."
  value       = aws_cloudwatch_log_group.access.name
}

output "policy" {
  description = "The inline policy attached to the API role, as JSON."
  value       = module.function.policy
}

output "app_origins" {
  description = "The value of APP_ORIGINS: the one browser origin the API accepts."
  value       = var.allowed_origin
}

# The keys are not secret; the values are (ORIGIN_VERIFY_SECRETS), so only the keys leave the module.
output "environment_variable_keys" {
  description = "The keys of the API function's environment, sorted."
  value       = nonsensitive(keys(module.function.environment_variables))
}

# The timeout chain (D12): Lambda < integration < CloudFront origin read timeout. The edge module takes the last one
# from here, so the three numbers cannot drift apart.
output "lambda_timeout_seconds" {
  description = "Timeout of the API function in seconds."
  value       = local.lambda_timeout_seconds
}

output "integration_timeout_milliseconds" {
  description = "Timeout of the API Gateway integration in milliseconds."
  value       = local.integration_timeout_milliseconds
}

# Sensitive: it holds ORIGIN_VERIFY_SECRETS. A caller's test compares single values as booleans and never prints the map.
output "environment_variables" {
  description = "Environment of the API function. Sensitive: it holds the origin-verify secrets."
  value       = module.function.environment_variables
  sensitive   = true
}

output "cloudfront_origin_read_timeout_seconds" {
  description = "The origin_read_timeout the CloudFront API origin must use, in seconds (longer than the integration timeout)."
  value       = local.cloudfront_origin_read_timeout_seconds
}
