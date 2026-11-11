# 0009. Terraform for infrastructure as code

- Status: Accepted (2026-10-09)
- Amended by: [0012](0012-runtime-platform.md) (x86_64, provided.al2023, handlers, BREF_RUNTIME, layer versions from composer.lock), [0013](0013-aws-region-us-east-1.md) (AWS region us-east-1), [0014](0014-slim-manual-first-deployment.md) (the bootstrap state stays local, backed up outside the repo)
- Date: 2026-10-07

## Context

Infrastructure must be defined as code, and it must be possible to create and destroy it from code. The resources
are Lambda (with Bref layers), API Gateway, DynamoDB, S3, SQS, CloudFront, IAM, CloudWatch and AWS Budgets.

## Decision

- Use **Terraform** (≥ 1.10) with the AWS provider.
- **State** lives in a versioned, encrypted S3 bucket, with S3-native state locking (`use_lockfile`). That bucket
  is created by a tiny separate `infra/terraform/bootstrap` configuration.
- **Layout:** reusable modules in `infra/terraform/modules/` and one environment, `infra/terraform/envs/dev/`.
  More environments can be added later.
- **Tagging:** every resource is tagged (`project`, `env`, `managed-by=terraform`) so costs and leftovers are easy
  to find.
- **Budget alarm:** an AWS Budgets alert is part of the stack.
- **Workflow:** `terraform plan` output is reviewed before every `apply`. `apply` and `destroy` are only run with the
  owner's explicit approval.

## Alternatives considered

- **AWS CDK.** Infrastructure in a programming language (usually TypeScript). Powerful, but it adds another
  language and toolchain, and the generated CloudFormation is harder to read.
- **AWS SAM / CloudFormation.** AWS-native and good for serverless, but more verbose, and AWS-only knowledge.
- **Serverless Framework.** Common with Bref, but its v4 licensing has changed. Using Terraform for everything keeps
  one tool.
- **OpenTofu.** An open-source fork of Terraform, compatible with this setup. We could switch to it later with
  minimal change.

## Consequences

- Clear `plan` → `apply` → `destroy` cycle; widely used skills.
- We manage the state bucket ourselves, through the bootstrap step.
- Packaging the PHP code for Lambda (a zip with `vendor/`) is a build step in CI, outside Terraform.

## Implemented in Phase 7

The code is in `infra/terraform/`. The plan is [the Phase 7 plan](../plans/phase-7-infrastructure.md) (decision D3);
the module list is in [infra/terraform/modules/README.md](../../infra/terraform/modules/README.md).

- **Layout:** `bootstrap/` creates the state bucket (the bucket where Terraform records what it has built). `modules/`
  holds seven reusable modules. `envs/dev/` is the dev environment that calls them.
- **Offline tests:** `terraform test` checks the configuration against a mock AWS provider (`mock_provider "aws"`, a
  stand-in for AWS that makes no calls). `make tf-unit` runs them. Nothing touches AWS: no credentials are used and no
  plan or apply runs (decisions D1 and D2).
