# 0013. AWS region: us-east-1

- Status: Accepted (2026-11-03)
- Date: 2026-11-03
- Amends: [0009](0009-terraform-for-infrastructure.md)

## Context

No document chose an AWS region. The value `eu-west-1` appears in `.env.example`, the Docker Compose defaults and the
unit tests, but only as a placeholder written in Phase 0. The Terraform variable `aws_region` deliberately has no
default so that the owner decides. The decision was needed before Phase 7 builds the CloudFront policy (the S3 host
that the Content-Security-Policy must allow depends on the region) and before any cost estimate.

The owner is in Uruguay. The app is a proof of concept, so a longer round trip is acceptable and low cost is preferred.

## Decision

Deploy to **us-east-1 (N. Virginia)**.

## Alternatives considered

- **sa-east-1 (São Paulo).** The lowest latency from Uruguay (roughly 20-40 ms against roughly 150 ms), but most
  services cost more there. Not needed for a proof of concept.
- **us-east-2 (Ohio).** Same prices as us-east-1 and similar latency. No reason to prefer it.
- **eu-west-1 (Ireland).** Only ever a placeholder. Farther from the owner with no benefit.

## Consequences

- Both Bref layers used by the project exist in us-east-1 (`php-85` version 20, `gd-php-85` version 1, read from the
  layer files on 2026-11-03).
- The SDK signs S3 URLs for us-east-1 with the global host form `<bucket>.s3.amazonaws.com` (observed 2026-11-03), not
  `<bucket>.s3.<region>.amazonaws.com`. The Content-Security-Policy and its tests must be built from the region
  variable, not from a fixed host.
- us-east-1 is where some global AWS features are managed (for example the CloudFront certificate region), which a
  later custom-domain decision will need.
- CloudFront `PriceClass_100` has no South American edge locations, so Uruguayan users are served from North America.
  That fits the low-cost goal of a proof of concept.
- The local placeholders (`eu-west-1`) are changed to `us-east-1` in Phase 7 so that the local stack matches.
- Changing region later means a new deployment, because resources are regional.
