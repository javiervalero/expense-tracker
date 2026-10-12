# Terraform modules

Empty in Phase 0. Phase 7 adds the reusable modules that `envs/dev` composes (ADR 0009, ADR 0012), one directory per
concern, each with `main.tf`, `variables.tf` and `outputs.tf`:

- `api`: API Gateway HTTP API and the PHP-FPM Lambda (Bref layer version taken from `composer.lock`, x86_64).
- `worker`: the SQS-triggered receipt worker Lambda, its queue and dead-letter queue.
- `data`: the DynamoDB table and the S3 receipts bucket with its event notification.
- `frontend`: the S3 bucket and CloudFront distribution that serve the Vue app and route `/api/*`.
- `observability`: CloudWatch log groups and alarms, and the AWS Budgets alert.

Every resource is tagged through the provider's `default_tags`. Modules never configure providers or backends.
