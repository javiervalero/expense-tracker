# Alerts, alarms and the budget (Phase 7 plan D22 and section 4).
# One SNS topic with one email subscription. Every alarm notifies it when it fires and when it returns to OK.
# Six alarms: the dead-letter queue, the age of the oldest message on the main queue, and one per log message
# (metric filters on the API log group). Modules never configure providers.

locals {
  namespace = "ExpenseTracker/${var.environment}"

  # Every alarm: one 5-minute period is enough to decide, and a metric with no datapoints means nothing happened.
  alarm_period_seconds = 300

  # The main-queue alarm threshold. The worker handles one message per invocation, so a healthy queue drains in seconds.
  # The longest a message can legitimately wait is three tries of the 180-second visibility timeout (maxReceiveCount 3,
  # modules/receipts): 540 s, after which the message goes to the DLQ, which has its own alarm. 900 s (15 minutes) is
  # above that, so a poison message cannot trip it, yet it is early: the usual cause is a stuck or throttled worker, or
  # a flood of uploads by someone re-using a signed link (accepted risk "Direct-to-S3 spend", plan section 4), and the
  # Phase 8 runbook can react well before the budget email, which lags by hours.
  queue_age_threshold_seconds = 900

  # THE GUARDED LIST. Each message is a substring the PHP code logs; scripts/check-alarm-messages.sh reads the
  # `message = "..."` entries between the two marker comments and fails when one is no longer in backend/src. Keep one
  # entry per line, and do not put another `message = "..."` between the markers.
  # Each entry also carries its literal CloudWatch `pattern` (HCL-escaped: read it as the text after unescaping `\"` and
  # `\\`); scripts/tests/alarm-patterns.test.sh reads it from here and proves, on JSON lines, that a genuine line matches
  # and request-controlled text (path, Origin) does not. Keep `pattern` on the same line as its `message`.
  # Step 0 R6: the API alone logs all four, so they all sit on the API log group. `CorruptItem` is not a log message
  # of its own but the failure code that appears in the "unhandled exception" line (exceptionMessage "DynamoDB <op>
  # failed (CorruptItem)", then the "file" key) and in the `code` field of other API lines; its pattern has two
  # alternatives (a leading `?` means OR).
  # alarm-messages:begin
  alarm_messages = {
    receipt_cleanup_failed        = { message = "receipt cleanup failed", metric = "ReceiptCleanupFailed", pattern = "\"\\\"message\\\":\\\"receipt cleanup failed\\\"\"" }
    client_address_unavailable    = { message = "client address unavailable", metric = "ClientAddressUnavailable", pattern = "\"\\\"message\\\":\\\"client address unavailable\\\"\"" }
    login_throttle_cleanup_failed = { message = "login throttle clean-up failed after a successful login", metric = "LoginThrottleCleanupFailed", pattern = "\"\\\"message\\\":\\\"login throttle clean-up failed after a successful login\\\"\"" }
    corrupt_item                  = { message = "CorruptItem", metric = "CorruptItem", pattern = "?\"\\\"code\\\":\\\"CorruptItem\\\"\" ?\"(CorruptItem)\\\",\\\"file\\\"\"" }
  }
  # alarm-messages:end
}

# --- Notifications ---------------------------------------------------------------------------------------------------

# No customer-managed key: with the AWS-managed SNS key, CloudWatch alarms could not publish to the topic.
resource "aws_sns_topic" "alerts" {
  name = "${var.name}-alerts"
}

# The owner receives a confirmation email and must click the link once; until then nothing is delivered (Phase 8).
resource "aws_sns_topic_subscription" "email" {
  topic_arn = aws_sns_topic.alerts.arn
  protocol  = "email"
  endpoint  = var.alert_email
}

# --- Queue alarms ----------------------------------------------------------------------------------------------------

resource "aws_cloudwatch_metric_alarm" "dlq" {
  alarm_name          = "${var.name}-receipts-dlq-not-empty"
  alarm_description   = "A receipt message failed three times and sits in the dead-letter queue."
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateNumberOfMessagesVisible"
  dimensions          = { QueueName = var.dlq_name }
  statistic           = "Maximum"
  period              = local.alarm_period_seconds
  evaluation_periods  = 1
  threshold           = 0
  comparison_operator = "GreaterThanThreshold"
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  ok_actions          = [aws_sns_topic.alerts.arn]
}

