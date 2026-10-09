# Spike B: S3 emulator (`pgsty/silo`)

- Work package: WP3 of `docs/plans/phase-0-foundations.md`. Decision it feeds: ADR 0007, "S3 emulator" (gate G1).
- Date: 2026-10-09. Harness: `spikes/s3-emulator/` (run with `./run.sh`; tear down with `./run.sh down`).
- Result: **silo passes all three ADR 0007 criteria. Floci was not needed and was not run.** Recommendation: **silo.**

## What was tested

Tests were written first and run with nothing listening (RED: 19 errors, 2 skipped, 0 assertions). Then silo was started
and the same tests were run unchanged (GREEN). No test was weakened. The suite was then run three times on the same
stack and once more from empty volumes: 21 tests, all green every time (2 outage tests are skipped in the main pass and
run in a separate two-phase scenario, see below). Every presigned POST is built with aws-sdk-php 3.400.0
`PostObjectV4` and sent as multipart with Guzzle. PHP 8.5.11 inside `php:8.5-cli-alpine`.

Image: `pgsty/silo:RELEASE.2026-09-16T00-00-00Z`
**digest `sha256:635197cb9f36d01bee221d34d1c7d7960f6a95c48b0b6c01d99cd13bdae51a46`** (linux/amd64, ubi9-micro base,
`org.opencontainers.image.revision` 2a4d51406b7ed87af5fe6fe0f801f3290f96eb3c). Other pins used by the harness:
`php:8.5-cli-alpine` `sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb`,
`composer:2.10.3` `sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac`.

## Criterion 1: presigned POST policy enforcement (mandatory): PASS

| Test | Result | HTTP | S3 error code (message) |
|---|---|---|---|
| valid upload | accepted, object stored with 100 bytes and `image/jpeg` | 204 | none |
| body over `content-length-range` max | rejected, not stored | 400 | `EntityTooLarge` (exceeds the maximum allowed object size) |
| body under min | rejected, not stored | 400 | `EntityTooSmall` |
| wrong `Content-Type` field | rejected, not stored | 403 | `AccessDenied` (Invalid according to Policy: Policy Condition failed) |
| different `key` than signed | rejected, neither key stored | 403 | `AccessDenied` (Policy Condition failed) |
| extra unsigned field (`x-amz-meta-injected`) | rejected, not stored | 403 | `AccessDenied` (Each form field ... must appear in the list of policy conditions) |
| expired policy | rejected, not stored | 403 | `AccessDenied` (Policy expired) |
| tampered `X-Amz-Signature` | rejected, not stored | 403 | `SignatureDoesNotMatch` |

Codes and statuses match AWS S3 behaviour. Each rejection test also asserts a 4xx, an S3 XML error code, and that the
object does not exist afterwards.

## Criterion 2: S3-format ObjectCreated notifications with prefix filter: PASS

| Test | Result |
|---|---|
| exactly one webhook per POST upload to `receipts/` | 1 webhook, 1 record |
| no webhook for a key outside the prefix (`other/...` in the `receipts` bucket) | none |
| no webhook for the `receipt-thumbnails` bucket | none |
| no webhook on delete | none (only the creation event exists) |
| overwrite of the same key | **one webhook per upload (2 for 2 uploads)**, same ETag, `eventName` `s3:ObjectCreated:Post` both times; matches AWS. Relay must not deduplicate (D6 confirmed) |
| `Records[0].s3.object.key` is URL-encoded | key `receipts/user a/exp+1/receipt-x` arrives as `receipts%2Fuser+a%2Fexp%2B1%2Freceipt-x`; `urldecode()` restores it exactly. Space is `+`, literal plus is `%2B`, as on AWS |
| `eventName` / `eventSource` | `s3:ObjectCreated:Post` / `minio:s3` (AWS: `aws:s3`; the handler must not depend on it, as ADR 0007 says) |
| auth token header | sent as `Authorization: Bearer <token>` |
| receiver down (`queue_dir`) | upload still returns 204; after the receiver restarts the queued event is replayed exactly once, about 2 s later |
| bootstrap idempotent | run twice, still one rule (prefix `receipts/`), thumbnails bucket has none |

Differences from AWS that the worker's emulator fixture must cover (all visible in
`docs/spikes/fixtures/silo-object-created-post.json`):

1. `object.key` percent-encodes the `/` separators too (`%2F`), where AWS leaves `/` as is. `urldecode()` handles both.
2. The body has extra top-level fields `EventName` and `Key`; `Key` is `<bucket>/<key>` (not URL-encoded). Ignore them.
3. `awsRegion` is empty, `eventSource` is `minio:s3`, the `s3.bucket.arn` is `arn:aws:s3:::receipts`, and there are
   extra `source` and `responseElements` objects. `userIdentity.principalId` is empty.
4. `configurationId` is the literal `Config`, not the id given in the rule.
5. `eventVersion` is `2.0` (AWS sends `2.1`/`2.2`). Same shape for `Records[0]`.
6. One record per webhook. The body is `{"EventName", "Key", "Records":[...]}`, so the relay should forward only the
   `{"Records": [...]}` part to SQS, as AWS would. Notifications are only sent for the rules matching the event.

### Configuration that worked (no mcli needed; D5 not triggered)

