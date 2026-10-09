# 0010. GitHub Actions CI/CD with OIDC

- Status: Accepted (2026-10-09)
- Date: 2026-10-07
- Revised: 2026-10-09 (PR #1 review comment #9: phasing)

## Context

The brief expects automated tests and a CI pipeline. The repository will be hosted on GitHub. Deploying to AWS
creates billable resources, so deployments must be deliberate, and AWS credentials must not leak.

## Decision

- **CI (`.github/workflows/ci.yml`) on every pull request and push to `main`:**
  - PHP: PHP-CS-Fixer, PHPStan, PHPUnit (with DynamoDB Local, the S3 emulator and ElasticMQ as service containers);
  - front-end: ESLint, Prettier, `vue-tsc`, Vitest, build;
  - end-to-end: Playwright against the Docker Compose stack;
  - Terraform: `fmt -check`, `validate`, tflint, checkov.

  These checks are required before merging into `main`.
- **CD (`.github/workflows/deploy.yml`):** **started manually only**, and protected by a GitHub environment that
  requires approval. Steps:
  1. build the Lambda zip and the front-end;
  2. `terraform plan`;
  3. approval;
  4. `terraform apply`;
  5. upload the front-end to S3 and invalidate CloudFront;
  6. smoke test.
- **AWS authentication via GitHub OIDC:** an IAM role trusts only this repository (and the deploy environment). No
  long-lived AWS keys are stored in GitHub.

## Alternatives considered

- **Storing AWS access keys as GitHub secrets.** Long-lived credentials that can leak; OIDC removes them.
- **Automatic deploy on merge.** Convenient, but every deploy has cost and impact; manual approval fits a proof of
  concept with an owner who is learning.
- **AWS CodePipeline / CodeBuild.** AWS-native, but more setup and less transparent than workflow files in the
  repository.

## Consequences

- Every change is checked the same way; the main branch stays green.
- Deploys are deliberate and auditable in the Actions history.
- The OIDC role and its trust policy have to be created once, by Terraform, and kept least-privilege.
- **Phased delivery** ([`../PLAN.md`](../PLAN.md) §12). The essential track delivers CI with PHP, front-end,
  contract and `terraform fmt`/`validate` checks. Playwright (L1), the OIDC deploy workflow (L2) and tflint/checkov
  (L3) follow after the first deployment. Until L2, the owner deploys with `make deploy` and short-lived AWS SSO
  credentials; the workflow later calls the same script, so no work is wasted.
