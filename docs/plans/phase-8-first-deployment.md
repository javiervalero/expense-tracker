# Phase 8: First AWS deployment (slim, owner-run)

| | |
|---|---|
| Status | **Slimmed on 2026-11-11 by [ADR 0014](../adr/0014-slim-manual-first-deployment.md).** Nothing here has been executed and no AWS operation is authorized yet; the owner decides each step when running the runbook. |
| Phase | 8 (PLAN §12 row 8), size S-M |
| Replaces | The 694-line plan approved at G1 on 2026-11-09 (9 work packages, 9 stages, about 20 gates). It stays in git history at commit `2840881`; ADR 0014 lists exactly which parts it supersedes. |
| Inputs | `docs/BRIEF.md`; `CLAUDE.md`; ADRs 0009, 0010, 0012, 0013, 0014; `docs/plans/phase-8-step0-results.md` (AWS research, Q1-Q8); `infra/terraform/**`; `Makefile`; `scripts/terraform.sh`, `scripts/smoke.sh` |
| AWS | None while this is a plan. The owner runs every AWS command in their own terminal. **Agents never hold AWS credentials.** |

## 1. Context

The project is a proof of concept that tests ECC across a development lifecycle. The app must run in local development.
A manual AWS deployment comes second and must be as simple as possible, so the owner can repeat it alone for each new
version. Fixed: shared sandbox account with admin SSO, region us-east-1 (ADR 0013), budget $5/month, alert address
supplied by the owner in uncommitted tfvars.

## 2. Goal and completion criteria

**Goal:** `envs/dev` runs in the sandbox account, the app works end to end through CloudFront, the owner deployed it alone
from `docs/deploy.md`, and one destroy has run.

Complete when all of these hold and are recorded in `docs/plans/phase-8-results.md`:

1. The first deployment passes `scripts/smoke.sh --cross-user https://<distribution_domain>` and a browser walkthrough
   with no CSP errors in the console.
2. A second "new version" run of the runbook (build, plan, apply, publish, smoke) succeeds, and its plan shows only the
   expected changes.
3. The destroy plan was applied. The leftover check finds only the state bucket and the documented DynamoDB system backup.
4. The results file lists what was run, pass or fail, surprises, and the next-day billing order of magnitude.
5. `make lint`, `make tf-test` and `make test-shell` are green, CI has no credentials, `make secrets-scan` is clean,
   and PLAN row 8 is Done.

## 3. Rules

- **Agents offline only.** No agent session while an SSO login is live (O1). Agents receive pass or fail summaries, never
  raw state, plans or `terraform show` output. The one exception is `make deploy-plan-summary`, which prints only counts and
  resource types (ADR 0014 amendment, 2026-11-12).
- **Owner-read plans.** The owner reads the saved plan (`plan -out`) and applies that file. No `-auto-approve` on a fresh
  plan. Plan files are deleted after apply because they hold generated secrets.
- **Short-lived credentials only.** `scripts/terraform-aws.sh` forwards the three SSO variables and refuses to start
  without a session token.
- **Kept guardrails:** Terraform image pinned by digest, `allowed_account_ids` on both providers, `prevent_destroy` on
  the state bucket (never destroyed), budget alarm with a confirmed SNS subscription, Terraform cost limits, bundle secret
  and CSP checks before publishing, git-ignored tfvars, state and plans, no credentials or deploy job in CI.
- **Dropped:** typed hash approvals, plan manifests, recovery tooling, `review-plan.sh`, the pinned AWS CLI image and
  allow-list, the bootstrap state migration, the 41-row verification matrix, the alarm-filter proof, and the 48-hour window.

## 4. Runbook (owner's terminal; the detailed version is `docs/deploy.md`)

First time only:

1. Copy the three example files and fill them in: `infra/terraform/envs/dev/terraform.tfvars`,
   `infra/terraform/envs/dev/backend.hcl`, `infra/terraform/bootstrap/terraform.tfvars`.
2. `aws sso login --profile <p>`, then `eval "$(aws configure export-credentials --profile <p> --format env)"`.
   `aws sts get-caller-identity --query Account --output text` must equal the tfvars account id.
3. Bootstrap the state bucket: `scripts/terraform-aws.sh -chdir=infra/terraform/bootstrap init -input=false -lockfile=readonly`,
   then `plan -input=false -out=bootstrap.tfplan`. Read it: 7 bucket resources, 0 to destroy.
4. `apply -input=false bootstrap.tfplan`, delete the plan file, and copy `bootstrap/terraform.tfstate` to a private folder
   outside the repo. That file is the only record that the bucket is managed.
5. `make deploy-init`.

Each version:

6. `make deploy-build` (Lambda zip, layer check, front-end build, bundle secret and CSP checks, worktree secrets scan).
7. `make deploy-plan`. Read it: no unexpected destroy or replace, names start `expense-tracker-dev-`.
8. `make deploy-apply` (re-export credentials first if the session is old).
9. `make publish-site`: assets first, other root files next, `index.html` last with an explicit `text/html` type, then a
   CloudFront invalidation of `/*`. Values come from `terraform output`. No `--delete`.