- Webhook target through server env vars on the `s3` service (`MINIO_*` names are still the ones used by silo):
  `MINIO_NOTIFY_WEBHOOK_ENABLE_<ID>=on`, `..._ENDPOINT_<ID>=http://relay:8080/hook`, `..._AUTH_TOKEN_<ID>`,
  `..._QUEUE_DIR_<ID>=/queue` (a volume), `..._QUEUE_LIMIT_<ID>`. The startup log prints
  `SQS ARNs: arn:minio:sqs::<ID>:webhook`.
- Bucket rule through the AWS SDK: `PutBucketNotificationConfiguration` with a `QueueConfigurations` entry whose
  `QueueArn` is that ARN, `Events` `s3:ObjectCreated:*` and a `prefix` `receipts/` filter rule. Re-putting is idempotent
  (it replaces the configuration). See `spikes/s3-emulator/bootstrap.php`.
- Root credentials: `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` (dummy alphanumeric values in the compose file).
  Path-style addressing is required (`use_path_style_endpoint`), as for any single-host emulator.
- The webhook target must be reachable when silo starts; in the harness `s3` depends on `capture` being healthy. A
  target that goes down later is covered by `queue_dir` (above). Not tested: silo starting while the target is down.

## Other behaviour checked

| Item | Result |
|---|---|
| CORS | `PutBucketCors` (SDK) is supported per bucket. Preflight (`OPTIONS /receipts`, POST) from `http://localhost:5173` returns 200 with `Access-Control-Allow-Origin: http://localhost:5173`. From `http://evil.example`: 403, no allow-origin header. |
| Presigned GET, host signing | URL signed for `http://localhost:19100`, sent to `http://s3:9000` with `Host: localhost:19100`: 200 and the body. Same URL with the real host `s3:9000`: 403 `SignatureDoesNotMatch`. So the Host header is part of the signature (as on AWS). **Phase 5: the API container may sign with the host-facing endpoint but must send the request with that Host header, or simply have the browser fetch the URL, which is the normal flow.** |
| Health | `GET /minio/health/live` and `/ready` return 200, from the host (`127.0.0.1:19100`) and inside the network. The image is ubi-micro (no curl/wget), so the Compose healthcheck is `["CMD", "silo", "healthcheck", "live"]` (the binary's own probe); it reports `healthy` in about 3 s. |
| Port binding | `127.0.0.1:19100->9000` only. |
| Console | Disabled with `MINIO_BROWSER=off`. |

## Criterion 3: actively maintained, published image: PASS (with a risk)

- Published image on Docker Hub with date-based release tags; four releases between 2026-08-04 and 2026-09-16.
  Pushes to the repository continued until at least 2026-10-08. Not archived. About 3,900 stars, 20 open issues.
- It is a fork of MinIO (history and copyright 2015-2025 MinIO, Inc., "Modifications 2025-2026 PGSTY"), so the S3
  behaviour we rely on (POST policy, notifications, `queue_dir`) is the mature MinIO implementation.
- **Single-maintainer risk.** The fork is run by one person/organisation (PGSTY, merges by Feng Ruohang) with a few
  outside contributors (for example S3-compatibility fixes in late September). If it is abandoned, we keep the pinned
  digest (no security fixes, acceptable for a dev-only emulator on loopback) or switch to Floci or pinned MinIO, and
  the harness is built to be re-run against another emulator. The application only depends on standard S3 APIs.
- Image tags are mutable, so the compose file pins by digest.

## Licence note

Silo is AGPL-3.0-or-later. We use it unmodified, only as a local development and CI service in a container, and we
do not distribute it or offer it as a network service to users. Our own code is not linked to it. No obligations
are triggered. Do not ship the image to production.

## Fixtures captured

- `docs/spikes/fixtures/silo-object-created-post.json`: the full webhook body for one `s3:ObjectCreated:Post`
  upload (the headers are listed in the notification table: `Authorization: Bearer <token>` and a JSON
  content type). Use it as the "emulator S3-in-SQS" fixture (wrapped in an SQS body) for WP9. The
  sourceIPAddress and ids are local and not sensitive.

## Recommendation

**Use silo**, pinned by digest above, for the `s3` service (WP8):

1. Config: `MINIO_NOTIFY_WEBHOOK_*` env vars with `queue_dir` on a volume; bucket rule via SDK in `local-aws-init`;
   no mcli.
2. Compose healthcheck `silo healthcheck live`; `depends_on` the relay healthy so the target exists at start.
3. Keep the single-maintainer risk in ADR 0007's consequences. Fallbacks, in order: Floci
   (`floci/floci:2.2.0` with `FLOCI_AUTH_VALIDATE_SIGNATURES=true`; the suite can be re-run against it by changing the
   image, env names and ARN in `compose.yml`; not run now because silo passed) and MinIO pinned by digest.
4. Relay: forward `body.Records` only; urldecode `object.key`; do not deduplicate (an overwrite sends a second event).
5. Handler fixtures: add the silo fixture next to the AWS one; the differences are listed above.

## How to reproduce

```
cd spikes/s3-emulator
./run.sh        # composer install (container), compose up, bootstrap, 21 tests, outage scenario
./run.sh down   # remove the spikeb project and its throwaway volumes
```

Raw per-test status and error codes are written to `spikes/s3-emulator/results/results.jsonl` (not committed).
