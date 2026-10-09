# Architecture Decision Records

An ADR is a short document that records one significant decision: the context, what was decided, which alternatives
were considered, and the consequences. ADRs are never rewritten after the fact. If a decision changes, a new ADR
supersedes the old one, so the history of *why* stays readable.

**Statuses:** `Proposed` → `Accepted` | `Rejected`; later possibly `Superseded by NNNN`. When a later ADR changes only part of a decision, the old ADR stays Accepted and gets an `Amended by: NNNN` line under its status.

ADRs 0001–0011 were **Accepted** on 2026-10-09, when the plan in [`../PLAN.md`](../PLAN.md) was approved after review on PR #1.

| # | Title | Status |
|---|---|---|
| [0001](0001-serverless-aws-php-on-lambda-with-bref.md) | Serverless AWS architecture, PHP on Lambda with Bref | Accepted |
| [0002](0002-dynamodb-single-table-data-model.md) | DynamoDB single-table data model | Accepted |
| [0003](0003-vanilla-php-backend-structure.md) | Vanilla PHP back-end structure | Accepted |
| [0004](0004-server-side-sessions-with-cookies.md) | Server-side sessions with secure cookies | Accepted |
| [0005](0005-single-origin-through-cloudfront.md) | Single origin for app and API through CloudFront | Accepted |
| [0006](0006-direct-to-s3-receipt-uploads-and-async-processing.md) | Direct-to-S3 receipt uploads and asynchronous processing | Accepted |
| [0007](0007-local-development-with-docker-compose.md) | Local development with Docker Compose and emulators | Accepted |
| [0008](0008-frontend-stack.md) | Front-end stack | Accepted |
| [0009](0009-terraform-for-infrastructure.md) | Terraform for infrastructure as code | Accepted |
| [0010](0010-github-actions-ci-cd-with-oidc.md) | GitHub Actions CI/CD with OIDC | Accepted |
| [0011](0011-openapi-contract.md) | OpenAPI 3.1 contract as the source of truth for the API | Accepted |
| [0012](0012-runtime-platform.md) | Runtime platform: PHP 8.5, x86_64, Bref 3 configuration and client IP | Proposed |

## Template

```markdown
# NNNN. Title

- Status: Proposed
- Date: YYYY-MM-DD

## Context
What problem or force makes a decision necessary.

## Decision
What we will do.

## Alternatives considered
Other options and why they were not chosen.

## Consequences
What becomes easier, what becomes harder, what we must watch.
```
