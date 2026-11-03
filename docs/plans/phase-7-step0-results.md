# Phase 7: Step 0 research results (gate G2)

Date: 2026-11-03. Companion to [phase-7-infrastructure.md](phase-7-infrastructure.md) (§9, items R1–R11).
Method: docs read from official pages by haiku agents (a first pass without a docs tool was discarded because it answered from memory); code inventory by an Explore agent; "ran" items by the main session in pinned containers with no credentials. Nothing here touched AWS.

Legend: **confirmed** = read in an official page or observed by running; **open** = not confirmed, with where it gets settled.

## Results

| # | Finding | Status | Effect on the plan |
|---|---|---|---|
| R1 | `terraform test` under `mock_provider "aws"` runs `command = apply` fully offline with no credentials (spike, Terraform 1.16.5, provider 6.68.0; only the provider download used the network). | confirmed (ran) | D2 holds. |
| R1 | Asserting a computed value with `command = plan` fails loudly ("Unknown condition value"); it does not pass silently. | confirmed (ran) | Use `apply`, or `override_during = plan` with realistic values. |
| R1 | The mock generates a **random 8-character string** for an ARN (for example `9qw73oan`), not an ARN. | confirmed (ran) | IAM and policy tests need `override_resource` or `mock_resource` with realistic ARNs, or must assert on the JSON structure only. Add to WP2. |
| R1 | A run block's `module { source }` resolves relative to the **root config directory** (`./modules/m` works, `../modules/m` fails); only local and registry sources are allowed. | confirmed (ran, docs) | Tests in `envs/dev/tests/` reach modules with `./modules/...` from `envs/dev`, or the root must sit where `../../modules/` is valid. Re-check the D2 path before WP2. |
| R1 | `lifecycle { prevent_destroy }` is **not assertable**: the resource has no `lifecycle` attribute. | confirmed (ran) | Replace the planned test with a static check (a small grep script in CI) plus code-reviewer. |
| R2 | A fractional `throttling_rate_limit` (0.5) is accepted by the provider schema. AWS docs do not state a rule on fractions. | provider accepts (ran); AWS-side acceptance open | Phase 8 real plan. Keep integers if in doubt. |
| R2 | Maximum HTTP API integration timeout: **30 s**. Event source mapping `maximum_concurrency` minimum: **2**. | confirmed (docs) | D12 and D8 hold. |
| R2 | Docs do not say whether `route_settings` needs an existing route key, nor how `$default` and specific routes rank for throttling. | open | Make the stage depend on the routes; Phase 8 read-only check. |
| R3 | `aws_dynamodb_table.on_demand_throughput`: `max_read_request_units`, `max_write_request_units`, each ≥ 1 (or -1 to remove); a GSI has its own block. The provider accepts it (spike). | confirmed (docs, ran) | D15 holds. |
| R3 | On-demand prices, us-east-1: $0.125 per million reads, $0.625 per million writes. eu-west-1 and PITR prices were not found. | partly confirmed | Recompute the cost note once the region is chosen. |
| R4 | SDK presigned host form: eu-west-1 → `<bucket>.s3.eu-west-1.amazonaws.com`; us-east-1 → `<bucket>.s3.amazonaws.com`. Same for POST action and GET. | confirmed (ran) | The CSP `connect-src` and `img-src` host depends on the region; build it from the region variable. |
| R5 | Bref 3.0.12 `layers.json` has `php-85` (version 20 in eu-west-1 and us-east-1) and `arm-php-85`. `bref/extra-php-extensions` has `gd-php-85` (version 1 in both regions); its latest release is 3.0.13. | confirmed (read) | D10 holds. Check that 3.0.13 pairs with Bref 3.0.12 when adding the dependency (WP3). |
| R6 | SDK inventory per entry point: see the table below. No `Scan`, no table administration, no SQS SDK call. | confirmed (code) | IAM tables in D20. |
| R6 | The **worker also deletes** from `receipts` (failed, replaced, obsolete) and the API deletes from both buckets. Neither Lambda puts objects into `receipts`. | confirmed (code) | Already covered: the plan's IAM table (D20) gives the worker `DeleteObject` on `receipts` and "never `PutObject`". Only the short phrase "worker cannot write to `receipts`" in `PLAN.md` row 7 is imprecise; read it as "cannot put objects". |
| R6 | Three of the four alarm messages are logged by the **API** only. `CorruptItem` is a failure-code value, not a log line (not yet traced to a log line). | confirmed (code) | D22 metric filters go on the API log group; trace `CorruptItem` before building that alarm. |
| R7 | The PHP tools image has `gd` but **no `zip` extension and no `zip` binary** (only `unzip`). | confirmed (ran) | WP3 needs another way to build a reproducible zip. See owner decision below. |
| R8 | Worker memory re-measure with a GD-generated progressive JPEG. | not done | Moves to WP6. |
| R9 | S3 → SQS queue policy shape (principal `s3.amazonaws.com`, `SQS:SendMessage`, `aws:SourceArn`, `aws:SourceAccount`) confirmed; FIFO queues are not supported. SSE-SQS support and a TLS-deny statement are **not stated** in the S3 docs. The provider accepts both (spike). | partly confirmed | Open: Phase 8 delivery check (upload one object, read the queue). Fallback if delivery fails: drop the TLS deny first. |
| R10 | Managed cache policy ids match the plan. `CloudFront-Viewer-Address` goes in an origin request policy, not a cache policy. CSP limit **1,783 characters**. Origin request policy limit: 10 headers (ours: 5). | confirmed (docs) | D17 holds; add a CSP length test. |
| R11 | No Terraform-for-Bref example has a licence (3 have none, 2 are unasserted). Patterns only. One useful idea: derive layer ARNs from `layers.json`. | confirmed | No code copied. |

## R6 detail: SDK operations per entry point

| Entry point | DynamoDB | S3 | SQS |
|---|---|---|---|
| API | GetItem, PutItem, UpdateItem, DeleteItem (including ReturnValues), Query on `LSI1` and the base table, TransactWriteItems | presigned POST and GET (no call), DeleteObject, ListObjectsV2, DeleteObjects on `receipts` and `receipt-thumbnails` | none |
| Worker | GetItem, UpdateItem (receipt records only) | GetObject and HeadObject on `receipts`, PutObject on `receipt-thumbnails`, DeleteObject on both | none (the event source mapping receives and deletes) |

## Owner decisions at G2 (2026-11-03)

1. **Lambda zip tool (R7): decided.** Add the `zip` package to the pinned PHP tools image in WP3 (D11 updated). The host `python3` (not pinned) and the Terraform `archive` data source (ruled out by D2) were not chosen.
2. **Region: decided, us-east-1** (see [ADR 0013](../adr/0013-aws-region-us-east-1.md)). `eu-west-1` was never a decision; it was a placeholder from Phase 0. Chosen for low cost on a proof of concept. Effects: the S3 host in the CSP is the global form `<bucket>.s3.amazonaws.com`; the local placeholders change to `us-east-1`; prices in §9 are re-checked for us-east-1 (the confirmed on-demand prices above are for this region).
3. **Test shape (R1):** because mocks produce random ARNs, IAM and policy tests use realistic override values. No decision needed.

## Throwaway spike

The R1 spike lived in `.terraform/spike/` (git-ignored, not committed). It can be deleted at any time.
