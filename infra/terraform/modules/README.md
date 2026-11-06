# Terraform modules

A **module** is a reusable folder of Terraform code. Terraform describes AWS resources as code, and a module groups
one part of the system: it takes inputs (variables) and returns values (outputs) for other modules to use. Each module
has `main.tf`, `variables.tf` and `outputs.tf` (ADR 0009, ADR 0012, decision D3 in `docs/plans/phase-7-infrastructure.md`).

Modules never configure a **provider**, the plugin that talks to AWS and holds the region and account. Only
`envs/dev` sets the provider. The dev environment in `envs/dev` composes the modules: it calls each one, passes values
between them, and sets names and sizes.

| Module | What it creates | Needs from other modules | Exposes |
|---|---|---|---|
| `table` | The DynamoDB table (the database), its index, expiry (TTL) and a ceiling on reads and writes per second | Nothing (the ceiling values come from `envs/dev`) | `table_name`, `table_arn` |
| `receipts` | Two S3 buckets (receipts and thumbnails), the SQS queue that receives upload events, its dead-letter queue (DLQ, for messages that keep failing), and the S3 notification that feeds the queue | The worker role ARN (a bucket policy stops that role writing receipts); the CloudFront origin for the CORS rule | Bucket names and ARNs, queue and DLQ names, ARNs and URLs, `visibility_timeout_seconds`, `cors_allowed_origins` |
| `lambda-function` | One Lambda function (code that runs on request, with no server to manage), its IAM role (the permissions it holds), an inline policy and its log group. Used by `api` and `worker` | The caller supplies the layer versions, the zip, the environment and the policy | Function name and ARN, role ARN and name, log group, runtime, memory, timeout, layers, environment, policy |
| `worker` | The receipt worker: a `lambda-function` plus the event source mapping that reads the main queue (one message at a time) | The table, the buckets and their ARNs, the queue ARN, layer versions and the zip | Function name and ARN, role ARN, log group, the queue it reads, timeout, environment keys |
| `api` | The HTTP API (API Gateway, the public entry point) with its stage, routes and throttles, the integration with the API Lambda, the permission that lets API Gateway call it, the access log group, and the API `lambda-function` | The table, the buckets, the zip, the origin-verify secrets (a shared secret that CloudFront sends and PHP checks) and the allowed browser origin | `api_endpoint`, `execution_arn`, log group names, timeouts, `cloudfront_origin_read_timeout_seconds`, environment keys |
| `edge` | The CloudFront distribution (the CDN in front of everything), the site S3 bucket, the response-header and cache policies, and the function for single-page app links | The receipt and thumbnail bucket names, the API endpoint, the active origin-verify secret, the API origin read timeout | `domain_name`, `distribution_id`, `site_bucket`, `api_origin_domain`, `content_security_policy`, `receipts_host`, `thumbnails_host` |
| `observability` | The SNS topic that sends alerts by email, metric filters on log messages, six alarms (the DLQ, the queue age and four log messages) and the monthly budget | The API log group name, the queue names, the alert email and the budget amount | `alerts_topic_arn`, `metrics_namespace`, `alarm_names` |

**How they connect** (`envs/dev/main.tf`). `table` is created first; its name and ARN go to `receipts`, `worker` and
`api`. `receipts` gives its buckets and queue to `worker` and `api`. The worker role ARN goes back into `receipts`
for its bucket policy; this is not a loop, because the role is created apart from its permissions. `api` is created
before `edge`, because `edge` needs the API endpoint. The endpoint does not depend on the API Lambda, so the chain
does not loop back. `edge` then provides the CloudFront domain, which becomes the API's allowed origin and the
receipts CORS origin, so both are set after the distribution exists. Last, `observability` reads the API log group and
the queue names to build its alarms.

**How to test.** Run `make tf-unit` from the repository root. It runs the offline tests (`terraform test`) for
`bootstrap` and `envs/dev`, in `envs/dev/tests/`. The AWS provider is replaced by a mock (`mock_provider "aws"`), and a
script refuses any test file without one. Nothing touches AWS: no credentials are used, and no plan or apply runs. A
plan against real AWS belongs to Phase 8 and needs the owner's go-ahead.
