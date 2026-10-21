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
- Start order: the spike ran silo only with the target up. **Checked in WP8:** silo also starts, reports healthy and accepts `PutBucketNotificationConfiguration` for `arn:minio:sqs::RELAY:webhook` while the target host does not exist at all; events wait in `queue_dir` until the target answers. So `s3` does not need to `depends_on` the relay, and the bootstrap needs no gating on it. `queue_dir` and `/data` are both `tmpfs`, so objects and pending events share one lifecycle (they vanish on `make down`, like DynamoDB Local's data).

**The relay (`s3-events-relay`, WP9)** receives silo's webhook and sends `{"Records":[...]}` to the `receipts` queue; the emulator's wrapper fields `EventName` and `Key` are dropped and the records are forwarded as they arrive (key still percent-encoded, no deduplication). Answers: 204 sent, 400 not an event with a non-empty `Records` list (the `s3:TestEvent` has none; silo never sends it), 401 missing or wrong bearer token, 405 not POST, 413 body over 256 KiB (see below), 502 the queue refused (silo then retries from its `queue_dir`). It runs `php -S` on container port 8080 (not published to the host), with the same hardening as `s3`, and `s3` does not depend on it. Because it reads the real `receipts` queue, `backend/tests/Integration/Pipeline/S3ToQueueTest.php` must run with the worker runner (WP11) stopped: `make test-integration` stops it for those suites and starts it again afterwards, also when a test fails or the run is interrupted (INT, TERM and HUP are trapped, because dash does not run an EXIT trap on a signal; a SIGKILL of make cannot be caught, then `make up` restarts it).

**The worker runner (`worker-runner`, WP11)** stands in for the Lambda SQS event source mapping, which has no local equivalent. It long-polls `receipts` (20 s, up to 10 messages), posts each batch to the worker's Runtime Interface Emulator as an AWS-shaped SQS event (`eventSourceARN` is a local placeholder with the all-zero account id of the queue URLs), and deletes only the messages that succeeded. The whole batch counts as failed, and nothing is deleted, when the answer is not HTTP 200, is not valid JSON, contains `errorType` (RIE reports a handler exception as HTTP 200 without an `X-Amz-Function-Error` header, ADR 0012), or names an `itemIdentifier` that is missing, empty or not in the batch. Its HTTP timeout (8 s) is below the queue's visibility timeout (10 s); the RIE keeps running the handler after the client gives up, so the next invocation may fail while it is busy and is retried, which is acceptable locally. After an invocation that could not be made at all, the runner pauses 2 s before polling again. Failed messages come back after the visibility timeout and go to `receipts-dlq` after 3 receives. SIGTERM finishes the batch in hand (or exits at once while only waiting for messages; once a receive has returned, the signal only sets a flag, so received messages are always handled); the service has `stop_grace_period: 20s`. It starts in step 1 of `make up`: it needs `sqs` and `worker`, not the S3 bootstrap. Logs carry batch sizes and message ids, never bodies.

**How the 256 KiB cap is enforced (WP9 review).** In layers: (1) a `Content-Length` above the cap is answered 413 before the body is read (after the method and token checks); (2) the body reader itself stops one byte past the cap, so a request without `Content-Length` (chunked) is accepted up to the cap and no further; (3) the container limits the rest: `php -S` buffers bodies above about 64 KiB in a temporary file, and the root filesystem is read-only, so the service has a `tmpfs` `/tmp` (`uid=10001,gid=10001,mode=1777,size=8m`; without it such requests failed with an empty body and a 400, and the 413 could not be reached), `memory_limit=32M`, and `post_max_size` / `upload_max_filesize` of 1M (above the cap, so PHP never empties a body the relay would answer with 413). The SQS client has a 1 s connect and 3 s request timeout and no SDK retries (the emulator retries from its `queue_dir`), and the server runs 8 workers. Forwarding is semantically faithful rather than byte-identical (`{}` and `[]` and `1.0` are kept; integers beyond `PHP_INT_MAX` become floats and lone surrogates are rejected with 400; S3 events have neither). The router uses the raw request path, so `//x` is a 404, not the root. The relay's allowlist of endpoints requires a service name to start with a letter, so `2130706433`, `0x7f000001` and `874000000` (IPv4 literals to a resolver) are refused. The live behaviour is covered by `backend/tests/Integration/Pipeline/RelayHttpTest.php` (run by `make test-integration`).

**Hardening of the `s3` container (WP8 security review): it runs non-root and read-only.** `docker-compose.yml` sets `user: "10001:10001"`, `cap_drop: [ALL]`, `security_opt: ["no-new-privileges:true"]` and `read_only: true`. The only writable places are the two `tmpfs` mounts, `/data` and `/queue`, created with `uid=10001,gid=10001,mode=0770`. Silo needs nothing else: it keeps its internal state under `/data/.minio.sys` and needs no `/tmp` or config directory. Checked: the container becomes healthy, the S3 conformance suite passes, and an upload under `receipts/` still queues an event file in `/queue/minio-webhook-RELAY/` while the relay does not exist yet.

**Observed differences in rejections (all still rejections, nothing stored):** a presigned POST form that has a policy but no `X-Amz-Signature` is answered `403 SignatureDoesNotMatch` (not 400); a form with no auth fields at all gets `400 AuthorizationQueryParametersError`; a form with no `key` field gets `400 MalformedPOSTRequest`. The CORS preflight answers `Vary: Origin` and also sends `Access-Control-Allow-Credentials: true`, which AWS does not; harmless because the allowed origin is a single exact origin, never `*`.

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

## Implemented in Phase 1 (2026-10-14, PR #4)

- **DynamoDB bootstrap.** `local-aws-init` (step 2 of `make up`) creates the app table `DYNAMODB_TABLE` from
  `infra/dynamodb/table.json` (ADR 0002) and enables TTL on `expiresAt` (DynamoDB Local 3.3.1 accepts the TTL API;
  whether it really expires items was not tested, and nothing locally depends on expiry). It is idempotent: an
  unchanged table logs "no changes". It never deletes a table: if the existing table differs from the file,
  `make up` fails and says to run `make down && make up`.
- **In-memory storage, kept** (owner decision, 2026-10-13). DynamoDB Local still runs with `-inMemory`, so tables
  and data vanish on `make down`, the same lifecycle as the S3 emulator's tmpfs. Persisted records would point at
  receipt files that no longer exist.
- **`.env` check.** `make up` and the test targets stop early, naming the line to add, when `.env` lacks
  `DYNAMODB_TABLE` (clones from before Phase 1).
- **Local endpoints only.** `DYNAMODB_ENDPOINT` is accepted only for a local
  stand-in. Real AWS hosts are refused, so on AWS the variable is left unset.
- **Origin and client-IP headers locally** work as ADR 0012 §4.5 describes: the Vite proxy adds both headers, nginx
  fills `CloudFront-Viewer-Address` only when it is absent, the `api` healthcheck sends `X-Origin-Verify`, and the
  integration tests check the logged `clientIp` through nginx.

## Implemented in Phase 2 (2026-10-21, PR #5)

Decision A22 and risk R13 in [the Phase 2 plan](../plans/phase-2-authentication.md).

- **The API reaches DynamoDB Local:** `api-php` gets `DYNAMODB_ENDPOINT`, `DYNAMODB_TABLE` (both required), the fake
  key pair from `.env` and `APP_ORIGINS`. It stays only on the internal network `api-internal`, which has no route
  out, and `dynamodb` joins that network, so the fake keys can reach nothing but DynamoDB Local. Clones made before
  Phase 2 must add `DYNAMODB_ENDPOINT` to `.env`.
- **Accepted risk (R13):** `api` and `dynamodb` can both reach php-fpm on port 9000, and FastCGI has no
  authentication. Using that path needs code execution inside AWS's digest-pinned DynamoDB Local image; the target is
  read-only, non-root, `cap_drop: ALL`, holds only the fake keys and has no route out. `listen.allowed_clients` would
  need fixed subnets that could clash with a developer's network. Revisit if a non-AWS image ever joins `api-internal`.
