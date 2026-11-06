# Offline tests of modules/observability (D22 of the Phase 7 plan): the alerts topic and its email subscription, the
# six alarms (the dead-letter queue, the age of the oldest message on the main queue, and four log-message filters on
# the API log group) and the monthly budget. The AWS provider is mocked, so nothing here can reach an account
# (scripts/check-tftest-mocks.sh enforces the mock in every test file).
# `command = apply`: under the mock, computed ARNs are random strings, so the topic ARN is pinned below with
# override_resource and every action list is compared with that very value.
# The email is a placeholder on the reserved example.com domain; the real one lives in the git-ignored tfvars.
# Not assertable here: that the filter patterns still match real log lines (Phase 8 runs the read-only
# `test-metric-filter`) and that the PHP messages still exist (scripts/check-alarm-messages.sh).
mock_provider "aws" {}

variables {
  name                = "expense-tracker-dev"
  environment         = "dev"
  api_log_group_name  = "/aws/lambda/expense-tracker-dev-api"
  receipts_queue_name = "expense-tracker-dev-receipts"
  dlq_name            = "expense-tracker-dev-receipts-dlq"
  alert_email         = "alerts@example.com"
  monthly_budget_usd  = 5
}

# The provider validates that alarm actions are ARNs.
override_resource {
  target = aws_sns_topic.alerts
  values = {
    arn = "arn:aws:sns:us-east-1:123456789012:expense-tracker-dev-alerts"
  }
}

run "the_topic_is_named_after_the_environment" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_sns_topic.alerts.name == "expense-tracker-dev-alerts"
    error_message = "The topic must be <name>-alerts."
  }
}

run "the_alert_email_is_subscribed_by_email_protocol" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_sns_topic_subscription.email.protocol == "email"
    error_message = "The subscription must use the email protocol."
  }

  assert {
    condition     = aws_sns_topic_subscription.email.endpoint == var.alert_email && var.alert_email == "alerts@example.com"
    error_message = "The subscription endpoint must be exactly the alert_email input."
  }

  assert {
    condition     = aws_sns_topic_subscription.email.topic_arn == aws_sns_topic.alerts.arn
    error_message = "The subscription must be on the alerts topic."
  }
}

run "there_are_six_alarms_and_four_log_filters" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = 2 + length(aws_cloudwatch_metric_alarm.log_messages) == 6
    error_message = "There must be 6 alarms: the DLQ, the queue age, and one per log message."
  }

  assert {
    condition     = length(aws_cloudwatch_log_metric_filter.messages) == 4 && length(aws_cloudwatch_metric_alarm.log_messages) == 4
    error_message = "There must be 4 log metric filters, each with its own alarm."
  }

  assert {
    condition     = toset(keys(aws_cloudwatch_log_metric_filter.messages)) == toset(keys(aws_cloudwatch_metric_alarm.log_messages))
    error_message = "Every filter must have its alarm and the other way round."
  }
}

run "every_alarm_has_the_same_period_evaluation_and_missing_data_handling" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      a.period == 300 && a.evaluation_periods == 1
    ])
    error_message = "Every alarm must have period 300 and 1 evaluation period."
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      a.treat_missing_data == "notBreaching"
    ])
    error_message = "Every alarm must treat missing data as notBreaching (a quiet metric is a healthy one)."
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      a.comparison_operator == "GreaterThanThreshold"
    ])
    error_message = "Every alarm must fire when the value is greater than the threshold."
  }
}

run "every_alarm_notifies_the_topic_on_alarm_and_on_ok" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      a.alarm_actions == toset([aws_sns_topic.alerts.arn]) && aws_sns_topic.alerts.arn == "arn:aws:sns:us-east-1:123456789012:expense-tracker-dev-alerts"
    ])
    error_message = "Every alarm must notify exactly the alerts topic when it fires."
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      a.ok_actions == toset([aws_sns_topic.alerts.arn])
    ])
    error_message = "Every alarm must notify exactly the alerts topic when it returns to OK."
  }

  assert {
    condition = alltrue([
      for a in concat([aws_cloudwatch_metric_alarm.dlq, aws_cloudwatch_metric_alarm.queue_age], values(aws_cloudwatch_metric_alarm.log_messages)) :
      length(coalesce(a.insufficient_data_actions, toset([]))) == 0
    ])
    error_message = "No alarm may notify on INSUFFICIENT_DATA (a quiet metric would page every time)."
  }
}

