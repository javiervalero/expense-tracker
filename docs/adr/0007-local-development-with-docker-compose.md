# 0007. Local development with Docker Compose and emulators

- Status: Accepted (2026-10-09)
- Amended by: [0012](0012-runtime-platform.md) (worker image bref/php-85:3 + GD; RIE confirmed, in-process fallback dropped; local IP/origin headers)
- Updated: 2026-10-09 (Phase 0 spike result, gate G1)
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

#### Result (Phase 0 spike, 2026-10-09, approved by the owner at gate G1)

**Chosen emulator: `pgsty/silo`, release `RELEASE.2026-09-16T00-00-00Z`, pinned by digest:**
`pgsty/silo@sha256:635197cb9f36d01bee221d34d1c7d7960f6a95c48b0b6c01d99cd13bdae51a46`.
It is a maintained fork of MinIO. It is used for the `s3` service in local development and CI only.

Results against the three criteria (full detail in [the report](../spikes/s3-emulator.md)):

1. **Presigned POST policy: PASS (8 of 8 cases).** Each case was sent with aws-sdk-php `PostObjectV4`.

   | Case | Result | HTTP | S3 error code |
   |---|---|---|---|
   | valid upload | stored | 204 | none |
   | body over `content-length-range` max | rejected | 400 | `EntityTooLarge` |
   | body under min | rejected | 400 | `EntityTooSmall` |
   | wrong `Content-Type` field | rejected | 403 | `AccessDenied` |
   | different `key` than signed | rejected | 403 | `AccessDenied` |
   | extra unsigned field | rejected | 403 | `AccessDenied` |
   | expired policy | rejected | 403 | `AccessDenied` |
   | tampered `X-Amz-Signature` | rejected | 403 | `SignatureDoesNotMatch` |

   Rejected uploads are not stored. The codes match AWS S3.

2. **Notifications: PASS.**
   - Exactly one webhook per upload under `receipts/`. None for keys outside the prefix, none for the thumbnails bucket, none on delete.
   - An overwrite of the same key sends a **new** event, as AWS does. The relay must **not** deduplicate.
   - The object key is percent-encoded, including the `/` separators (`receipts%2Fuser+a%2Fexp%2B1%2F...`; AWS leaves `/` as is). The relay forwards it unchanged. The worker's parser decodes it with `urldecode()`, as on AWS (ADR 0006), and that handles both forms. Decoding in the relay as well would decode twice.
   - `eventSource` is `minio:s3`. The handler must not depend on it.
   - The webhook sends `Authorization: Bearer <token>`.
   - With `queue_dir` set, an event is replayed exactly once after the receiver restarts. The upload itself still returns 204 while the receiver is down.

3. **Maintained: PASS, with one risk.** The image is published with date-based tags (four releases between 2026-08-04 and 2026-09-16), and the repository is not archived. The project is run mainly by one maintainer, with a few outside contributors. If it is abandoned, we keep the pinned digest, which is acceptable for a dev-only emulator on loopback, or switch to a fallback (below).

**Configuration that worked:**
- Webhook target: server env vars on the `s3` service, `MINIO_NOTIFY_WEBHOOK_ENABLE_<ID>=on`, `MINIO_NOTIFY_WEBHOOK_ENDPOINT_<ID>`, `MINIO_NOTIFY_WEBHOOK_AUTH_TOKEN_<ID>`, `MINIO_NOTIFY_WEBHOOK_QUEUE_DIR_<ID>` (a volume), and `MINIO_NOTIFY_WEBHOOK_QUEUE_LIMIT_<ID>`. The startup log prints the ARN `arn:minio:sqs::<ID>:webhook`. Despite "sqs" in the name, this is silo's webhook target, not a queue. The relay is still needed to put events into ElasticMQ.
- Bucket rule: the AWS SDK call `PutBucketNotificationConfiguration` in the bootstrap step, with a `QueueConfigurations` entry for that ARN, `s3:ObjectCreated:*` and a `receipts/` prefix filter. Re-running it replaces the rule, so it is idempotent. No `mcli` is needed.
- Healthcheck: `silo healthcheck live`, because the image has no curl or wget.
- Path-style addressing is required.
- Start order: the webhook target (the relay) must be reachable when silo starts, so the `s3` service `depends_on` the relay being healthy. Silo starting while the relay is down was not tested.

**Differences from AWS events that the relay and the parser must handle:**
- The webhook body has extra top-level fields `EventName` and `Key`. Drop them. The relay forwards only `{"Records": [...]}` to SQS.
- `awsRegion` is empty and `userIdentity.principalId` is empty.
- `eventVersion` is `2.0` (AWS sends `2.1` or `2.2`).
- `configurationId` is the literal `Config`.

The fixture `docs/spikes/fixtures/silo-object-created-post.json` is the emulator's event for the WP9 tests.

**Presigned GET and the Host header (for Phase 5):** the signature covers the `Host` header, as on AWS. A URL is accepted only when the request's `Host` header matches the host it was signed for. The API therefore signs URLs for the address the browser uses (the host-facing endpoint). Normally the browser fetches the URL, so this just works. If the API container ever fetches such a URL itself (from inside the Docker network), it must send that same `Host` header.

**Licence:** AGPL-3.0-or-later. We use the image unmodified, only as a local development and CI service. We do not distribute it or offer it as a network service to users, so no obligations are triggered. It must not be shipped to production.

**Fallbacks, in order:**
1. Floci (`floci/floci:2.2.0`) with `FLOCI_AUTH_VALIDATE_SIGNATURES=true`. The permanent S3 conformance suite (WP8) can be re-run against it by changing the image, the env names and the notification ARN of the `s3` service. It was not run in this spike because silo passed.
2. MinIO pinned by digest.

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
