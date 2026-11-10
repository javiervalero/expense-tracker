# Phase 7: Infrastructure (Terraform). Implementation plan

| | |
|---|---|
| Status | **Approved at G1 on 2026-11-03.** The owner approved all 15 decisions in §10 with their recommended defaults; the 9 contradictions are resolved as written above. Step 0 research (R1–R11) was recorded at G2 on 2026-11-03 ([phase-7-step0-results.md](phase-7-step0-results.md)). **All 11 work packages (WP1–WP11) are done and merged to `main` as PR #11 (f5690dc, 2026-11-06).** Nothing was applied to AWS; every plan against AWS, apply and destroy is still Phase 8. Drafted by `ecc:planner` (opus/high) from PLAN §12 rows 7–8, ADRs 0001/0005/0006/0009/0010/0012, the carry lists in the Phase 1–6 plans, and the code (`ApiConfig`, `WorkerConfig`, `S3Settings`, `DynamoDbSettings`, `LocalEnvironment`, `WorkerRuntime`, client factories, `infra/dynamodb/table.json`, `Makefile`, `scripts/terraform.sh`, `ci.yml`) |
| Phase | 7 (essential track), size L |
| Branch | `feat/phase-7-infrastructure`, from `main` at c4f29da |
| AWS | **None.** No `terraform plan/apply/destroy/import`, no `init` with a backend, no AWS CLI, no credentials. All validation is offline. **Every plan against AWS, every apply and every destroy belongs to Phase 8, and each one needs the owner's explicit go-ahead.** |
| Cost of this phase | $0 |

**Scope (PLAN §12 row 7):** the state bootstrap root; the `envs/dev` composition and its modules (DynamoDB, three buckets, SQS + DLQ, two Lambdas, HTTP API with throttling, CloudFront, IAM, log groups, alarms, budget); the build tooling Terraform depends on (layer versions from `composer.lock`, the Lambda zip); offline plan tests.

**Not in scope:**

- `make deploy`, the front-end upload and invalidation, all AWS smoke checks (Phase 8).
- GitHub OIDC role and `deploy.yml` (L2: ADR 0010 phasing puts it there).
- tflint and checkov (L3).
- Lambda-error and API-5xx alarms (L4).
- WAF (L8).
- Custom domain.

## Contradictions found while planning

1. **Phase 6 carry: "the Lambda timeout must cover 11–12 sequential queries under degraded DynamoDB".** That cannot be met. One DynamoDB call can take up to about 12 s when degraded (`DynamoDbClientFactory`: 1 s connect + 3 s request, × 3 attempts), so 12 calls ≈ 144 s. API Gateway HTTP API stops every integration at 30 s. → **D12:** the timeout chain covers the *normal* worst case with a wide margin. Degraded DynamoDB ends in a Lambda timeout (the report is read-only, so this is safe). The residual risk is recorded. A request deadline in code is owner decision 9.
2. **Phase 2 R15 (an `APP_ORIGINS` ↔ CloudFront dependency cycle).** There is no cycle. The distribution needs only `aws_apigatewayv2_api.api_endpoint`, which does not depend on the Lambda. The order is: API → distribution → Lambda (its env reads `domain_name`) → integration → routes. → **D25**, pinned by a test.
3. **`modules/README.md` layout** puts the receipts bucket in `data` and the queue in `worker`. The S3 → SQS wiring would then be split across two modules. → **D3** keeps the whole pipeline in one module, so one test file covers it. This is a README change, not an ADR change.
4. **ADR 0010 calls tflint and checkov "required"; PLAN §10 and ADR 0010 phasing say L3.** → Kept in L3. They are also new tools (memory note: ask before running new scanners).
5. **Phase 0 D8 ("no `layers.json` for gd, pin a variable").** This was superseded by the ADR 0012 Phase 0 result: the file exists. → **D10** reads it from `bref/extra-php-extensions`.
6. **The Phase 5 §4 note says "with SSE-KMS in Phase 7"**, but the bootstrap checklist already chose SSE-S3. → **D7** uses SSE-S3 everywhere. The ETag stays an MD5, and the accepted Phase 5 risk is unchanged.
7. **API Gateway route throttles are shared by all callers.** They cannot replace the per-user limit asked for in Phase 5 M1. → Gateway throttles act only as a backstop. M1 is owner decision 10.
8. **Cost amplification is bigger than the Phase 6 notes say.** The report's corrected worst case is about 3,000 strongly consistent read units per call. At only 1 request/s sustained, that is roughly $30/day of reads, and AWS Budgets reacts only hours later. A route throttle alone is weak. → **D15:** a DynamoDB on-demand maximum throughput as a hard cost ceiling. (These figures are the planner's own estimate from approximate prices; Step 0 R3 confirms them.)
9. **Phase 5 I3 ("worker on an internal network") conflicts with PLAN §4 "No VPC".** Lambda ENIs also slow down `destroy`, which Phase 8 has to exercise. → Declined for now (owner decision 13).

## 0. What exists vs what Phase 7 builds

| Area | Exists | Phase 7 builds |
|---|---|---|
| Terraform | `bootstrap/` (versions only), `envs/dev/` (versions, provider with `allowed_account_ids` and `default_tags`, 3 variables, partial S3 backend with `use_lockfile`), lock files for aws 6.68.0, `modules/README.md` | Bootstrap resources, 7 modules, composition, `tests/*.tftest.hcl`, `random` provider pin |
| Tooling | `scripts/terraform.sh` (pinned 1.16.5, passes no AWS variables, IMDS off); `make tf-fmt tf-check tf-test tf-lock`; CI `terraform` job (`tf-check`, `tf-test`) | `make tf-unit` (offline `terraform test`), `tf-fn-test`, `tf-layers`, `tf-layers-check`, `lambda-build`, `lambda-check`; new CI steps (no new jobs) |
| Table | `infra/dynamodb/table.json` (CreateTable shape plus `TimeToLiveAttribute`) | `modules/table` derives everything from it |
| App config | API: `ORIGIN_VERIFY_SECRETS`, `CLIENT_IP_HEADER`, `APP_ORIGINS`, `DYNAMODB_TABLE`, `RECEIPTS_BUCKET`, `THUMBNAILS_BUCKET`, optional `AWS_REGION`. Endpoints are refused unless `APP_ENV=local`. Worker: the same minus the first three. `WorkerRuntime::MEMORY_LIMIT = 768M` | Env maps in D23, with tests |
| Local mirrors | ElasticMQ redrive 3 (visibility 10 s locally); bootstrap CORS `POST GET HEAD`, headers `*`, expose `ETag`, max-age 600 | The same settings on AWS |

## 1. Goal and definition of done

**Goal:** `envs/dev` describes the whole PLAN §4 architecture. It passes `fmt`, `validate` and an offline test suite that pins every security- and pipeline-relevant setting. Phase 8 then only has to supply credentials, review a real plan and apply.

**Done means:**

- `make tf-check tf-unit tf-fn-test tf-layers-check lambda-build lambda-check` pass locally and in CI, plus `make lint`, with `tf-test` still green.
- Every carry item in §7 is either built and tested or explicitly moved to Phase 8 or later.
- No `data` source in our own Terraform (D2), and no secret or account id is committed.
- The docs are done: ADR notes, module README, PLAN row 7 → Done, and the Phase 8 prerequisites list.

## 2. Design decisions (recommended defaults)