run "the_dlq_alarm_fires_on_any_visible_message" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.dlq.namespace == "AWS/SQS" && aws_cloudwatch_metric_alarm.dlq.metric_name == "ApproximateNumberOfMessagesVisible"
    error_message = "The DLQ alarm must watch AWS/SQS ApproximateNumberOfMessagesVisible."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.dlq.dimensions == tomap({ QueueName = "expense-tracker-dev-receipts-dlq" })
    error_message = "The DLQ alarm must have the single dimension QueueName = the DLQ name."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.dlq.threshold == 0 && aws_cloudwatch_metric_alarm.dlq.statistic == "Maximum"
    error_message = "The DLQ alarm must fire above 0 (Maximum), so one dead letter is enough."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.dlq.alarm_name == "expense-tracker-dev-receipts-dlq-not-empty"
    error_message = "The DLQ alarm name must be <name>-receipts-dlq-not-empty."
  }
}

run "the_queue_age_alarm_watches_the_main_queue_with_a_threshold_of_fifteen_minutes" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.queue_age.namespace == "AWS/SQS" && aws_cloudwatch_metric_alarm.queue_age.metric_name == "ApproximateAgeOfOldestMessage"
    error_message = "The queue-age alarm must watch AWS/SQS ApproximateAgeOfOldestMessage."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.queue_age.dimensions == tomap({ QueueName = "expense-tracker-dev-receipts" })
    error_message = "The queue-age alarm must have the single dimension QueueName = the MAIN queue name (not the DLQ)."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.queue_age.threshold == 900 && aws_cloudwatch_metric_alarm.queue_age.statistic == "Maximum"
    error_message = "The queue-age alarm must fire above 900 seconds (Maximum)."
  }

  assert {
    condition     = aws_cloudwatch_metric_alarm.queue_age.alarm_name == "expense-tracker-dev-receipts-queue-age"
    error_message = "The queue-age alarm name must be <name>-receipts-queue-age."
  }
}

run "the_filter_patterns_are_the_quoted_messages_on_the_api_log_group" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_cloudwatch_log_metric_filter.messages["receipt_cleanup_failed"].pattern == "\"\\\"message\\\":\\\"receipt cleanup failed\\\"\""
    error_message = "The receipt-cleanup filter must be anchored on the JSON key: \"\\\"message\\\":\\\"receipt cleanup failed\\\"\"."
  }

  assert {
    condition     = aws_cloudwatch_log_metric_filter.messages["client_address_unavailable"].pattern == "\"\\\"message\\\":\\\"client address unavailable\\\"\""
    error_message = "The client-address filter must be anchored on the JSON key."
  }

  assert {
    condition     = aws_cloudwatch_log_metric_filter.messages["login_throttle_cleanup_failed"].pattern == "\"\\\"message\\\":\\\"login throttle clean-up failed after a successful login\\\"\""
    error_message = "The login-throttle filter must be anchored on the JSON key."
  }

  # Two alternatives (a leading ?): the end of the exceptionMessage value followed by the "file" key, and the "code" field.
  assert {
    condition     = aws_cloudwatch_log_metric_filter.messages["corrupt_item"].pattern == "?\"\\\"code\\\":\\\"CorruptItem\\\"\" ?\"(CorruptItem)\\\",\\\"file\\\"\""
    error_message = "The corrupt-item filter must be anchored on the code field or on the end of exceptionMessage followed by the file key."
  }

  # Request text (path, origin) is logged through json_encode, which escapes every quote: a pattern that needs a raw
  # quote next to the phrase cannot be forged. A bare quoted phrase is a substring match anywhere in the line.
  assert {
    condition = alltrue([
      for f in values(aws_cloudwatch_log_metric_filter.messages) :
      strcontains(f.pattern, "\\\"")
    ])
    error_message = "Every filter pattern must carry an escaped quote (a JSON anchor), never a bare quoted phrase."
  }

  assert {
    condition = alltrue([
      for f in values(aws_cloudwatch_log_metric_filter.messages) :
      f.log_group_name == "/aws/lambda/expense-tracker-dev-api"
    ])
    error_message = "Every filter must sit on the API log group (Step 0 R6: the worker does not log these messages)."
  }
}

run "the_filters_emit_one_per_match_in_the_environment_namespace" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = alltrue([
      for f in values(aws_cloudwatch_log_metric_filter.messages) :
      one(f.metric_transformation).namespace == "ExpenseTracker/dev" && one(f.metric_transformation).value == "1"
    ])
    error_message = "Every filter must emit the value 1 in the namespace ExpenseTracker/<environment>."
  }

  assert {
    condition = alltrue([
      for f in values(aws_cloudwatch_log_metric_filter.messages) :
      one(f.metric_transformation).default_value == null || one(f.metric_transformation).default_value == ""
    ])
    error_message = "The filters must not set a default value (no datapoints while quiet; notBreaching covers it)."
  }

  assert {
    condition = toset([for f in values(aws_cloudwatch_log_metric_filter.messages) : one(f.metric_transformation).name]) == toset([
      "ReceiptCleanupFailed", "ClientAddressUnavailable", "LoginThrottleCleanupFailed", "CorruptItem"
    ])
    error_message = "The four metrics must have their planned names."
  }
}

