output "alerts_topic_arn" {
  description = "ARN of the alerts topic (every alarm notifies it)."
  value       = aws_sns_topic.alerts.arn
}

output "metrics_namespace" {
  description = "CloudWatch namespace of the log-message metrics."
  value       = local.namespace
}

output "alarm_names" {
  description = "Names of all six alarms."
  value = concat(
    [aws_cloudwatch_metric_alarm.dlq.alarm_name, aws_cloudwatch_metric_alarm.queue_age.alarm_name],
    [for key in sort(keys(aws_cloudwatch_metric_alarm.log_messages)) : aws_cloudwatch_metric_alarm.log_messages[key].alarm_name]
  )
}
