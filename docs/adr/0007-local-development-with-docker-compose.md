# 0007. Local development with Docker Compose and emulators

- Status: Accepted (2026-10-09)
- Amended by: [0012](0012-runtime-platform.md) (worker image bref/php-85:3 + GD; RIE confirmed, in-process fallback dropped; local IP/origin headers)
- Date: 2026-10-07
- Revised: 2026-10-09 (PR #1 review comment #6; MinIO maintenance status)

## Context

The brief requires that the whole system run locally without an AWS account, and deploy to AWS afterwards. The AWS
architecture uses Lambda, API Gateway, DynamoDB, S3, SQS and CloudFront.

The receipt worker is the hardest part to reproduce: on AWS it is triggered by S3 → SQS → Lambda, with retries and a
dead-letter queue. A local shortcut (for example an emulator's webhook calling the worker directly) would be a
different code path with no retries or DLQ, leaving the production path untested until deployment.

## Decision

Use one `docker-compose.yml`:

| AWS | Local service |
|---|---|
| API Gateway + Lambda (API) | `api`: nginx + php-fpm running the same `public/index.php` |
| DynamoDB | `dynamodb`: `amazon/dynamodb-local` |
| S3 | `s3`: an S3-compatible emulator, image pinned by digest, with the `receipts` and `receipt-thumbnails` buckets created at start-up; only `receipts` sends events (prefix `receipts/`). Chosen in Phase 0 (see below). |
| SQS + DLQ | `sqs`: ElasticMQ, with the receipts queue and a DLQ (redrive `maxReceiveCount` 3, as on AWS) |
| S3 → SQS notification | `s3-events-relay` (local-only, about 30 lines): receives the emulator's webhook and sends the `{"Records":[...]}` body to ElasticMQ, as S3 does on AWS. Not needed if the chosen emulator can notify SQS directly. |
| Lambda SQS event source mapping + worker Lambda | `worker`: a local runner long-polls ElasticMQ, builds the Lambda SQS event and invokes the worker in the Bref Lambda image through the Lambda Runtime Interface Emulator. It deletes only messages not in `batchItemFailures`, so failed messages are retried and reach the DLQ. |
| CloudFront + S3 site | `frontend`: Vite dev server, proxying `/api` to `api` |

- **Application code is identical** between local and AWS, including the worker, which receives the same SQS event
  wrapping an S3 event. Endpoints and credentials come from environment variables. The relay and the runner are
  local test plumbing that stand in for AWS-managed integrations (the S3 notification and the Lambda event source
  mapping); they are not application code.
- **A Makefile** wraps common commands: `make up`, `make test`, `make lint`.
- **The same Compose stack** runs integration tests in CI.

### S3 emulator: chosen in a Phase 0 spike

The draft used MinIO. Its community edition stopped publishing images in October 2025, went into maintenance mode
in December 2025 and has since been reported as archived, so a pinned MinIO image would never receive security
fixes. Phase 0 includes a short spike to pick a maintained S3-compatible emulator (candidates: Garage, SeaweedFS;
MinIO pinned by digest is the fallback). The pass/fail criteria:
1. **Enforces presigned POST policy conditions** (`content-length-range`, content type, exact key). These are a
   security control we test, so this is mandatory.
2. Can deliver S3-format `ObjectCreated` notifications (webhook or queue), filtered by prefix.
3. Actively maintained, with a published image or a reproducible build.

The decision and the results are recorded here before Phase 0 is merged.

### How the SQS path is tested before deployment

1. **Unit tests** of the worker handler with fixtures: an AWS S3-in-SQS event, an emulator S3-in-SQS event,
   `s3:TestEvent`, an unexpected key, a duplicate delivery, a deleted/replaced/expired record, and a batch where one
   message fails (asserts `batchItemFailures`).
2. **Integration tests** in Compose, run in CI: presigned POST → emulator → relay → ElasticMQ → runner → Lambda
   image → `ready` in DynamoDB Local with a thumbnail in the thumbnails bucket; a "poison" file reaches the ElasticMQ
   DLQ after 3 receives; an upload whose record was deleted gets its objects removed.
3. **Terraform plan tests:** assert the notification is limited to `receipts/`, the event source mapping has
   `ReportBatchItemFailures`, the redrive policy has `maxReceiveCount` 3, and the visibility timeout is at least
   6× the worker timeout.
4. **Post-deploy smoke test:** upload a valid image and an invalid file; assert `ready` and `failed`; assert the DLQ
   is empty.

## Alternatives considered

- **LocalStack.** Emulates S3, SQS, notifications and Lambda in one container. Rejected: since release 2026.03.0 it
  requires a LocalStack account auth token, and the free plan is for non-commercial use only. That adds a
  third-party account and a CI secret, against "runs locally without an account". It is also heavier.
- **Moto server.** Apache-2.0 and supports S3 → SQS notifications, but we could not confirm that it enforces presigned
  POST policy conditions, which are a security control we test.
- **Emulator webhook straight to the worker** (earlier draft). Simpler, but a different entry point with no retries
  or DLQ, so the production path would be untested until deployment.
- **Developing against a real AWS dev account.** Contradicts the brief, costs money and needs internet access.

## Consequences

- Fast, free, offline development. The worker's code path is the same in both places, including SQS batch-failure
  handling and DLQ redrive.
- Still only proven on AWS, and covered by the post-deploy smoke test and Terraform plan tests: real IAM permissions,
  the real S3 notification filter, and Lambda memory and timeout limits.
- Event records differ in `eventSource` (for example `minio:s3` vs `aws:s3`). The handler must not depend on it, and
  fixtures from both are in the unit tests.
- More local services (emulator, ElasticMQ, relay, runner). Developers need Docker installed.
- To verify in Phase 0: that the Bref image runs under the Lambda Runtime Interface Emulator. Fallback: the runner
  calls the same handler in-process with the same event array.
