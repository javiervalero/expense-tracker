output "receipts_bucket" {
  description = "Name of the receipts bucket."
  value       = aws_s3_bucket.receipts.bucket
}

output "receipts_bucket_arn" {
  description = "ARN of the receipts bucket."
  value       = aws_s3_bucket.receipts.arn
}

output "thumbnails_bucket" {
  description = "Name of the thumbnails bucket."
  value       = aws_s3_bucket.thumbnails.bucket
}

output "thumbnails_bucket_arn" {
  description = "ARN of the thumbnails bucket."
  value       = aws_s3_bucket.thumbnails.arn
}

output "queue_arn" {
  description = "ARN of the receipts queue (the worker's event source)."
  value       = aws_sqs_queue.receipts.arn
}

output "queue_url" {
  description = "URL of the receipts queue."
  value       = aws_sqs_queue.receipts.url
}

output "dlq_arn" {
  description = "ARN of the dead-letter queue (the DLQ alarm)."
  value       = aws_sqs_queue.dlq.arn
}

output "dlq_url" {
  description = "URL of the dead-letter queue."
  value       = aws_sqs_queue.dlq.url
}

output "dlq_name" {
  description = "Name of the dead-letter queue (the CloudWatch QueueName dimension)."
  value       = aws_sqs_queue.dlq.name
}

output "queue_name" {
  description = "Name of the main receipts queue (the CloudWatch QueueName dimension of the queue-age alarm)."
  value       = aws_sqs_queue.receipts.name
}

# The outputs below let the composition's test see what the module was given, since a test cannot reach resources
# inside a child module.
output "visibility_timeout_seconds" {
  description = "Visibility timeout of the main receipts queue in seconds (at least 6 times the worker timeout, D8)."
  value       = aws_sqs_queue.receipts.visibility_timeout_seconds
}

output "cors_allowed_origins" {
  description = "The origins of the receipts bucket's CORS rule."
  value       = one(aws_s3_bucket_cors_configuration.receipts.cors_rule).allowed_origins
}
