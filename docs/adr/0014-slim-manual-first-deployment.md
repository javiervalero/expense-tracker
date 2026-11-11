# 0014. Phase 8: a slim, owner-run manual deployment

- Status: Accepted (2026-11-11)
- Date: 2026-11-11
- Amends: [0009](0009-terraform-for-infrastructure.md) (the bootstrap state stays local), [0010](0010-github-actions-ci-cd-with-oidc.md) (the manual deploy path until L2)
- Supersedes: most of [`../plans/phase-8-first-deployment.md`](../plans/phase-8-first-deployment.md) as approved at G1 on 2026-11-09 (list under Consequences)

## Context

The project is a proof of concept for testing ECC in a software development lifecycle. Its main goal is a working local
development environment. A manual deployment to AWS is secondary, and it should be as simple as possible: something the
owner can repeat alone for each new version.

The Phase 8 plan approved at G1 had 9 work packages, 9 stages, about 20 approval gates and 41 checks. Its tooling (a
646-line credentialed wrapper with typed `/dev/tty` approvals, plan manifests, recovery subcommands, an allow-listed AWS
CLI container, a plan-review script) existed to keep credentials away from agents while still letting agents drive the
work. The owner has ruled that out: agents never hold AWS credentials, and the owner runs every AWS command. That rule
makes most of the tooling unnecessary.

Fixed and unchanged: the target is the shared sandbox AWS account with admin SSO, region us-east-1 (0013), budget
$5/month.

## Decision

- Deploy by hand from one runbook, `docs/deploy.md`, using a few Make targets and a small `scripts/terraform-aws.sh`
  (about 25 lines). That script is the existing pinned Terraform container plus the three AWS credential variables passed
  through from the owner's shell. It refuses to start without a session token, so long-lived keys cannot be used.
- Approval is the owner reading the saved plan (`plan -out`) and applying that plan file. Terraform refuses a stale plan.
  There are no typed hashes, plan manifests, plan-age limits or recovery tooling.
- The host AWS CLI v2 (already needed for `aws sso login`) runs the few direct AWS calls: the S3 upload, the CloudFront
  invalidation and read-only checks. There is no pinned AWS CLI image and no command allow-list.
- The bootstrap root keeps its local state, backed up outside the repository. It is not migrated into the bucket.
- Verification is `smoke.sh --cross-user`, a browser walkthrough and a few manual `curl` and CLI checks. The 41-row
  matrix, the alarm-filter proof and the 48-hour observation window are dropped.
- A destroy at the end proves "created and destroyed from code". Re-creating afterwards is optional.
- Kept unchanged: the Terraform image pinned by digest, `allowed_account_ids`, `prevent_destroy` on the state bucket,
  the budget and alarms, the Terraform cost limits, no credentials in CI, and agents offline only (no agent session open
  while an SSO login is live).

## Alternatives considered

- **Finish the heavy WP2 tooling as planned.** Rejected: far more code, tests and review than a proof of concept
  warrants, and more to maintain than the owner can repeat by hand. The unreviewed work is kept on the side branch
  `archive/phase-8-wp2-heavy`, which is never merged.
- **Trim the wrapper but keep the typed approvals and plan review.** Rejected: the owner reading the plan already is the
  approval, and the remaining guardrails cover the real money and account risks.
- **Automate the deployment in CI now.** Rejected for this phase: that is L2 (OIDC `deploy.yml`), which can later call
  the same Make targets non-interactively.

## Consequences

- The runbook is short enough for the owner to repeat, and there is much less to build, test and review.
- There are fewer automatic checks against mistakes. A wrong command is caught by the owner reading it, by
  `allowed_account_ids` and by Terraform's stale-plan check, not by wrapper refusals. Accepted for a sandbox-only proof of
  concept.
- AWS-only behaviours stay unproven unless the smoke run or a manual check hits them; the results file records what was
  seen.
- `docs/deploy.md` moves from Phase 9 into Phase 8.
- Parts of the Phase 8 plan superseded by this ADR: section 1 completion criteria; section 3.1 O2-O6 (O1 stays as one
  rule); section 3.2 T1-T13 except the `/*` invalidation; WP2, WP3, WP5, WP7 and WP8; WP6's state migration; the section 5
  stages; the section 6 gate ladder (G2, G3, A0-A14); the section 8 verification matrix; and decisions 4, 7 (the Lambda
  reserved concurrency stays at -1, no quota probe), 8, 9, 10, 14 (no 48-hour window) and 20. Decisions 1, 2, 5, 6, 11, 12,
  15, 16, 18, 19 and 21 are kept.
- The old plan text stays in git history at `2840881`.
