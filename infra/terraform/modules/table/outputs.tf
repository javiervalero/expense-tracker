output "table_name" {
  description = "Name of the DynamoDB table."
  value       = aws_dynamodb_table.this.name
}

output "max_read_request_units" {
  description = "Read ceiling of the table (D15), as set on the table's on_demand_throughput."
  value       = one(aws_dynamodb_table.this.on_demand_throughput).max_read_request_units
}

output "max_write_request_units" {
  description = "Write ceiling of the table (D15), as set on the table's on_demand_throughput."
  value       = one(aws_dynamodb_table.this.on_demand_throughput).max_write_request_units
}

output "table_arn" {
  description = "ARN of the DynamoDB table (the IAM policies add /index/LSI1 for the index)."
  value       = aws_dynamodb_table.this.arn
}