resource "aws_cloudwatch_metric_alarm" "queue_age" {
  alarm_name          = "${var.name}-receipts-queue-age"
  alarm_description   = "The oldest message on the receipts queue is older than ${local.queue_age_threshold_seconds} seconds: the worker is stuck, or uploads are flooding the queue."
  namespace           = "AWS/SQS"
  metric_name         = "ApproximateAgeOfOldestMessage"
  dimensions          = { QueueName = var.receipts_queue_name }
  statistic           = "Maximum"
  period              = local.alarm_period_seconds
  evaluation_periods  = 1
  threshold           = local.queue_age_threshold_seconds
  comparison_operator = "GreaterThanThreshold"
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  ok_actions          = [aws_sns_topic.alerts.arn]
}

# --- Log-message alarms ----------------------------------------------------------------------------------------------

# The pattern is a quoted term that includes the JSON structure around the phrase (`"message":"<phrase>"`, quotes
# escaped inside the term), still a substring match, so a prefix before the JSON line does not matter. A bare quoted
# phrase would match anywhere in the line, and the API logs request text (the path in OriginVerifyMiddleware and
# CsrfMiddleware, the Origin header in CsrfMiddleware): anyone could forge an alarm by requesting `/CorruptItem`.
# JsonLogger writes every value through json_encode, which escapes each `"` inside a value as `\"`, so request text can
# never produce the raw `"message":"` or the raw `","file"` that the patterns need.
# Not documented by AWS: the page "Filter pattern syntax" shows quoted terms and `?` terms but not `\"` inside a quoted
# term, nor `?` with quoted terms. Phase 8 MUST run the read-only test-metric-filter on a real line of each kind before
# trusting these alarms.
# The value is 1 per matching line. There is no default_value: a filter without one publishes nothing while quiet, and
# the alarms read a missing datapoint as notBreaching, which is the same as 0 without a datapoint every minute.
resource "aws_cloudwatch_log_metric_filter" "messages" {
  for_each = local.alarm_messages

  name           = "${var.name}-${replace(each.key, "_", "-")}"
  log_group_name = var.api_log_group_name
  pattern        = each.value.pattern

  metric_transformation {
    name      = each.value.metric
    namespace = local.namespace
    value     = "1"
  }
}

resource "aws_cloudwatch_metric_alarm" "log_messages" {
  for_each = local.alarm_messages

  alarm_name          = "${var.name}-${replace(each.key, "_", "-")}"
  alarm_description   = "The API logged \"${each.value.message}\"."
  namespace           = local.namespace
  metric_name         = one(aws_cloudwatch_log_metric_filter.messages[each.key].metric_transformation).name
  statistic           = "Sum"
  period              = local.alarm_period_seconds
  evaluation_periods  = 1
  threshold           = 0
  comparison_operator = "GreaterThanThreshold"
  treat_missing_data  = "notBreaching"
  alarm_actions       = [aws_sns_topic.alerts.arn]
  ok_actions          = [aws_sns_topic.alerts.arn]
}

# --- Budget ----------------------------------------------------------------------------------------------------------

# Account-wide (no cost filter). The budget only NOTIFIES: it stops nothing, and AWS evaluates it a few times a day, so
# it lags by hours (accepted risk, plan section 4). The alarms above are the fast signals.
resource "aws_budgets_budget" "monthly" {
  name         = "${var.name}-monthly"
  budget_type  = "COST"
  time_unit    = "MONTHLY"
  limit_amount = tostring(var.monthly_budget_usd)
  limit_unit   = "USD"

  notification {
    notification_type          = "ACTUAL"
    threshold                  = 80
    threshold_type             = "PERCENTAGE"
    comparison_operator        = "GREATER_THAN"
    subscriber_email_addresses = [var.alert_email]
  }

  notification {
    notification_type          = "FORECASTED"
    threshold                  = 100
    threshold_type             = "PERCENTAGE"
    comparison_operator        = "GREATER_THAN"
    subscriber_email_addresses = [var.alert_email]
  }
}