| # | Topic | Decision |
|---|---|---|
| D1 | Safety | Terraform runs only through `scripts/terraform.sh` (no credentials, IMDS off). Allowed commands: `fmt`, `init -backend=false -lockfile=readonly`, `validate`, `test`, `providers lock`. The wrapper does not enforce this list in code: it passes any subcommand through, and safety rests on the container having no credentials (WP11 security review, LOW). Phase 8, which will have credentials, must add its own gate before any `plan` or `apply`, and should refuse a `TERRAFORM_IMAGE` override without a `@sha256:` pin. `make tf-unit` refuses to run if any `*.tftest.hcl` lacks `mock_provider "aws"` (`scripts/check-tftest-mocks.sh`). Without credentials, a real provider fails closed anyway |
| D2 | Offline validation | **`terraform test` with `mock_provider "aws"`** and the real `random` provider (it runs locally, so the real slot generation is what gets tested). `command = apply` under mocks, or `plan` with `override_during = plan` (Step 0 R1 decides). **No data sources:** account and region come from variables; managed CloudFront policy ids are constants; IAM policies are `jsonencode()` locals, not `aws_iam_policy_document`. That way the tests assert real JSON instead of mocked strings. All tests live in `envs/dev/tests/` (run blocks use `module { source = ... }`) and `bootstrap/tests/`, so only the two existing lock files are needed |
| D3 | Module layout | `modules/table`, `modules/receipts` (2 buckets, queue, DLQ, queue policy, the one notification), `modules/lambda-function` (function, role, inline policy, log group; used twice), `modules/worker` (function + event source mapping), `modules/api` (HTTP API, stage, routes, throttles, integration, permission, access-log group), `modules/edge` (site bucket, OAC, CloudFront, function, policies), `modules/observability` (SNS, metric filters, alarms, budget). Modules never configure providers |
| D4 | Names | `local.name = "expense-tracker-${var.environment}"`. Table `expense-tracker-dev`. Functions and roles `…-api`, `…-worker`. Queues `…-receipts`, `…-receipts-dlq`. Buckets `…-site-<hex8>`, `…-receipts-<hex8>`, `…-receipt-thumbnails-<hex8>`, with one `random_id` (4 bytes): no account id in public bucket hosts, no dots (the `S3Settings` pattern), ≤ 63 characters, and a fresh name after destroy. State bucket: `expense-tracker-tfstate-<account_id>-<region>` (deterministic, because `backend.hcl` must name it before any state exists) |
| D5 | Bootstrap | `bootstrap/{providers,variables,main,outputs}.tf` implement the checklist already in `bootstrap/main.tf` (versioning, AES256, public access block, TLS-only policy, BucketOwnerEnforced, `prevent_destroy` on the bucket and its config resources, noncurrent versions expire after 90 days, abort multipart after 7 days). Keys: `envs/dev/terraform.tfstate`, `bootstrap/terraform.tfstate` (migrated in Phase 8). `backend.hcl.example` is committed; the real `backend.hcl` is git-ignored |
| D6 | Table | `jsondecode(file("${path.root}/../../../dynamodb/table.json"))` builds `hash_key`/`range_key` (from the key types), `attribute`, `local_secondary_index` (`non_key_attributes` through `try()`) and `ttl { attribute_name = "expiresAt", enabled = true }`. On-demand billing, PITR on, `deletion_protection_enabled = false` (dev; Phase 8 tests destroy), plus the D15 ceiling |
| D7 | Receipt buckets | Both: public access block on, BucketOwnerEnforced, SSE-S3, versioning **off**, TLS-deny policy, abort incomplete multipart after 1 day. `receipts` only: CORS `["https://${distribution.domain_name}"]` with `POST GET HEAD`, headers `*`, expose `ETag`, max-age 600 (the local rule); an explicit `Deny s3:PutObject` for the worker role ARN in the bucket policy (defence in depth on top of IAM). `force_destroy = true` (owner decision 5) |
| D8 | Queue + event source mapping | `receipts`: visibility **180 s** (6 × worker 30 s), retention 4 days, SSE-SQS, redrive `maxReceiveCount = 3` to the DLQ. DLQ: retention 14 days, `redrive_allow_policy` byQueue. Queue policy: `s3.amazonaws.com` `sqs:SendMessage` with `aws:SourceArn` = the receipts bucket and `aws:SourceAccount`; TLS deny (Step 0 R9). Notification: the receipts bucket only, `s3:ObjectCreated:*`, prefix `receipts/`, `eventbridge = false`; `depends_on` the queue policy. Event source mapping: **`batch_size = 1`** (hard requirement, Phase 5), `maximum_batching_window_in_seconds = 0`, `function_response_types = ["ReportBatchItemFailures"]`, `scaling_config.maximum_concurrency = 2` (Phase 5 L4; 2 is the AWS minimum, Step 0 R2) |
| D9 | Lambdas (common) | `provided.al2023`, `["x86_64"]`, `BREF_PING_DISABLE = "1"`, explicit log group (D21) created before the function, no function URL, no VPC, X-Ray off, `reserved_concurrent_executions` from a variable, default `-1` (Phase 8 reads the account quota first) |
| D10 | Layers | `scripts/bref-layers.php` (php tools container) reads `backend/vendor/bref/bref/layers.json` (`php-85`) and `backend/vendor/bref/extra-php-extensions/layers.json` (`gd-php-85`) and writes **committed** `envs/dev/bref-layers.auto.tfvars.json` = `{bref_php_layer_versions: {region: n}, bref_gd_layer_versions: {region: n}}`. `make tf-layers` regenerates it; `make tf-layers-check` fails on drift (CI `backend` job, which has `vendor/`). Terraform builds `arn:aws:lambda:${region}:873528684822:layer:php-85:${v}` and `…:403367587399:layer:gd-php-85:${v}`; the publisher accounts are named constants. Adds `bref/extra-php-extensions` as a **dev** dependency (owner decision 12). `validate` needs no `vendor/` |
| D11 | Package | `make lambda-build` (pinned php tools image) copies `backend/{public,entrypoints,src,php/conf.d,composer.json,composer.lock}` to `build/lambda/src`, runs `composer install --no-dev --classmap-authoritative --no-scripts`, and writes **one** reproducible zip `build/lambda/backend.zip` (sorted entries, fixed mtime) for both functions. The pinned php tools image has neither `ext-zip` nor a `zip` binary (Step 0 R7), so WP3 adds the `zip` package to `docker/php/Dockerfile` (owner decision at G2). It then writes git-ignored `envs/dev/package.auto.tfvars.json` (`{lambda_package_path, lambda_package_sha256}`). `make lambda-check`: `php/conf.d/app.ini`, `public/index.php`, `entrypoints/lambda/worker.php` and `vendor/autoload.php` are present; no `tests/`, `.env*`, `phpunit`, `phpstan` or `php-cs-fixer`; under 50 MB zipped; `vendor/autoload.php` loads in the pinned `bref/php-85` image |
| D12 | API Lambda | 1024 MB (Argon2id: 64 MiB per hash, and CPU scales with memory). **Timeout chain: Lambda 25 s < integration `timeout_milliseconds = 29000` < CloudFront `origin_read_timeout = 30`**, pinned by a test. The Phase 1 rule "> 12 s per DynamoDB call" holds. Report worst case: 12 queries at about 0.05–0.3 s each, about 4 s at most (R4 locally: 0.48 s), so more than 6× margin. One fully degraded call (12 s) still fits; two or more end in a Lambda timeout → 504 (read-only, safe). The Phase 5 cleanup worst case (> 30 s when DynamoDB and S3 degrade together) stays accepted |
| D13 | Worker Lambda | **1024 MB** (`WorkerRuntime::MEMORY_LIMIT` 768M is tied to it: the test asserts `memory_size == 1024`, with a comment pointing to the constant; change both together), timeout 30 s, handler `entrypoints/lambda/worker.php`, layers php-85 then gd-php-85 |
| D14 | HTTP API | Protocol HTTP, `disable_execute_api_endpoint = false` (no custom domain; PHP's origin check rejects direct calls). One AWS_PROXY integration, payload **2.0** (Phase 2: duplicate-cookie handling). Routes: **`$default`** (so PHP answers everything, including the 403 to direct callers) plus explicit routes that exist only to carry a throttle: `GET /api/v1/reports/monthly`, `POST /api/v1/expenses/{id}/receipt/upload`, `GET /api/v1/expenses`, `POST /api/v1/auth/login`, `POST /api/v1/auth/register`. Stage `$default`, auto-deploy, `default_route_settings` plus `route_settings` (owner decision 7). Writes stay under the stage default (each is O(1), Phase 3 B22). Lambda permission `source_arn = "${execution_arn}/*/*"`. No API Gateway CORS |
| D15 | Cost ceiling | `aws_dynamodb_table.on_demand_throughput { max_read_request_units, max_write_request_units }` (owner decision 8; argument names, minimums and prices confirmed in Step 0 R3). The ceiling throttles reads to 503 + `Retry-After` (existing mapping) instead of billing without limit. A test asserts it is set |
| D16 | Origin secret | `random_password.origin_verify_a/_b`: length 64, `special = false` (alphanumeric always satisfies `ApiConfig::SLOT_PATTERN` and contains no comma), `keepers = { rotation = var.origin_verify_rotation_a/_b }`. `var.origin_verify_active_slot` ∈ {`a`,`b`} picks the CloudFront header value. Lambda env `ORIGIN_VERIFY_SECRETS = "${a},${b}"`. Tests: both match the PHP slot regex, they differ, neither contains the other, and the header equals the active slot. All values are sensitive; plan output shows `(sensitive value)`. State holds them in plain text (accepted, ADR 0012) |
| D17 | CloudFront | Origins: the site bucket (OAC, sigv4 always) and API Gateway (domain = `api_endpoint` without the scheme, https-only, TLSv1.2, custom header `X-Origin-Verify`). Behaviours: `/api/*` → managed **CachingDisabled** (`4135ea2d-6df8-44a3-9df3-4b5a84be39ad`), custom origin request policy (headers **whitelist**, exactly `Origin, X-Requested-With, Content-Type, Accept, CloudFront-Viewer-Address`; cookies all; query strings all), all 7 methods, `https-only`. `/assets/*` → CachingOptimized (`658327ea-f89d-4fab-a63d-7e88639e58f6`). Default `/*` → CachingOptimized, viewer-request function (D19), `redirect-to-https`, `default_root_object = "index.html"`. **No `custom_error_response`** (Phase 4: it would mask API problem responses). IPv6 on, `http2and3`, `PriceClass_100`, default certificate, no standard logging (Phase 4 L3: query strings). Ids confirmed in Step 0 R10 |
| D18 | Headers, CSP, caching | One security block on every response-headers policy: HSTS (2 years, includeSubDomains, no preload), nosniff, frame DENY, `Referrer-Policy: same-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`, all `override = true`. CSP (site and assets policies) = the ADR 0008 target plus `connect-src 'self' https://<receipts-host>` and `img-src 'self' https://<thumbnails-host>`. The hosts are built from bucket name + region in the exact form R4 records, not from computed attributes. `Cache-Control`: `no-cache` on the default behaviour, `public, max-age=31536000, immutable` on `/assets/*` (Phase 4 carry, kept in IaC rather than upload flags). The API policy has no CSP and keeps PHP's `no-store` (no Cache-Control override) |
| D19 | SPA deep links | `modules/edge/functions/spa-rewrite.js` (cloudfront-js-2.0, viewer request, default behaviour only): if the last path segment has no `.`, the URI becomes `/index.html`. Tested by `spa-rewrite.test.mjs` with `node:test` + `vm` in the pinned node image (`make tf-fn-test`; no new dependency) |
| D20 | IAM | Trust: `lambda.amazonaws.com` only. Inline policies, no managed policies (`AWSLambdaBasicExecutionRole` grants `CreateLogGroup` on `*`). Action lists are fixed in Step 0 R6 from the SDK call inventory; expected shape in the table below. No `*` actions, no `*` resources (logs: own group ARN only), no `Scan`, no table administration, no KMS |
| D21 | Logs | `/aws/lambda/expense-tracker-dev-{api,worker}` and `/aws/apigateway/expense-tracker-dev`, 30-day retention (Phase 1 decision 2). Access-log JSON: `requestId, requestTime, httpMethod, routeKey, path, status, responseLength, integrationLatency, integrationErrorMessage`. No query string variable (Phase 3 B23); the test asserts this. Lambda log format stays Text (the app already writes JSON lines) |
| D22 | Alarms, budget | SNS `expense-tracker-dev-alerts` with an email subscription (`var.alert_email`, uncommitted tfvars; the owner confirms it in Phase 8). Alarms (period 300, `> 0`, `treat_missing_data = notBreaching`, alarm + OK actions): DLQ `ApproximateNumberOfMessagesVisible`; metric filters in namespace `ExpenseTracker/dev` on patterns anchored on the JSON structure, still a substring match so any FPM prefix is fine: `"message":"<phrase>"` for the three messages, and for CorruptItem `"code":"CorruptItem"` or `(CorruptItem)","file"` (WP11 security review: a bare phrase would match request text the API logs, the path and the `Origin` header, so anyone could set off or pin an alarm; `json_encode` escapes every `"` inside a value, so request text can never produce the anchor. Do not simplify them back to bare phrases. Phase 8 checks real and forged lines with the read-only `test-metric-filter`, see section 7): `receipt cleanup failed`, `client address unavailable`, `CorruptItem`, `login throttle clean-up failed after a successful login` (Step 0 R6: `receipt cleanup failed`, `client address unavailable` and `login throttle clean-up failed…` are logged by the API only, so those filters sit on the API log group; `CorruptItem` is a failure-code value, not a log line, so WP9 traces where it is logged before its alarm is built). `scripts/check-alarm-messages.sh` fails if a pattern literal no longer appears in `backend/src`. Budget `expense-tracker-dev-monthly`: account-wide COST, `var.monthly_budget_usd`, email at 80% actual and 100% forecast |
| D23 | Lambda env | See the table below. The test asserts the **exact key set**: no `*_ENDPOINT`, no `APP_ENV`, no reserved `AWS_*` key |
| D24 | Inputs | `terraform.tfvars.example` (region, account id, alert email, budget, rotations, active slot) committed with placeholders; the real `*.tfvars` (except `*.auto.tfvars.json` for layers) and `backend.hcl` git-ignored. gitleaks keeps scanning |
| D25 | Ordering | API → distribution → Lambda env (`APP_ORIGINS = "https://${domain_name}"`) → integration → routes. The receipts CORS rule is its own resource after the distribution. The cross-module references form no resource cycle (checked by `validate` and the composition test) |
| D26 | Outputs | `distribution_domain`, `site_bucket`, `receipts_bucket`, `thumbnails_bucket`, `api_endpoint`, `dlq_url`, `table_name` (for Phase 8 `make deploy` and smoke). The origin secret is never an output |

**Lambda env (D23)**

| Key | API | Worker |
|---|---|---|
| `BREF_RUNTIME` | `fpm` | `function` |
| `BREF_PING_DISABLE` | `1` | `1` |
| `DYNAMODB_TABLE`, `RECEIPTS_BUCKET`, `THUMBNAILS_BUCKET` | yes | yes |
| `ORIGIN_VERIFY_SECRETS` (sensitive), `CLIENT_IP_HEADER=CloudFront-Viewer-Address`, `APP_ORIGINS` | yes | — |

**IAM shape (D20; the exact actions come from Step 0 R6)**

| Role | DynamoDB (table + `index/LSI1`) | S3 | Other |
|---|---|---|---|
| API | Get/Put/Update/Delete/Query/ConditionCheckItem (transactions are authorised through these) | `PutObject` on `receipts/receipts/*` only (to sign POST policies); `GetObject` and `DeleteObject` on `receipts/receipts/*` and `thumbnails/*`; `ListBucket` on receipts (condition `s3:prefix` `receipts/*`) and on thumbnails | logs on its own group |
| Worker | Get/Update (plus whatever R6 finds) | `GetObject` and `DeleteObject` on `receipts/receipts/*`; `ListBucket` on receipts (prefix `receipts/*`, so a missing key gives 404, not 403); `PutObject` and `DeleteObject` on `thumbnails/*`; `ListBucket` on thumbnails; **never `PutObject` on receipts** (plus the bucket-policy deny) | SQS Receive/Delete/GetQueueAttributes/ChangeMessageVisibility on the main queue only; logs |

## 3. Work packages

**One commit per WP, tests first, with the red run recorded in §7.3.** Strictly one after another on one branch.

### 3.1 Rules every agent brief repeats

- Make targets only, in pinned containers. Never run host `terraform`, `aws`, `php`, `composer`, `node`, `npm` or `python`.
- **Never** `terraform plan/apply/destroy/import/state/console`, or `init` with a backend. `terraform test` only through `make tf-unit`, and every test file declares `mock_provider "aws"`.
- No credentials, no `~/.aws`, no AWS CLI, no network scans. The only network use is the registry download (`tf-lock`, init) and Composer/npm downloads.
- Edit tool only (no `sed`/`perl`/`python` rewrites, no shell redirects or heredocs). Re-check any file an agent says it "recovered".
- One make target at a time, in the foreground. No background jobs or polling loops.
- No new dependency, provider or image beyond those approved at G1.
- Never commit account ids, emails, `backend.hcl` or real tfvars. The test fixtures use AWS's documented example values (`123456789012`, `d111111abcdef8.cloudfront.net`).
- Ask before every push; after a push, watch CI by run id and check `headSha`.

### 3.2 Model routing

- **Implementation:** `ecc:tdd-guide` sonnet/medium.
- **Review:** `ecc:code-reviewer` sonnet/medium for every Terraform, Makefile, shell or CI diff (one reviewer per diff).
- **Security:** `ecc:security-reviewer` opus/high on WP7 (over the WP4–WP7 diff: state bucket, bucket and queue policies, IAM, API exposure), WP8 (edge: origin lockdown, headers, CSP), and the WP11 whole-phase pass. Round 2 covers the fix hunks only.
- **Step 0:** docs questions go to `ecc:docs-lookup` haiku/low (a URL per claim, checked by the main session); the code inventory to `Explore` haiku/low; the "Ran" items are run by the main session.
- **Other:** docs `ecc:doc-updater` haiku/low; test-output summaries `general-purpose` haiku/low; build errors `ecc:build-error-resolver` sonnet/low, then opus/high.

### Step 0: Research & Reuse (before WP2), recorded at G2

| # | Question | Kind | Needed by |
|---|---|---|---|
| R1 | Terraform 1.16 `terraform test` with `mock_provider`: are computed values known at `plan`? `override_during = plan`? Is `command = apply` under mocks fully offline (no credentials, no API calls)? Can `run { module { source } }` reach `../../modules/*`? | docs | WP2 |
| R2 | HTTP API: is a fractional `throttling_rate_limit` allowed; do `route_settings` need existing route keys; precedence of `$default` vs specific routes; integration timeout maximum. Event source mapping `maximum_concurrency` minimum | docs | WP6, WP7 |
| R3 | DynamoDB `on_demand_throughput` in aws provider 6.68 (names, minimums); on-demand read/write unit prices and PITR price for the chosen region | docs | WP5, owner decision 8 |
| R4 | **Ran** (php tools container, fake credentials, no endpoint, no network): the exact hosts the SDK puts in a presigned POST and a presigned GET for the chosen region, which gives the CSP host form | ran | WP8 |
| R5 | **Ran:** the keys of `bref/bref` 3.0.12 `layers.json` (`php-85`?) and the `bref/extra-php-extensions` version that pairs with it; `gd-php-85` versions | ran | WP3 |
| R6 | SDK operations per entry point (API, worker), giving the IAM tables; which function logs each D22 message, and the exact string or field (`CorruptItem`) | Explore | WP6, WP9 |
| R7 | **Ran:** does the tools image have `ext-zip` (or `zip`)? Size of the `--no-dev` vendor folder, zipped and unzipped | ran | WP3 |
| R8 | Worker memory: re-measure with a GD-generated progressive JPEG (4:4:4 if GD can produce one) with EXIF orientation 6, as Phase 5 asked; libpng `PNG_USER_CHUNK_MALLOC_MAX` in Bref's layer build (read-only look at Bref's build files, I1) | ran + docs | WP6 |
| R9 | Does S3 → SQS delivery work with SSE-SQS, and with an `aws:SecureTransport` deny on the queue policy? | docs | WP5 |
| R10 | Managed CloudFront cache policy ids; `CloudFront-Viewer-Address` is allowed in an origin request policy whitelist; the CSP length limit of response-headers policies | docs | WP8 |
| R11 | Read-only GitHub search for Terraform layouts of Bref on Terraform without Serverless (adopt patterns; no code copied without a licence, ADR 0012) | docs | WP6 |