run "each_log_alarm_watches_the_metric_of_its_filter_with_threshold_zero" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = alltrue([
      for k, a in aws_cloudwatch_metric_alarm.log_messages :
      a.namespace == "ExpenseTracker/dev" && a.metric_name == one(aws_cloudwatch_log_metric_filter.messages[k].metric_transformation).name
    ])
    error_message = "Each log alarm must watch the metric its own filter emits, in ExpenseTracker/dev."
  }

  assert {
    condition = alltrue([
      for a in values(aws_cloudwatch_metric_alarm.log_messages) :
      a.threshold == 0 && a.statistic == "Sum"
    ])
    error_message = "Each log alarm must fire above 0 (Sum), so one logged message is enough."
  }

  assert {
    condition     = length(distinct([for a in values(aws_cloudwatch_metric_alarm.log_messages) : a.alarm_name])) == 4
    error_message = "The four log alarms must have distinct names."
  }
}

run "the_namespace_follows_the_environment_input" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  variables {
    environment = "staging"
  }

  assert {
    condition     = alltrue([for f in values(aws_cloudwatch_log_metric_filter.messages) : one(f.metric_transformation).namespace == "ExpenseTracker/staging"])
    error_message = "The namespace must be built from the environment input."
  }
}

run "the_budget_is_account_wide_monthly_cost_in_dollars" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition     = aws_budgets_budget.monthly.name == "expense-tracker-dev-monthly"
    error_message = "The budget must be <name>-monthly."
  }

  assert {
    condition     = aws_budgets_budget.monthly.budget_type == "COST" && aws_budgets_budget.monthly.time_unit == "MONTHLY"
    error_message = "The budget must be a COST budget with a MONTHLY time unit."
  }

  assert {
    condition     = aws_budgets_budget.monthly.limit_amount == "5" && aws_budgets_budget.monthly.limit_unit == "USD"
    error_message = "The budget limit must be monthly_budget_usd, in USD."
  }

  assert {
    condition     = length(aws_budgets_budget.monthly.cost_filter) == 0 && length(aws_budgets_budget.monthly.filter_expression) == 0
    error_message = "The budget must be account-wide: no cost filter."
  }
}

run "the_budget_notifies_at_eighty_percent_actual_and_the_whole_limit_forecasted" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = toset([
      for n in aws_budgets_budget.monthly.notification :
      "${n.notification_type}/${n.threshold}/${n.threshold_type}/${n.comparison_operator}"
      ]) == toset([
      "ACTUAL/80/PERCENTAGE/GREATER_THAN",
      "FORECASTED/100/PERCENTAGE/GREATER_THAN",
    ])
    error_message = "The budget must have exactly: 80% ACTUAL and 100% FORECASTED, PERCENTAGE, GREATER_THAN."
  }

  assert {
    condition = alltrue([
      for n in aws_budgets_budget.monthly.notification :
      n.subscriber_email_addresses == toset(["alerts@example.com"]) && length(coalesce(n.subscriber_sns_topic_arns, toset([]))) == 0
    ])
    error_message = "Each budget notification must go to the alert email, and only to it."
  }
}

run "the_budget_limit_follows_the_input" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  variables {
    monthly_budget_usd = 12.5
  }

  assert {
    condition     = aws_budgets_budget.monthly.limit_amount == "12.5"
    error_message = "The budget limit must follow monthly_budget_usd."
  }
}

run "no_resource_carries_an_email_other_than_the_input" {
  command = apply

  module {
    source = "../../modules/observability"
  }

  assert {
    condition = alltrue([
      endswith(aws_sns_topic_subscription.email.endpoint, "@example.com"),
      alltrue([for n in aws_budgets_budget.monthly.notification : alltrue([for e in n.subscriber_email_addresses : endswith(e, "@example.com")])]),
    ])
    error_message = "Only the placeholder address may appear in the planned resources."
  }
}

run "an_alert_email_without_an_at_sign_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    alert_email = "alerts.example.com"
  }

  expect_failures = [var.alert_email]
}

run "an_alert_email_with_a_space_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    alert_email = "alerts @example.com"
  }

  expect_failures = [var.alert_email]
}

run "an_alert_email_without_a_domain_dot_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    alert_email = "alerts@localhost"
  }

  expect_failures = [var.alert_email]
}

run "an_empty_alert_email_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    alert_email = ""
  }

  expect_failures = [var.alert_email]
}

run "a_zero_budget_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    monthly_budget_usd = 0
  }

  expect_failures = [var.monthly_budget_usd]
}

run "a_negative_budget_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    monthly_budget_usd = -1
  }

  expect_failures = [var.monthly_budget_usd]
}

run "an_environment_with_a_slash_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    environment = "dev/x"
  }

  expect_failures = [var.environment]
}

run "a_name_with_capitals_is_refused" {
  command = plan

  module {
    source = "../../modules/observability"
  }

  variables {
    name = "Expense-Tracker"
  }

  expect_failures = [var.name]
}