10. First time only: confirm the SNS subscription email.
11. Checks: `make deploy-smoke`; browser walkthrough; the `api_endpoint` output plus `/api/v1/health` must return 403;
    `curl -I` of `/` and one asset for content type and cache headers; at least 10 minutes after the smoke, the DLQ depth
    must read 0.
12. `aws sso logout`.

Destroy: `make deploy-plan DESTROY=1`, read it (envs/dev only), then `make deploy-apply`. Check leftovers by project tag,
log groups and the next day's billing. Wait at least 60 seconds before any re-create (SQS queue-name reuse).

## 5. Work packages (one branch, one PR)

| # | Work | Size | Notes |
|---|---|---|---|
| A | ADR 0014, ADR index, amended-by lines, this plan, PLAN rows 8 and 9 | S | Done in this step |
| B | New `scripts/terraform-aws.sh` (about 25 lines) and its test | S | Copy of `scripts/terraform.sh` plus the three credential variables; refuses an empty session token; ignores a `TERRAFORM_IMAGE` override; the digest must equal the one in `terraform.sh`. `terraform.sh` stays offline and unchanged. Security review (opus/high). |
| C | Export `distribution_id` (old WP4) | S | Eighth output of `envs/dev`; update `scripts/check-outputs.sh`, its test and the `tf-unit` help text. No other output. |
| D | Bootstrap housekeeping (trimmed old WP6) | S | `bootstrap/terraform.tfvars.example`; fix the stale comments in `bootstrap/main.tf` and `bootstrap/versions.tf` to say the state stays local and is backed up. |
| E | Make targets | S | `deploy-build`, `deploy-init`, `deploy-plan [DESTROY=1]`, `deploy-apply`, `publish-site`, `deploy-smoke`. In `.PHONY`, not in `LINT_TARGETS`. `publish-site` refuses an empty session token. |
| F | `docs/deploy.md` | M | The runbook plus one-line fixes for `QueueDeletedRecently` (wait, plan again), a stuck lock (`force-unlock` after confirming nothing runs) and `errored.tfstate` (`state push` it, then delete it, because it holds secrets). |
| G | After the owner's run: `phase-8-results.md`, PLAN rows, README lines | S | docs only (doc-updater, haiku/low; main session checks). |

Old work packages: WP1 merged (PR #13); WP2, WP3, WP5, WP8 dropped; WP4 is C; WP6 is D; WP7 is E; WP9 is G.
The unreviewed heavy WP2 code is kept, never merged, on the local branch `archive/phase-8-wp2-heavy`.

Order: A, then B to E in any order, then F, then the owner runs the runbook, then G. Each code package is test-first
(tdd-guide, sonnet/medium); the diff gets one `ecc:code-reviewer` pass (sonnet/medium) and B gets a security review.
The gates before merge are `make lint`, `make tf-test` and `make test-shell`, run by the main session.

## 6. Risks that still matter (unverified AWS behaviour)

- **Credential lifetime.** SSO credentials may expire during a long CloudFront create or delete. If the state write
  fails, Terraform writes `errored.tfstate` (secrets, git-ignored). Re-export just before apply; `deploy.md` has the
  `state push` recovery line. The real lifetime and the `env` output format are unconfirmed (Q1).
- **Shared-account setup, untested:** Budgets permission for the SSO role, the API Gateway account-level CloudWatch Logs
  role, CloudFront verification of a new account, IAM propagation making the event source mapping fail once, and access to
  the Bref layers. Each would show up as a partly failed first apply; a fresh plan and apply usually fixes it.
- **Budget.** It is account-wide and lags by hours, so other people's spend can trigger it, and it is the only money
  backstop. The alarm filters were never proven on real CloudWatch, so they may stay silent.
- **Content types.** `aws s3 sync` guesses MIME types, so check them with `curl -I`.
- **Destroy leftovers.** DynamoDB keeps a system backup of a deleted table for 35 days. Late invocations can recreate
  untagged log groups.
- **Local bootstrap state.** Losing it (for example by `git clean -fdx`) means a `terraform import`. Back it up.
- **Procedural isolation only.** Files in the worktree and the `~/.aws/sso` cache are readable by any process of the
  same user, and `docker inspect` shows the forwarded credentials while Terraform runs. Accepted for a sandbox.
- **Limits during smoke runs.** The register limit of 5 per hour and the login throttle can produce 429 responses.

## 7. Later (not Phase 8)

OIDC role and `deploy.yml` (L2, which can call the same Make targets), tflint and checkov, Lambda-error and API-5xx
alarms, WAF, a custom domain, Playwright, per-user upload throttling, build-time bucket origins in the front end, an
AWS-only smoke script and the S3-host pin in `smoke.sh`, and any agent-driven AWS automation.