### WP1: Plan (S)

- **Files:** this plan, plus the PLAN §12 row 7 link.
- **Agent:** main session.
- **Commit:** `docs: add phase 7 infrastructure plan`.

### WP2: Test harness and tooling (S)

- **Files:**
  - `envs/dev/versions.tf` (+ `hashicorp/random` pinned) and the lock file (`make tf-lock`);
  - `envs/dev/tests/variables.tftest.hcl` (`expect_failures` for region, account id, environment, active slot);
  - `scripts/check-tftest-mocks.sh` + `scripts/tests/check-tftest-mocks.test.sh`;
  - Makefile `tf-unit` (mocks check, then `init -backend=false -lockfile=readonly` + `test` for `bootstrap` and `envs/dev`), added to `LINT_TARGETS`;
  - CI `terraform` job step `make tf-unit`;
  - `.gitignore` (`build/lambda/`, `package.auto.tfvars.json`, `*.tfvars`, `backend.hcl`);
  - `terraform.tfvars.example`, `backend.hcl.example`;
  - `scripts/tests/terraform-check.test.sh` still green.
- **Red:** the variables tests fail before the validations and new variables exist.
- **Commit:** `test: add offline terraform test harness`.

### WP3: Layer versions and Lambda package (M)

- **Files:**
  - `backend/composer.json`/`lock` (`bref/extra-php-extensions`, dev);
  - `scripts/bref-layers.php`, `scripts/build-lambda-zip.php`, `scripts/check-lambda-zip.sh`;
  - `scripts/tests/{bref-layers,lambda-zip}.test.sh` + fixtures (hand-written `layers.json`, tiny zips);
  - `envs/dev/bref-layers.auto.tfvars.json`;
  - Makefile `tf-layers tf-layers-check lambda-build lambda-check`;
  - CI: `tf-layers-check` in the `backend` job; `lambda-build lambda-check` in the `guards` job.
