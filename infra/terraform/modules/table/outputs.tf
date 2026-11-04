output "table_name" {
  description = "Name of the DynamoDB table."
  value       = aws_dynamodb_table.this.name
}

output "table_arn" {
  description = "ARN of the DynamoDB table (the IAM policies add /index/LSI1 for the index)."
  value       = aws_dynamodb_table.this.arn
}
