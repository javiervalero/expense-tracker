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
