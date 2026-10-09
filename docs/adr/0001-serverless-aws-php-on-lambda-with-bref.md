# 0001. Serverless AWS architecture, PHP on Lambda with Bref

- Status: Accepted (2026-10-09)
- Date: 2026-10-07
- Revised: 2026-10-09 (PR #1 review comment #5; Bref 3.0)

## Context

The application must run on AWS, with a vanilla PHP back-end (no framework). It is a proof of concept with very
low and irregular traffic. The owner's stated preference is to use serverless services wherever a serverless option
exists, so that nothing runs, and almost nothing is billed, while the app is idle.

AWS Lambda has no built-in PHP runtime. PHP needs a *custom runtime*: a layer containing the PHP binary and a small
bootstrap that passes Lambda events to PHP.

"No framework" is a standing requirement for this and future projects, so whatever provides the runtime must not
leak into the application's own code.

## Decision

- Use only serverless or pay-per-use AWS services: Lambda, API Gateway (HTTP API), DynamoDB, S3, SQS, CloudFront,
  CloudWatch.
- Run PHP on Lambda using **Bref 3's runtime layer** (Amazon Linux 2023), used purely as the PHP runtime. Bref 3
  ships one unified PHP runtime instead of the separate FPM and function layers of Bref 2:
  - **API:** runs in PHP-FPM mode. API Gateway events are converted to FastCGI requests to php-fpm, so the normal
    `public/index.php` front controller runs unchanged and contains no Bref code.
  - **Receipt worker:** runs in function mode. Its handler is `entrypoints/lambda/worker.php`, a file that **returns
    a plain PHP closure** (`fn (array $event): array`). We do not implement Bref's `Handler`/`SqsHandler` classes.
    Partial batch failures are reported with Lambda's documented `batchItemFailures` array.
- **Composer runtime dependencies: `aws/aws-sdk-php` and `bref/bref` only.** `bref/bref` is required because the
  layer's bootstrap runs Bref's runtime code from `vendor/`. Application code never imports it.
- **Bref is confined to the edge.** Only files under `entrypoints/lambda/` and the Terraform layer references are
  Bref-specific. All logic lives in runtime-agnostic classes under `src/` (for example
  `Receipts\S3EventParser` and `Receipts\ReceiptProcessor`). A CI check fails if `src/` or `public/` references
  `Bref\` or `AWS_LAMBDA_*`.
- Do **not** use Bref's Laravel or Symfony integrations, or the Serverless Framework. Deployment is done with
  Terraform ([ADR 0009](0009-terraform-for-infrastructure.md)).
- Pin the `bref/bref` version and the matching layer version together.

### To verify in Phase 0 (no AWS needed: use Bref's official Docker images)
- How Bref 3's unified runtime is configured for FPM mode vs function mode, and the exact transitive packages
  `bref/bref` installs (`composer show --tree bref/bref`), recorded here.
- GD is available in the base layer; otherwise add the `extra-php-extensions` layer (v3, aligned with Bref).
- `PASSWORD_ARGON2ID` is defined in the layer's PHP build.
- Which `$_SERVER` value carries the client IP under FPM mode (needed for per-IP login throttling).
- The Bref image runs under the Lambda Runtime Interface Emulator for local worker tests
  ([ADR 0007](0007-local-development-with-docker-compose.md)).

## Alternatives considered

- **ECS Fargate (nginx + php-fpm containers) behind a load balancer.** Closest to local development and has no cold
  starts, but it runs (and bills) around the clock: about $50+/month more once the load balancer and outbound
  networking are included. Not serverless.
- **EC2 / Lightsail instance.** Cheapest always-on option, but it is a server to patch and operate, and it scales
  poorly. Not serverless.
- **Writing our own PHP Lambda runtime.** Possible, but it reimplements what Bref already maintains and tests well.
- **Implementing Bref's `SqsHandler` interface in the worker.** Typed events, but it puts Bref types into our code.
  A plain closure returning the documented Lambda response shape does the same job.

## Consequences

- Cost is close to $0 when idle and grows with use.
- No servers to patch; scaling is automatic.
- Cold starts: the first request after idle takes about 0.3–0.5 s longer. Acceptable for a proof of concept.
- Local development runs the same PHP code under php-fpm ([ADR 0007](0007-local-development-with-docker-compose.md)),
  so the code must not depend on long-lived process state (it doesn't anyway with PHP's request model).
- **Portability.** Moving to containers (for example ECS) means no changes to the API code. The worker needs one new
  entry point (for example an SQS long-poll loop) that reuses the same core classes, and `bref/bref` is removed from
  Composer. Most of the work is infrastructure, not application code.
- We depend on Bref's layers being kept up to date. Mitigated by pinning versions and testing upgrades in CI.
