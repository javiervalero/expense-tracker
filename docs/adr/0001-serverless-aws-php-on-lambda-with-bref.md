# 0001. Serverless AWS architecture, PHP on Lambda with Bref

- Status: Proposed
- Date: 2026-10-07

## Context

The application must run on AWS, with a vanilla PHP back-end (no framework). It is a proof of concept with very
low and irregular traffic. The owner's stated preference is to use serverless services wherever a serverless option
exists, so that nothing runs, and almost nothing is billed, while the app is idle.

AWS Lambda has no built-in PHP runtime. PHP needs a *custom runtime*: a layer containing the PHP binary and a small
bootstrap that passes Lambda events to PHP.

## Decision

- Use only serverless or pay-per-use AWS services: Lambda, API Gateway (HTTP API), DynamoDB, S3, SQS, CloudFront,
  CloudWatch.
- Run PHP on Lambda using **Bref's runtime layers**, used purely as the PHP runtime:
  - the API runs on the PHP-FPM layer, so a normal `public/index.php` front controller works unchanged;
  - the receipt worker runs on the function layer.
- Do **not** use Bref's Laravel or Symfony integrations, or the Serverless Framework. Deployment is done with
  Terraform ([ADR 0009](0009-terraform-for-infrastructure.md)).
- Pin Bref layer versions.

Bref is a runtime and packaging tool, not an application framework: no Bref code appears in the application's own
classes. Reviewers should confirm this matches the "no framework" constraint.

## Alternatives considered

- **ECS Fargate (nginx + php-fpm containers) behind a load balancer.** Closest to local development and has no cold
  starts, but it runs (and bills) around the clock: about $50+/month more once the load balancer and outbound
  networking are included. Not serverless.
- **EC2 / Lightsail instance.** Cheapest always-on option, but it is a server to patch and operate, and it scales
  poorly. Not serverless.
- **Writing our own PHP Lambda runtime.** Possible, but it reimplements what Bref already maintains and tests well.

## Consequences

- Cost is close to $0 when idle and grows with use.
- No servers to patch; scaling is automatic.
- Cold starts: the first request after idle takes about 0.3–0.5 s longer. Acceptable for a proof of concept.
- Local development runs the same PHP code under php-fpm ([ADR 0007](0007-local-development-with-docker-compose.md)),
  so the code must not depend on long-lived process state (it doesn't anyway with PHP's request model).
- We depend on Bref's layers being kept up to date. Mitigated by pinning versions and testing upgrades in CI.
