# 0007. Local development with Docker Compose and emulators

- Status: Proposed
- Date: 2026-10-07

## Context

The brief requires that the whole system run locally without an AWS account, and deploy to AWS afterwards. The AWS
architecture uses Lambda, API Gateway, DynamoDB, S3, SQS and CloudFront.

## Decision

Use one `docker-compose.yml`:

| AWS | Local service |
|---|---|
| API Gateway + Lambda (API) | `api`: nginx + php-fpm running the same `public/index.php` |
| DynamoDB | `dynamodb`: `amazon/dynamodb-local` |
| S3 | `minio`: MinIO, with buckets created at start-up |
| S3 → SQS → worker Lambda | `worker`: receives MinIO's webhook bucket notifications (S3 event format) and calls the same PHP handler as the Lambda worker |
| CloudFront + S3 site | `frontend`: Vite dev server, proxying `/api` to `api` |

- **Only configuration differs** between local and AWS: endpoints and credentials, set by environment variables.
  Application code doesn't check where it is running.
- **A Makefile** wraps common commands: `make up`, `make test`, `make lint`, `make seed`.
- **The same Compose stack** runs integration and end-to-end tests in CI.

## Alternatives considered

- **LocalStack.** Emulates many AWS services in one container, including SQS and S3 events. It is heavier, some
  features need a paid tier, and its licensing has been changing. The official DynamoDB Local plus MinIO cover our
  needs without an account.
- **Developing against a real AWS dev account.** Contradicts the brief, costs money and needs internet access.

## Consequences

- Fast, free, offline development, with the same PHP code paths as production.
- The event path differs: MinIO webhook locally, S3 → SQS on AWS. Only the worker's entry point differs. The handler
  is shared and tested, and a post-deploy smoke test covers the AWS path.
- Developers need Docker installed.