- **Tests first:**
  - a missing region or a non-integer version fails;
  - output is stable (same input gives the same bytes);
  - drift is detected;
  - the zip: a missing `app.ini` or a present `tests/` fails;
  - building twice gives the same sha256.
- **Commit:** `build: derive bref layer versions from composer.lock and build the lambda zip`.

### WP4: State bootstrap (S)

- **Files:** `bootstrap/{providers,variables,main,outputs}.tf`, `bootstrap/tests/state_bucket.tftest.hcl`.
- **Tests:** every D5 checklist item, including `prevent_destroy` (asserted through a plan of a destroy-free config; if R1 shows `prevent_destroy` can't be asserted, the code-reviewer confirms it).
- **Commit:** `feat(infra): add the terraform state bootstrap`.

### WP5: Table and receipts pipeline (M)

- **Files:** `modules/table/*`, `modules/receipts/*`, `envs/dev/tests/{table,receipts_pipeline}.tftest.hcl`.
- **Tests:**
  - table: values derived from `table.json` (keys, `LSI1` projection ALL, TTL `expiresAt`), PITR, the D15 ceiling;
  - pipeline: exactly one notification, on receipts, prefix `receipts/`, `ObjectCreated:*`, EventBridge off;
  - queue policy principal and conditions; redrive 3 to the DLQ; DLQ retention > source retention; SSE;
  - both buckets: versioning off, SSE AES256, public access block, ownership, TLS deny; abort-multipart rule;
  - CORS only on receipts, with the given origin and methods;
  - worker-role PutObject deny in the bucket policy.
- **Commit:** `feat(infra): add the table and the receipt storage pipeline`.

### WP6: Lambda module and worker (M)

- **Files:** `modules/lambda-function/*`, `modules/worker/*`, `envs/dev/tests/{lambdas,iam_worker}.tftest.hcl`.
- **Tests:**
  - runtime, architecture, handler, `BREF_*`, layer ARNs (worker has gd);
  - memory 1024, timeout 30;
  - log group name and retention 30;
  - event source mapping: batch 1, report-batch-item-failures, max concurrency 2, source = the main queue, not the DLQ;
  - worker policy: the exact action set per resource, no `PutObject` on receipts, no `*`.
- **Commit:** `feat(infra): add the lambda module and the receipt worker`.

### WP7: API (M), security-critical

- **Files:** `modules/api/*`, `envs/dev/tests/{api,iam_api}.tftest.hcl`.
- **Tests:**
  - payload 2.0; `$default` plus the 5 throttled routes; `route_settings` lower than the default; integration timeout 29000;
  - Lambda timeout 25, memory 1024, exact env key set, no `_ENDPOINT`/`APP_ENV`;
  - access-log format fields, no query string;
  - permission scoped to this API;
  - API policy exact actions (LSI1 included, `PutObject` only on `receipts/receipts/*`, `ListBucket` prefix condition).
- **Review:** code-reviewer, then **security-reviewer** over WP4–WP7.
- **Commit:** `feat(infra): add the http api and the api lambda`.

### WP8: Edge (M), security-critical

- **Files:** `modules/edge/*` (+ `functions/spa-rewrite.js`, `spa-rewrite.test.mjs`), Makefile `tf-fn-test` (`RUN_NODE`), CI `terraform` job step, `envs/dev/tests/edge.tftest.hcl`.
- **Tests:**
  - origin request policy is a whitelist of exactly 5 headers, none with `_`; cookies all; query strings all;
  - `/api/*` uses CachingDisabled, https-only, 7 methods;
  - the origin custom header is present and equals the active slot;
  - the API origin is https-only TLSv1.2 with `origin_read_timeout` 30;
  - OAC on the site origin;
  - the function is attached to the default behaviour only; no custom error responses;
  - the exact CSP string with R4 hosts; HSTS, nosniff, DENY, `same-origin`;
  - Cache-Control per behaviour;
  - the site bucket policy allows only the CloudFront service principal with `AWS:SourceArn` = the distribution.
  - Function tests: `/expenses/<uuid>` → `/index.html`; `/assets/x.js`, `/favicon.ico` and `/` unchanged.
- **Review:** code-reviewer, **security-reviewer**.
- **Commit:** `feat(infra): add cloudfront, the site bucket and security headers`.

### WP9: Observability (S)

- **Files:** `modules/observability/*`, `scripts/check-alarm-messages.sh` + test, `envs/dev/tests/observability.tftest.hcl`.
- **Tests:**
  - 6 alarms (the DLQ, the main-queue age alarm and 4 metric filters) with namespace, threshold, actions and missing-data handling;
  - filter patterns equal the R6 strings, on the right log groups;
  - budget limit and both notifications;
  - the drift guard fails when a message is renamed in a fixture copy.
- **Commit:** `feat(infra): add alarms, metric filters and the budget`.

### WP10: Composition (M)

- **Files:** `envs/dev/{main,locals,secrets,outputs}.tf`, `envs/dev/tests/composition.tftest.hcl`, `modules/README.md` (D3).
- **Tests:**
  - origin-verify slot rules (D16);
  - `APP_ORIGINS` = the distribution domain over https; the receipts CORS origin is the same;
  - visibility ≥ 6 × worker timeout;
  - timeout chain 25 < 29 < 30;
  - API and worker get the same table and buckets; the buckets differ;
  - CSP hosts match the bucket names;
  - outputs exist and the secret is not one of them;
  - `validate` passes (no cycle).
- **Commit:** `feat(infra): compose the dev environment`.

### WP11: Whole-phase pass, docs (S)

- **Security:** security-reviewer opus/high over `infra/terraform/**` and the build scripts, against §4.
- **Docs** (doc-updater, checked line by line):
  - "Implemented in Phase 7" notes on ADR 0009 (layout, offline tests), 0012 (layers file, slots, timeouts), 0006 (event source mapping and queue numbers, the bucket-policy deny), 0005/0008 (behaviours, CSP hosts, SPA function);
  - PLAN row 7 → Done; README plan list;
  - a "Phase 8 prerequisites" list in this plan.
- **Before ready:** `/update-docs`, `/code-review`, `/verification-loop`, `/ecc:cost-report` (compare with Phase 6).
- **Commit:** `docs: phase 7 infrastructure notes`.

**Dependencies:** WP2 → WP3 → WP4 → WP5 → WP6 → WP7 → WP8 → WP9 → WP10 → WP11. WP3 can trail WP4–WP5 if needed: WP6 only needs the variables.

## 4. Threats and controls

| Threat | Control | Test |
|---|---|---|
| Direct `execute-api` calls, forged `CloudFront-Viewer-Address` | Secret header (D16), whitelist origin request policy without underscore names, `$default` route so PHP always answers 403 | WP7, WP8, WP10; live check in Phase 8 |
| Origin secret exposure | Sensitive values, never an output, not committed; state bucket private, TLS-only, versioned | WP4, WP10; gitleaks |
| **Worst-case read (`getMonthlyReport`, about 3,000 RRU per call)** and list amplification | Route throttles, the DynamoDB max-throughput ceiling (D15), the budget alarm | WP5, WP7 |
| Login/Argon2id CPU flood | Route throttles on login and register; reserved concurrency decided in Phase 8; WAF in L8 | WP7 |
| Upload-policy abuse by one user starving others | Route throttle as a backstop; per-user limit deferred (owner decision 10) | WP7 |
| Processing loop | Only receipts has a notification; worker has no `PutObject` on receipts (IAM + bucket deny); thumbnails bucket sends no events | WP5, WP6 |
| Poison file kills a batch | Batch size 1; memory 1024 / 768M; DLQ alarm | WP6, WP9 |
| IAM over-grant | Exact action sets asserted; no `*`; no managed policies | WP6, WP7 |
| Public data exposure | Public access block, OAC, signed URLs only, TLS deny | WP5, WP8 |
| Personal data cached | CachingDisabled on `/api/*`, PHP `no-store` untouched | WP8 |
| SPA rewrite masking API errors | The function runs on the default behaviour only; no custom error responses | WP8 |
| Query strings (`q`, cursors) in logs | No CloudFront logs; access-log format without a query string | WP7, WP8 |
| Local endpoints reaching AWS | Exact env key set: no `*_ENDPOINT`, no `APP_ENV` | WP7, WP10 |
| An agent touching AWS | D1: no credentials in the container, mock-provider guard, rule 3.1 | WP2 |
| Accidental state loss | `prevent_destroy`, versioning, noncurrent expiry after 90 days | WP4 |
| Direct-to-S3 spend with a reused signed link: a registered user reuses one presigned GET (view URL) or POST policy for its whole 300 s validity, downloading a 10 MiB receipt repeatedly or re-posting to the same key in a loop; every request is billed and each upload queues a worker run. API throttles only limit new links; D15 does not cover S3; the $5 budget only notifies and lags by hours. Order of magnitude from the WP7 security review: about $900/day of data transfer at 1 Gbps | Accepted for the proof of concept (owner, 2026-11-04). Mitigations: queue-age alarm on the main queue in WP9; Phase 8 runbook step to attach an explicit-deny s3:GetObject/PutObject policy to the API role (this kills outstanding presigned URLs); later: shorter URL TTL (about 60 s) or receipts through CloudFront (L8) | WP9, Phase 8 |
| Shared route throttles blocked by direct execute-api calls: junk requests straight to the public execute-api address (no secret) still count against the shared route limits before PHP answers 403; about 2-5 req/s on POST /api/v1/auth/login blocks every login, 10-20 req/s at `$default` blocks everything. ADR 0012 accepted "slows users"; with these limits it stops them | Accepted for the proof of concept (owner, 2026-11-04); no IaC-only fix exists (HTTP APIs have no WAF or resource policy). Options later: higher `$default` limit (50-100 req/s) with reserved concurrency, L8 WAF | WP7, Phase 8 |

## 5. Test plan

| Layer | What | Run by |
|---|---|---|
| Static | `fmt`, `validate` (both roots, modules through `envs/dev`) | `make tf-check` |
| Offline plan tests | Every D-row marked with a test above; variable validations | `make tf-unit` (CI `terraform` job) |
| Function | SPA rewrite cases | `make tf-fn-test` |
| Build | Layer-file drift; zip contents, reproducibility, autoload in the Bref image | `make tf-layers-check lambda-build lambda-check` |
| Guards | Mocks guard, alarm-message drift, existing `tf-test` | `make test-shell`, `make tf-test` |
| Real plan, apply, smoke | **Phase 8 only, owner-gated** | — |

## 6. Branch, PR, CI and gates

- **One PR**, draft after WP5, ready after WP11, squash-merged. Ask before every push.
- **G1:** owner approves this plan and §10.
- **G2:** Step 0 recorded. A result that changes a decision is re-approved; anything that changes an ADR becomes ADR 0013, accepted before the WP that needs it (none expected).
- **G3:** owner walkthrough (no AWS): read the `make tf-unit` output, `envs/dev/main.tf` and the module README, and run `make tf-fn-test`.
- **G4:** not needed (CI gets steps only, no new or renamed job).
- **G5:** no deletions planned.
- **G6:** the owner merges.
- **AWS: none.** The first `terraform plan` with credentials, every `apply` and every `destroy` happen in Phase 8, each with the owner's explicit go-ahead.

## 7. Carried items and review notes

- **Carried in, all assigned above:** Phase 1 (table.json, env, origin request policy, log retention, zip with `app.ini`, slot pattern, whitelist with no underscores, least privilege, no `*_ENDPOINT`, timeout > 12 s); Phase 2 (Argon2 memory, the client-address alarm, IAM for transactions, reserved concurrency as a variable); Phase 3 (LSI1 IAM, access-log format, the list throttle, write throttling through the stage default); Phase 4 (SPA function, CSP, Cache-Control, Referrer, no CloudFront logs); Phase 5 (bucket env, IAM per C13, `ListBucket` prefixes, `PutObject` for signing, the upload throttle, timeout, cleanup alarm, CORS origin, CSP hosts, memory 1024 / 768M, visibility 6×, **batch 1**, concurrency cap, I1 look-up); Phase 6 (timeout math, report throttle, LSI1 IAM, access-log format).
- **Carried out to Phase 8:**
  - real plan review; apply, destroy → re-create;
  - `backend.hcl` and bootstrap state migration;
  - account Lambda concurrency quota → reserved concurrency;
  - SNS email confirmation;
  - `make deploy` (zip + layers tfvars, front-end upload with content types, invalidation);
  - `test-metric-filter` against real log lines;
  - all ADR 0012 and Phase 1/2 live checks (direct API Gateway 403, forged and underscore viewer address, `X_Origin_Verify`, duplicate cookies, encoded paths, IPv6, no `X-Powered-By`);
  - DLQ depth in smoke; report and login latency (tune memory);
  - WP6 review M1: the worker's `ListBucket` on receipts carries the condition `s3:prefix = receipts/*` (D20). S3 may not evaluate `s3:prefix` for the bucket-level check behind a missing-key `GetObject`/`HeadObject`, which would give 403 instead of 404, and the worker would retry the message three times into the DLQ (`S3ObjectStore` treats only `NoSuchKey`/404 as "missing"). Not checkable offline. Smoke test: delete a receipt, run the worker on its message, expect 404 handling. If it is 403, drop the `Condition` from the worker's `ReceiptsList` statement (the worker never lists) and update the policy test;
  - build-time bucket origins (Phase 5 L2) and the `localhost:9000` bundle check;
  - leftover smoke users.
  - WP9 addition: alarm on the main receipts queue `ApproximateAgeOfOldestMessage` (free tier) as the early signal for the direct-to-S3 abuse above.
  - WP11 addition, **the four log-message alarms must be proven on real AWS before they are trusted.** The filter patterns use `\"` inside a quoted term and, for CorruptItem, `?` with quoted terms; the AWS "Filter pattern syntax" page documents neither. Possible failures: the apply is rejected (loud, safe); the term is not read as an escaped quote and never matches (a dead alarm looks like a quiet system, because missing data is not breaching); or the term is split into several AND/OR terms and over-matches (the forgery returns, or every line fires). So `test-metric-filter` must be run on a real log line of each kind AND on the forged fixtures of `scripts/tests/alarm-patterns.test.sh` (backslashes and raw-quote forms), with exact match / non-match results, both CorruptItem alternatives separately. If any result is wrong, switch to documented JSON patterns (`{ $.message = "receipt cleanup failed" }`, `{ $.code = "CorruptItem" }`; they work only if the line reaches CloudWatch as pure JSON: confirm `log_format = "Text"` on a real line and keep it pinned by a test). After each deploy, a canary request such as `GET /CorruptItem` must not move the metric.
  - WP11 addition, backend follow-up (small, not Phase 7): add `'code' => $error->awsErrorCode` to the context of the "unhandled exception" log (`ErrorHandlingMiddleware::context()`, `ApiKernel::logFailure`) when the error is a `StorageFailure`, then drop the second CorruptItem alternative `(CorruptItem)","file"`. Today it is safe only while no exception message holds request text: `capMessage` cuts at 1024 bytes, so a future exception quoting about 1,000 bytes of input could end a value with `(CorruptItem)` right before `"file"`.
  - WP7 security review checks that only real AWS can answer:
    - S3 to SQS delivery with SSE-SQS and the queue TLS deny (R9; if it fails, drop the TLS deny first);
    - HTTP API route throttles for `$default` and specific routes, and acceptance of the values (R2);
    - real access-log lines contain no query string;
    - a direct execute-api call gets 403, plus forged-header and underscore-header checks;
    - the `s3:TestEvent` sent when the notification is created is treated as invalid by the worker and does not land in the DLQ (the DLQ alarm must not fire on day one);
    - IAM propagation timing for the event source mapping on the first apply (a retry may be needed);
    - the API Gateway account-level CloudWatch Logs role exists, or the deploy principal can create it;
    - run `aws s3api head-bucket --expected-bucket-owner <account>` on the state bucket before `init` (name squatting);
    - read the account Lambda concurrency quota before setting reserved concurrency (the API flood can starve the worker).
  - state bucket hardening to consider: deny when `s3:TlsVersion` < 1.2; scope who can read state (it holds the origin secrets) when the OIDC role (L2) is added.
  - WP8 review, for `make deploy` and the Phase 8 smoke test:
    - deploy order: upload `assets/` first, then `index.html` and the other root files (`favicon.ico`), then invalidate `/index.html` (`distribution_id` output). The edge cache keeps `index.html` up to 24 h (CachingOptimized) and the response-headers policy `no-cache` only reaches the browser, so without the invalidation a front-end fix can take a day; a missing asset answered by S3 as 403 also receives the year-long `immutable` Cache-Control of the assets policy, so uploading `index.html` before its assets can leave a browser with a cached 403 (alternative: set Cache-Control as S3 object metadata at upload instead);
    - the site bucket has no `ListBucket`, so a missing site object is 403, not 404 (check on real AWS);
    - real-AWS checks: a viewer-sent `CloudFront-Viewer-Address` or `X-Origin-Verify` is replaced or dropped and an underscore variant never arrives; API Gateway accepts CloudFront requests with `Host` not forwarded; the OAC bucket policy is accepted with BlockPublicPolicy on and the TLS deny does not block CloudFront; CSP and Cache-Control reach the browser on site and asset responses, and `/api/*` keeps PHP's `no-store` with no CSP; no CSP violation in a real browser (upload XHR, thumbnails, Vue `:style` bindings under `style-src 'self'`); the encoding of `request.uri` in CloudFront Functions (the test comment "CloudFront does not decode the uri" is unverified);
    - optional hardening, later: `default-src 'none'; frame-ancestors 'none'` on the API response-headers policy, more Permissions-Policy features, `--network none` for `tf-fn-test`, site bucket versioning.
- **Carried out, later:**
  - M1 per-user upload and report throttle (proposed new L item, owner decision 10);
  - request deadline in code (owner decision 9);
  - worker VPC (declined, owner decision 13);
  - L2 OIDC role;
  - L3 tflint/checkov (expected findings to justify: SSE-S3 not KMS, default certificate TLS floor, no WAF/logging, SNS unencrypted, no VPC/X-Ray);
  - L4, L8.
- **§7.3 Review notes:** filled per WP.
  - WP5: code-reviewer approved, no CRITICAL/HIGH/MEDIUM; one LOW renamed.
  - WP6: code-reviewer approved; MEDIUM `gd_layer_version` made non-nullable and validated (>= 1); MEDIUM s3:prefix 403/404 carried to Phase 8; R8 worker memory measured, 768M/1024 MB kept.
  - WP7: code-reviewer approved after fixing the MEDIUM (`allowed_origin` and `cors_allowed_origin` now exactly as strict as `ApiConfig::ORIGIN_PATTERN`: https only, labels 1-63, port 1-65535 without leading zero, not 443); security-reviewer (opus/high) over WP4–WP7: no CRITICAL/HIGH, 2 MEDIUM accepted (rows in §4), fixed L2 (DLQ TLS-deny policy), L3 (postcondition: uploaded zip hash equals the built hash) and I2 (`--no-plugins`); skipped L1 (worker deny principal via `aws:PrincipalArn` condition: IAM already denies `PutObject`, defence in depth only).
    - Round 2 (opus/high, fix hunks only): no CRITICAL/HIGH/MEDIUM, all five hunks sound. Four LOW carried, none exploitable from outside because every policy is built in this repo from validated ARNs: (1) the `policy_json` wildcard check is still a blocklist (`?` wildcards, `arn:aws:s3:::*/receipts/*`, `arn:aws:logs:*:*:*` and a `*` region pass); later: an allowlist where `*` appears only as one final `/*` or `:*`, and a validated `aws_region` (`^[a-z]{2}(-[a-z]+)+-[0-9]$`); (2) the code-hash postcondition detects a mismatched zip after `UpdateFunctionCode`, it does not prevent it; Phase 8 `make deploy` must re-hash the zip right before apply (`filesha256(var.lambda_package_path) == var.lambda_package_sha256`), and recovery may need `terraform apply -replace=module.<x>.aws_lambda_function.this`; (3) the `--no-plugins` static test in `scripts/tests/lambda-zip.test.sh` could match a comment or a second install line; tighten to exactly one `run([...])` line; (4) `composer config` in `scripts/build-lambda-zip.php` has no `--no-plugins` (a global plugin could load; the build copy has no vendor directory).
  - WP8: code-reviewer (sonnet/medium) approved with no CRITICAL/HIGH/MEDIUM; security-reviewer (opus/high): no CRITICAL/HIGH/MEDIUM, two LOW (the year-long `immutable` Cache-Control also lands on S3 403s; the edge caches `index.html` for up to 24 h) and six INFO, all carried to the Phase 8 list above. The agent's Makefile edit had merged two target names in `.PHONY` (`tf-layerstf-layers-check`); caught in the main-session diff review and fixed before review.
  - WP9: six alarms were built, not five (the DLQ, the main-queue age alarm at 900 s, and four log filters including CorruptItem). code-reviewer (sonnet/medium): no CRITICAL/HIGH; one MEDIUM fixed (the alarm-message test script left `build/alarm-messages-test.*` copies of `backend/src` behind on every run; a guarded EXIT trap now removes them). The CorruptItem filter sits on the API log group: the code shows `StorageFailure` messages read `DynamoDB <op> failed (CorruptItem)` and `ErrorHandlingMiddleware` logs them in `exceptionMessage`. Worker failures end in the DLQ alarm and are not watched separately. 8 mutation checks caught.
  - WP10: the root now wires all seven modules (`envs/dev/{main,locals,secrets,outputs}.tf`, 21 composition runs; `tf-unit` 267 in envs/dev + 11 in bootstrap). To let a root-level test read what the modules were given, additive outputs were added (receipts `queue_name`, `visibility_timeout_seconds`, `cors_allowed_origins`; worker `timeout_seconds`, `environment_variable_keys`, `environment_variables`; api `environment_variables`; edge `origin_verify_header_value`; the last three that can hold the secret are sensitive). No resource changed. code-reviewer (sonnet/medium): no CRITICAL/HIGH/MEDIUM, three LOW carried to WP11: (1) the rotation run only checks that `keepers` equals the input variable (wiring, not renewal of one slot); (2) the D15 ceilings (400/100, in `locals.tf`) are asserted only in the table module test, not at composition level; (3) a test can name outputs but not list them, so an extra output carrying a secret is not caught (mutation 9, not caught): add a static guard (for example in the shell tests, no `random_password` in `outputs.tf`) and keep review as the control. 8 of 9 mutation checks caught.
  - WP11: security-reviewer (opus/high) over the whole phase: no CRITICAL/HIGH; one new MEDIUM, fixed: the four log-message alarms used bare substring patterns, and the API logs request text (the path in `OriginVerifyMiddleware`/`CsrfMiddleware`, the `Origin` header in `CsrfMiddleware`), so `GET /CorruptItem` or `Origin: receipt cleanup failed` could fire or pin an alarm and hide real alerts. Fix: patterns anchored on the JSON structure (D22), with a shell test (`scripts/tests/alarm-patterns.test.sh`) that proves genuine lines match and forged lines (quotes escaped as `json_encode` writes them) do not. LOW fixed: `*.tfplan`/`tfplan`/`*.plan` git-ignored (a plan file holds secrets in plain text); `alert_email` sensitive; comments that said no output carries the secret now name the two sensitive outputs; stale phase comments. LOW carried: `scripts/terraform.sh` does not enforce the D1 command list (D1 now says so; Phase 8 needs its own gate and a pinned-image check on `TERRAFORM_IMAGE`). Round 2 on the fix hunks (opus/high): no CRITICAL/HIGH/MEDIUM; two LOW carried to the Phase 8 list above (stronger `test-metric-filter` proof with forged samples; the fragile second CorruptItem alternative), one LOW open (`check-outputs.sh` reads only `envs/dev/outputs.tf`, so an `output` block in another `.tf` file of that root is not seen). code-reviewer (sonnet/medium): no CRITICAL/HIGH/MEDIUM; one LOW fixed (a test assertion that could never fail), one LOW noted (the guard ignores `/* */` comments, which errs towards failing). The WP10 LOWs are closed by `scripts/check-outputs.sh` (exactly the seven D26 outputs, no secret), the D15 ceiling assertion at composition level, and a second rotation run (the wiring is tested; that a changed keeper makes a new secret is the random provider's documented behaviour and cannot be asserted in a test file). 5 more mutation checks on the guard and ceilings, 5 on the alarm patterns, all caught.

## 8. Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | Mocked tests pass but AWS rejects a value (names, limits) | Step 0 doc checks; Phase 8 real plan reviewed before apply |
| R2 | Mocks hide computed-value wiring bugs | `override_resource` with realistic values; composition tests; no data sources |
| R3 | The zip is not reproducible, so every plan shows a Lambda change | Fixed mtimes and order; WP3 double-build test |
| R4 | The CSP host form differs from what the SDK signs, breaking uploads or previews | R4 records the SDK's real output; WP8 test |
| R5 | Report cost amplification before the budget fires | D15 ceiling, route throttle |
| R6 | A new account's Lambda concurrency is too low for reserved concurrency or the concurrency cap | Variable default `-1`; Phase 8 reads the quota |
| R7 | `force_destroy` deletes receipts on destroy | Dev only; every destroy is owner-gated; decision 5 |
| R8 | Scope creep (OIDC, scanners, WAF) | Out-of-scope list; later phases |

## 9. Cost notes (approximate; Step 0 R3 confirms the region's prices)

- **Phase 7:** $0 (nothing is created).
- **Idle after Phase 8:** close to $0. S3 and DynamoDB storage cost cents, plus PITR at about $0.20/GB-month on a tiny table. Within the always-free tiers: 10 alarms, metric filters (as custom metrics), 5 GB of logs, CloudFront 1 TB / 10 M requests, Functions 2 M, Lambda 1 M requests / 400k GB-s, SQS 1 M, SNS email. Budgets are free. No Secrets Manager, KMS keys, NAT or VPC endpoints.
- **Light use:** under $1/month. HTTP API is about $1 per million requests; DynamoDB on-demand is about $0.125 per million read units and $0.625 per million write units (us-east-1).
- **Worst case:** bounded by D15 (for example, 400 read units/s ≈ 35 M read units/day ≈ $4/day) and caught by the $5 budget.
- x86_64 instead of arm64 costs about 20% more on Lambda compute, which is negligible at this volume.

## 10. Owner decisions (recommended defaults)

1. **Scope:** Terraform, offline tests and build tooling only; OIDC → L2, tflint/checkov → L3, Lambda/5xx alarms → L4, WAF → L8? **Recommended: yes.**
2. **Validation:** `terraform test` with mock providers (fully offline) plus `fmt`/`validate`; no real plan until Phase 8? **Recommended: yes.**
3. **Module layout D3** (7 modules, the pipeline in one)? **Recommended: yes.**
4. **Names:** random 8-hex suffix on app buckets; state bucket `expense-tracker-tfstate-<account>-<region>`? **Recommended: yes.**
5. **Dev teardown:** `force_destroy` on the 3 app buckets, DynamoDB deletion protection off, PITR on, state bucket `prevent_destroy`? **Recommended: yes.** Alternative: protection on, with a manual empty and unlock before each destroy.
6. **Sizing:** API 1024 MB / 25 s (integration 29 s, CloudFront 30 s); worker 1024 MB / 30 s; visibility 180 s; batch 1; worker concurrency 2? **Recommended: yes.**
7. **Throttles (rate/burst per second, shared by all users):** stage 10/20; reports 1/2; upload policy 1/3; list expenses 5/10; login 2/5; register 1/3? **Recommended: yes** (values may become integers if R2 forbids fractions).
8. **DynamoDB cost ceiling:** max 400 read / 100 write units per second? **Recommended: yes.** Alternative: no ceiling, relying on throttles and the budget only.
9. **Request deadline in code** (for the report and cleanup worst cases): not in Phase 7; accept the timeout residual and list it for later? **Recommended: yes.**
10. **Per-user throttle (Phase 5 M1)** on upload policy and reports: a new later item, with the gateway throttle as the backstop? **Recommended: yes.**
11. **Alarms:** DLQ, receipt cleanup, client address unavailable, CorruptItem, login-throttle clean-up (all free tier), via SNS email; account-wide budget of $5/month at 80% actual and 100% forecast? **Recommended: yes.** The email goes in uncommitted tfvars.
12. **Layer versions:** add `bref/extra-php-extensions` (dev) and commit the generated `bref-layers.auto.tfvars.json` with a CI drift check; one reproducible zip for both functions? Also add the `hashicorp/random` provider. **Recommended: yes.**
13. **Edge and network:** no custom domain (default certificate, TLS floor accepted); `PriceClass_100`; IPv6 on; no CloudFront access logs; SSE-S3 everywhere; no VPC for the worker (I3 declined)? **Recommended: yes.**
14. **Security review scope:** opus/high on WP7 (WP4–7), WP8 and the WP11 pass; code-reviewer on every WP? **Recommended: yes.**
15. **PR shape:** one PR, draft after WP5, squash-merged? **Recommended: yes.**

## Notes on confidence

- **Timeout carry item:** as written in Phase 6, it cannot be met (contradiction 1). D12 and owner decision 9 reframe it.
- **Report cost:** the numbers in contradiction 8 and D15 are the planner's own estimate from the corrected Phase 6 worst case and approximate on-demand prices. Step 0 R3 should confirm them before G1 relies on them.
- **IAM action lists:** these are the expected shape only. Step 0 R6 must produce the exact lists from the code; transaction helpers and every worker call were not traced.
- **Unconfirmed until Step 0:** the CloudFront managed policy ids and the event source mapping `maximum_concurrency` minimum (R10, R2); how mock providers behave in Terraform 1.16 (R1), on which the D2 test design depends.
