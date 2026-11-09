# Phase 8: First AWS deployment. Execution plan

| | |
|---|---|
| Status | **Draft, awaiting G1 (owner approval of scope, architecture and the decisions in §11).** Nothing in it has been approved or executed. This version is simplified for the selected **Option B** operating model (§3.1): a personal proof of concept, the owner runs every AWS command, no AI agent holds AWS credentials. The earlier, heavier designs for agent isolation are deferred (§14). Section 13 records how the plan was built and reviewed. |
| Phase | 8 (PLAN §12 row 8, essential track), size M |
| Drafted | 2026-11-07 (first draft); reconciled with a second independent plan and simplified for Option B on 2026-11-09, on branch `feat/phase-8-first-deployment`, from `main` at `f5690dc` (Phase 7 merged in PR #11) |
| Inputs | `docs/BRIEF.md`; `CLAUDE.md` (guardrails); PLAN §4, §9, §10, §12, §13; ADRs 0005, 0006, 0009, 0010, 0012, 0013; `docs/plans/phase-7-infrastructure.md` and `phase-7-step0-results.md`; Phase 1, 2 and 6 "verify on AWS" lists; `infra/terraform/**`; `Makefile`; `scripts/**`; `.github/workflows/ci.yml`; `frontend/` build configuration |
| AWS | **None while this plan is a draft.** No AWS CLI, Terraform plan/apply/destroy, credentials or API call was used to write it. Every AWS call listed below is run by the owner in the execution stages of §5, each behind an approval gate (§6). |
| Cost of this plan | $0. Cost of executing it: see §10 (estimates only, nothing verified on an account). |

**How to read the evidence tags.** Each important claim carries one of these:

- **(F)** a fact read from the repository, with `file:line` (lines as of the working tree of `feat/phase-8-first-deployment` on 2026-11-09, which is `f5690dc` plus uncommitted documentation edits to `docs/PLAN.md` (one table row added, so its later lines moved by 1), ADR 0009, `docs/local-dev.md` (lines after 433 moved) and the Phase 7 plan (no line moved); every citation was re-checked against that tree; `PLAN.md` means `docs/PLAN.md`, `adr/…` means `docs/adr/…`, and bare `phase-N-…md` names are in `docs/plans/`).
- **(A)** an assumption: something believed about AWS or the owner's setup that no repository file proves.
- **(U)** unverified AWS behaviour: the repository already treats it as open, or it can only be answered on a real account. §9 collects these. Nothing in this plan records an AWS result, because none exists.
- **[exists]** / **[proposed: WPn]** next to a command: the command is implemented in the repository today, or it is a design that a work package must still build and test. Nothing marked *proposed* exists.

**Scope (PLAN §12 row 8, `PLAN.md:273`):** deploy `infra/terraform/envs/dev` to AWS for the first time, publish the front end and the Lambda package, run the smoke tests on AWS, including the AWS-only checks, then run one destroy, re-create and smoke test. Every apply and destroy only with the owner's explicit go-ahead.

**Not in scope:** the GitHub OIDC role and `deploy.yml` (L2), tflint/checkov (L3), Lambda-error and API-5xx alarms (L4), WAF (L8), a custom domain, Playwright (L1), the per-user upload and report throttle (Phase 5 M1) and build-time bucket origins in the front end (Phase 5 L2), `docs/deploy.md` and `architecture.md` (Phase 9, which turns the results of this phase into the runbook), agent-driven AWS automation and agent isolation (§14), and any application code change that is not a bug found by the deployment.

## Contradictions and gaps found while planning

1. **`distribution_id` is needed but not exported.** The Phase 7 plan tells `make deploy` to invalidate CloudFront "(`distribution_id` output)" (`phase-7-infrastructure.md:346`). The edge module has that output (`modules/edge/outputs.tf:9`), but `envs/dev/outputs.tf` exports exactly seven values (`outputs.tf:4-37`) and `scripts/check-outputs.sh:3-5` fails if the set is not exactly those seven. → **WP4** adds an eighth output and amends D26; decision 12.
2. **`make deploy` does not exist.** PLAN and ADR 0010 name it (`PLAN.md:226`, `PLAN.md:273`, `adr/0010-github-actions-ci-cd-with-oidc.md:46-48`), but the Makefile has no `deploy`, `tf-plan` or `tf-apply` target (`Makefile:38`, `Makefile:523-562`). A single command that both plans and applies would defeat the per-apply approval rule. → **WP7** builds a staged `make deploy-plan` / `make deploy`.
3. **The Terraform wrapper cannot reach an account, by design.** `scripts/terraform.sh` passes no AWS variable and does not enforce a command list (`terraform.sh:4-7`, `:17-26`); the Phase 7 plan says Phase 8 "must add its own gate" and should refuse an unpinned `TERRAFORM_IMAGE` override (`phase-7-infrastructure.md:59`). → **WP2**. The offline wrapper stays untouched so `make lint` and CI remain credential-free.
4. **No pinned AWS CLI exists, and the image-pin guard would not notice one.** No Dockerfile, Makefile target, workflow or script uses an AWS CLI; `docs/local-dev.md:298-315` only suggests `brew install awscli` or an unpinned `amazon/aws-cli` container for the local emulators. `scripts/check-image-pins.sh` lists images named in the Makefile or in scripts as a known gap (`check-image-pins.sh:50-52`), so "`check-image-pins` accepts the new image" proves nothing. → WP3 adds its own pin test; decision 4.
5. **No front-end publishing tool exists**, and Phase 7 fixed an order: `assets/` first, then `index.html` and root files, then an invalidation (`phase-7-infrastructure.md:346`). → **WP3**.
6. **The invalidation path in the Phase 7 plan may be incomplete (U).** It says to invalidate `/index.html`. The SPA function leaves `/` untouched and relies on `default_root_object` (`modules/edge/functions/spa-rewrite.js:12-14`, `modules/edge/main.tf:266`), so `/` and `/index.html` may be separate cache entries. → publish invalidates `/*` (one path); decision 11.
7. **The local DLQ check cannot be reused on AWS.** `make smoke` re-reads the DLQ twice, 5 s apart (`Makefile:241-244`) because local visibility is 10 s (`phase-7-infrastructure.md:42`). On AWS the visibility timeout is 180 s and `maxReceiveCount` is 3 (`modules/receipts/main.tf:26-29`), so a poison message needs minutes to reach the DLQ (`modules/observability/main.tf:13-16` computes 540 s). A 10 s wait would pass vacuously. → V22 waits 600 s.
8. **The smoke script has no AWS-only mode and carries four Phase 8 to-dos.** `scripts/smoke.sh` has no direct-API-Gateway or forged-header check, and Phase 6 carried those checks to Phase 8 (`phase-6-monthly-summary.md:312-322`). The same list asks for the S3 host to be pinned (`smoke.sh:69`, `:75`), the size+1 check tightened to `EntityTooLarge` (`smoke.sh:535-536`), the upload-field value pattern (`FIELD_VALUE_RE`) checked against real AWS session tokens, and the DLQ depth read before and after. → WP5; decision 8.
9. **Stale local region placeholders.** ADR 0013 says the `eu-west-1` placeholders change to `us-east-1` in Phase 7 (`adr/0013-aws-region-us-east-1.md:38`), but `.env.example:8` and `docker-compose.yml:163`, `:281` still say `eu-west-1`. This does not affect AWS (Terraform takes the region from tfvars). → optional WP1 item; decision 17.
10. **The bootstrap backend cannot simply be committed active.** The bootstrap has no `backend` block and its comment says Phase 8 migrates its state with one (`bootstrap/main.tf:8-9`); `bootstrap/versions.tf:1-2` still says it is applied "(Phase 7)", but Phase 7 applied nothing (`phase-7-infrastructure.md:8`). A committed, active `backend "s3"` block would make the first `init` ask for a bucket that this very apply must create. → WP6 commits the block as `backend.tf.example` and activates it in one small reviewed commit after the bucket exists (decision 9). Offline checks are unaffected because `tf-check` and `tf-unit` already run `init -backend=false` (`Makefile:535`, `:546`); the bootstrap root also has no tfvars example, added by WP6.
11. **Account-wide budget in a possibly shared account.** The budget has no cost filter (`modules/observability/main.tf:133-135`), so any other spend counts against the $5 limit. It drives decision 1.
12. **Queue names are fixed, and SQS makes a deleted name wait.** The main queue and its DLQ are named `<name>-receipts` and `<name>-receipts-dlq` (`modules/receipts/main.tf:201`, `:207`), so the re-create asks AWS for the same names a minute after deleting them. AWS documents a wait of at least 60 seconds **(U24: documented, not seen here)**. → T12, Stage 8.
13. **The destroy confirmation needs a data-loss preview.** `force_destroy` is on for the three app buckets and the table has no deletion protection (§7.5). → T12 prints whether each bucket holds an object and the table's approximate item count before the destroy plan is approved.
14. **README and PLAN row 7 will mislead after Phase 8.** `README.md:12` and `:63` say nothing is applied; PLAN row 7 (`PLAN.md:272`) still says "alarms: budget, DLQ depth, receipt-cleanup errors" and "worker cannot write to `receipts`", while six alarms exist (`modules/observability/main.tf:57`, `:73`, `:104-118`) and the worker deletes from receipts. → WP9 corrects these and only these.
15. **Phase 6 and Phase 7 carry lists were only partly mapped.** §8a maps every carried item to a check, a work package or an explicit "not in Phase 8".

## 0. What exists vs what is missing

| Area | Implemented (evidence) | Missing, or unverified on AWS |
|---|---|---|
| Application | API and worker, front end, OpenAPI contract, local stack and full test suite. Phases 0-7 are Done in `PLAN.md:265-272`. Local `make smoke` covers every feature (`Makefile:235-247`) | Never run against AWS. `smoke.sh --cross-user` has not been run live (`phase-6-monthly-summary.md:318`) |
| Terraform code | `bootstrap/` (state bucket and its configuration, every resource `prevent_destroy`, `bootstrap/main.tf:15-125`) and `envs/dev` calling 6 module blocks that use the 7 modules (`envs/dev/main.tf:15-96`; `lambda-function` is used inside `api` and `worker`); region fixed to us-east-1 (ADR 0013); `allowed_account_ids` on both providers (`providers.tf:5`); partial S3 backend with `use_lockfile` (`envs/dev/versions.tf:17-22`) | Never planned or applied. All validation is mock-provider tests (`phase-7-infrastructure.md:60`). Real plan output is unseen |
| Terraform execution | `scripts/terraform.sh` (pinned image, no credentials); `make tf-fmt tf-check tf-unit tf-fn-test tf-test tf-lock` (`Makefile:523-562`) | Credentialed wrapper and plan-approval gate (WP2), AWS CLI wrapper (WP3), Make targets (WP7) |
| Inputs | `terraform.tfvars.example`, `backend.hcl.example`; committed `bref-layers.auto.tfvars.json` (php-85 v20 at `bref-layers.auto.tfvars.json:45`, gd-php-85 v1 at `:21`, for us-east-1); `.gitignore` covers `*.tfvars`, `backend*.hcl`, `*.tfstate*`, `*.tfplan`, `build/` (`.gitignore:13-22`, `:30`, `:36`) | The real `terraform.tfvars` (account id, alert email) and `backend.hcl`. Both are owner-supplied and uncommitted |
| Packaging | `make lambda-build` / `lambda-check` build one reproducible zip and write `package.auto.tfvars.json` (`Makefile:123-131`, `phase-7-infrastructure.md:69`); the Lambda module re-checks the uploaded hash (`modules/lambda-function/main.tf:113-114`) | Re-hashing the zip right before apply (carried at `phase-7-infrastructure.md:361`, item 2) |
| Front-end build | `make frontend-build`, `check-bundle-secrets`, `check-bundle-csp` (`Makefile:499-512`). The bundle needs no AWS value: only `VITE_`-prefixed variables reach it (`frontend/vite.config.ts:27`) and a search of `frontend/src` finds no `VITE_` variable in use | Upload to the site bucket, content types, invalidation (WP3). Only `favicon.ico` sits in `frontend/public/` |
| Alarms and budget | 6 alarms (DLQ, queue age 900 s, 4 log-message filters on the API log group), SNS email, budget $5 at 80% actual / 100% forecast (`modules/observability/main.tf:1-60`, `:133-160`) | Email confirmation; proof that the filter patterns work on real CloudWatch (`phase-7-infrastructure.md:332`). No Lambda-error or 5xx alarm until L4 (`PLAN.md:283`) |
| Cost brakes | DynamoDB ceiling 400 read / 100 write units (`envs/dev/locals.tf:7-8`); route throttles, stage 10/20 (`modules/api/main.tf:29-40`); worker concurrency cap 2 and batch 1 (`modules/worker/main.tf:108-115`) | Reserved concurrency left at -1 until the quota is read (`variables.tf:128-140`). No brake on S3 transfer (accepted, `phase-7-infrastructure.md:291`) |
| CI | `terraform` job runs `tf-check`, `tf-unit`, `tf-fn-test`, `tf-test` with no credentials (`ci.yml:305-328`); workflow permissions are `contents: read` (`ci.yml:10-11`) | No deploy job, by design until L2 (`adr/0010-github-actions-ci-cd-with-oidc.md:46-48`) |
| Docs | PLAN, ADRs 0001-0013, Phase 7 plan and results, `docs/local-dev.md` | `docs/deploy.md` and `architecture.md` are Phase 9 (`PLAN.md:274`). Phase 8 produces the executed record, WP9 |

## 1. Goal and completion criteria

**Goal:** `envs/dev` is running in the owner's AWS account in us-east-1, the application works end to end through CloudFront, every AWS-only check in §8 has a recorded result, and the environment has survived one destroy and re-create, all with the owner's approval at each step and no secret committed or printed.

**Phase 8 is complete when all of these are true and recorded in `docs/plans/phase-8-results.md` (WP9):**

1. Every gate in §6 that was used has the owner's approval recorded (date, operation, plan hash where one applies). No AWS-changing operation ran without one, and no AWS command was run by an agent.
2. First apply succeeded; a second `plan -detailed-exitcode` right after it exits 0, or each remaining diff is explained and accepted by the owner. The queue and DLQ were read at 0 immediately after the apply and again at least 10 minutes later (V7).
3. Verification matrix §8: every row is PASS, or has a recorded fail with a fix and re-run, or an owner-accepted waiver naming the row.
4. `scripts/smoke.sh --cross-user https://<distribution_domain>` exits 0 on the first deployment and again after re-create.
5. The four log-message alarms were proven on real CloudWatch with genuine and forged samples (V30); the notification path was proven by an email received (V33).
6. After `destroy`: the four-view check of V38 finds nothing of `dev` left except the state bucket, which `prevent_destroy` keeps, and, if the owner accepted it (decision 15), the DynamoDB system backup. After re-create: the plan's resource set equals the first apply's, and the DLQ, worker-health, error and browser checks pass again (V39). A plan of the bootstrap root after the state migration shows no changes (V40).
7. No secret appears in the repository, the results file, or any saved log: `make secrets-scan` passes on the final tree.
8. The unverified-behaviour register (§9) has an outcome for each item, and each negative outcome is a filed fix, an ADR note, or an owner-accepted risk.
9. Offline suite still green at the end: `make lint` and `make tf-test` (the Phase 7 definition, `phase-7-infrastructure.md:50`), plus the new tests of WP2-WP7 through `make test-shell` (the bootstrap-backend test of WP6 through `make tf-test`, which `test-shell` excludes, `Makefile:89-93`; CI runs `test-shell` in the `guards` job, `ci.yml:135`). CI gains no credentials and no deploy job.
10. PLAN row 8 is updated to Done with the PR number, and the stale statements of contradictions 9 and 14 are corrected; ADR 0009, 0010, 0012 and 0013 carry an "Implemented in Phase 8" note. The end state of `dev` is the one the owner chose at A14a (and, if it was left running, A14b), or the owner has explicitly deferred V37 and A14b to the addendum; the bootstrap resources are unchanged.

## 2. Prerequisites and dependencies

These are the things this phase cannot invent. Each needs the owner or a decision in §11. **None is known to be in place; the repository records no AWS account, identity setup or alert address.**

| # | Prerequisite | Why / evidence | Owner action | Blocks |
|---|---|---|---|---|
| P1 | An AWS account for the deployment, with the 12-digit account id | `aws_account_id` has no default (`envs/dev/variables.tf:143-151`); the providers refuse any other account (`providers.tf:5`); the state bucket name embeds it (`bootstrap/main.tf:12`) | Choose a dedicated account (decision 1) and put the id in `terraform.tfvars` (git-ignored) | Everything AWS |
| P2 | Account hygiene (A): root user has MFA and no access keys; a billing contact is set; no other workloads share the account | The budget is account-wide (`modules/observability/main.tf:133-135`) | Owner confirms in writing at G3 | G3 |
| P3 | Human identity for the CLI: one IAM Identity Center (SSO) user with an administrator-level permission set on the dev account (decision 2) | ADR 0010 and PLAN name "short-lived AWS SSO credentials" (`adr/0010-github-actions-ci-cd-with-oidc.md:47`, `PLAN.md:226`). That the owner has Identity Center set up is **not recorded (A)** | Owner sets up SSO, sets the session length (at least 4 hours for Stages 4 and 8) and names the profile | Stage 1 |
| P4 | Billing visibility (A): the identity can create a budget; whether an IAM/SSO role may use Billing depends on an account setting | `aws_budgets_budget` is part of the stack (`modules/observability/main.tf:136`). The account setting is AWS behaviour, not in the repo (U17) | Owner enables billing access for the role if the plan fails on Budgets | A6 |
| P5 | Region **us-east-1**, decided | ADR 0013 (accepted 2026-11-03); `terraform.tfvars.example:4` | none | none |
| P6 | State bucket `expense-tracker-tfstate-<account>-<region>` created by the bootstrap, then `backend.hcl` | `bootstrap/main.tf:12`; `backend.hcl.example:1-6` | Owner reviews and approves the bootstrap apply (A2) | envs/dev init |
| P7 | An alert email address the owner can read, in uncommitted tfvars | `alert_email` is sensitive and has no default (`variables.tf:115-120`); SNS needs a click to confirm (`modules/observability/main.tf:48-53`) | Owner supplies it (decision 5) | SNS, budget |
| P8 | Quotas read before apply (U16): Lambda concurrent executions and the unreserved minimum; whether CloudFront can create a distribution in a new account | Reserved concurrency stays -1 until the quota is read (`variables.tf:128-129`; `phase-7-infrastructure.md:343`, `:376`); CloudFront gating for new accounts is **(A)** | Read-only probes at A0; owner decides reservations right after, before A5 | A5 |
| P9 | Tooling on the owner's machine: Docker, `make`, host AWS CLI v2 (for `aws sso login` and the credential export only; every other AWS call goes through the pinned container, T2, T9), a browser, `curl` ≥ 7.79 (`smoke.sh:99-101`) | Repo tooling runs in pinned containers (`Makefile:1-12`) | Owner confirms | Stage 0 |
| P10 | Offline baseline green at the starting commit: `make php-install frontend-install contract-install local-aws-install`, then `make lint` and `make tf-test` | `Makefile:345`, `:555` | Stage 0 runs it | Stage 0 |
| P11 | Bref layers are usable from the new account (U18): layers are published by external accounts (`873528684822`, `403367587399`) and Lambda must be allowed to read them | `phase-7-infrastructure.md:68`. Public layer access is **(A)** | Observed at A0 and at the first plan/apply | Apply |
| P12 | An uninterrupted block of owner time for Stages 4 and 8, within one SSO session; because the wrapper refuses to start with under 60 minutes left, set the permission-set session length to at least 4 hours, or log in again between A12 and A13 | A CloudFront create or delete can take many minutes (U20); the wrapper refuses a session that expires within 60 minutes (T2) | Owner schedules | A6, A12, A13 |

## 3. Operating model and design rules

### 3.1 Operating model (Option B)

This is a personal proof of concept and a first manual deployment, not a deployment platform.

- **The owner runs every AWS command**, in the owner's own terminal, from documented `make` targets. Claude Code and the ECC agents do offline engineering only: they write and test scripts, Terraform and docs, and never receive AWS credentials, an AWS profile or an AWS execution role.
- **O1, quiet period.** The owner closes every agent session (Claude Code and its subagents) before `aws sso login`, runs the stage's gates, then runs `aws sso logout` and may reopen agent sessions. No agent process is active while a login is live. The 48-hour observation window of A14a contains no login at all.
- **O2, what goes back to an agent.** After a session the owner may give an agent redacted summaries (counts, booleans, names, statuses, PASS/FAIL), never raw output of `get-function-configuration`, `get-distribution`, subscription lists, `terraform show`, state, plans, `errored.tfstate`, or log lines with addresses. Anything printed in an agent session is stored in its transcript and sent to the model API.
- **O3, reviewing the tooling.** At G2 the owner reads the tooling (`scripts/`, `Makefile`, `infra/`, `docker/`) and records the reviewed commit SHA on their own side. Before each AWS session the owner reads `git diff --stat <G2-SHA>..HEAD -- Makefile scripts infra docker` and re-reads any change. The ECC reviewers are agents of the same session, so their verdicts are advisory, not a control.
- **O4, no pasted commands.** The owner types only documented `make` targets and scripts of the reviewed commit, plus values they checked themselves, and does not run a command copied from an agent session without reading it.
- **O5, short sessions.** The SSO session is as short as the work allows (the wrapper needs at least 60 minutes left). `aws sso logout` ends the local login but does not revoke role credentials already issued; they stay valid until they expire. Emergency response is §7.3.
- **O6, the honest limit.** O1-O5 are **procedural safeguards, not an operating-system security boundary.** If an agent process runs while a login is live, it could read the owner's credentials. The dedicated dev account, the budget, short sessions and O1 are what keep the consequence small. Stronger isolation (a separate OS user or machine, a read-only identity for agents, agent-run verification) is deferred (§14).

### 3.2 Tooling rules (recommended defaults)

| # | Topic | Rule |
|---|---|---|
| T1 | Wrappers | `scripts/terraform.sh` stays offline-only and unchanged. A new `scripts/terraform-aws.sh` (WP2) is the only way repo tooling runs Terraform with credentials, and a new `scripts/aws.sh` (WP3) the only way it calls the AWS CLI beyond the host `aws sso login` and credential export of T2. Both source `scripts/lib/aws-session.sh` (WP2) so the credential rules exist once. The offline wrapper is kept apart so `make lint` and CI can never reach an account |
| T2 | Credentials | The owner runs `aws sso login --profile <name>` and names the profile in `AWS_PROFILE`; a wrapper refuses to run without it. The wrapper obtains short-lived credentials from that session (`aws configure export-credentials`; flag and format **(A)**, settled by Step 0 Q1 and the WP2 spike) and passes them to the container as `-e NAME` pass-through variables (`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_SESSION_TOKEN`, `AWS_REGION`), never as arguments, never printed, `~/.aws` never mounted. It requires a non-empty session token and a key id starting `ASIA` (the prefix convention is **(A)**, Q1), refuses to start if the credentials expire within 60 minutes (expiry from the export, **(A)**), refuses a set `DOCKER_HOST` or non-default Docker context, and never forwards `AWS_PROFILE`, `AWS_ENDPOINT_URL*` or `AWS_CA_BUNDLE`. While a container runs, `docker inspect` shows its `-e` values to anyone with Docker access on the machine (O6). Instance metadata stays disabled (`terraform.sh:25`) |
| T3 | Terraform commands | Only `init`, `plan`, `apply <saved plan file>`, `output -raw <name>` (one of the eight non-sensitive outputs) and `state list`. `show` is run only by the wrapper itself on its own plan file, piped into `review-plan.sh` (plan JSON and state hold both `random_password` results in clear text, `envs/dev/secrets.tf:7-24`); `output -json` and `output` without a name are refused. `validate`, `fmt` and `test` stay on the offline wrapper. `init` always has `-input=false -lockfile=readonly`, except the A3 migration, which omits `-input=false` because Terraform will not ask its migration question under it (**(A)**, Q7); bootstrap at A1 has no backend flag (local state), bootstrap at A3 has `-migrate-state -backend-config=backend.hcl`, envs/dev at A4 has `-backend-config=backend.hcl`. `-force-copy`, `-reconfigure`, `-upgrade`, `-var`, `-var-file`, `-refresh=false`, `-lock=false`, `-target`, `-replace` and `-auto-approve` are refused. `TF_LOG*` is never set (debug logs contain the Lambda environment). `import`, `taint`, `state rm/mv/push`, `force-unlock` and `console` need a per-use approval (A-x) and are run by hand |
| T4 | Image pin | `TERRAFORM_IMAGE` and `AWS_CLI_IMAGE` are pinned by digest and defined in the two wrappers as the source of truth and mirrored in the Makefile (WP3 pin test covers both); the wrappers refuse an environment override (`phase-7-infrastructure.md:59`). A new pin test (WP3) covers both, because `check-image-pins.sh` does not scan them |
| T5 | Plan, review, apply | `plan` writes the plan and a one-line manifest (root, sha256, creation time, zip hash, commit SHA) into the owner-only deploy directory (`${XDG_STATE_HOME:-$HOME/.local/state}/expense-tracker-deploy/`, mode 700, files 600, outside the repo; subdirectories `plans/` and `recovery/`) and prints the hash and summary. `apply` takes only a saved plan, requires the owner to **type** the hash prefix and a fixed phrase (`apply <root> <12 hex>`), re-hashes the Lambda zip against the manifest, requires a clean `git status` for `infra/`, `scripts/`, the Makefile and the lock files and the commit SHA of the manifest, a plan younger than 30 minutes (proposed, decision 20) and a matching account id and region, and, for envs/dev after a destroy, the wait of T12. A changed plan has a new hash and needs a new approval. **Saved plans survive until used:** the plan and apply invocations are separate, so a plan is deleted only by (a) an apply attempt that started `terraform apply`, successful or not, (b) a rejected review, (c) expiry (swept at the start of every wrapper invocation), or (d) `make aws-clean-plans`; the exit trap removes only its own partial files, never a finished plan; deletion is by exact manifest name, never by glob or recursive delete. **Recovery artefacts are never auto-deleted:** after any failed apply the wrapper copies `errored.tfstate` (and a crash log if Terraform writes one) from the root into `recovery/`, verifies the copy by sha256, then removes the original; the wrapper refuses further plan, apply and destroy while `recovery/` holds anything; only `make aws-recovery-resolve`, with a phrase typed by the owner that includes the artefact's sha256, after a `state push` (without `-force`, gate A-x) was verified or the owner declined it, removes them. Plan files and recovery artefacts hold secrets in plain text (`.gitignore:16`) |
| T6 | Plan review | `scripts/review-plan.sh` (WP2) reads `terraform show -json` and fails on: a `delete` or `replace` unless the caller passed `--confirm-destroy <root>`, any delete in the bootstrap root (it has no destroy path), a resource type outside Appendix B, an IAM user/access key, KMS key, NAT gateway, WAF or VPC resource, a `*` action or resource in an IAM policy, and an account id or region different from the tfvars. It prints counts and names only. The owner also reads the plan against the §5 Stage 3 checklist |
| T7 | Staged deploy | `make deploy-plan` (builds, checks, plans, prints the hash) and `make deploy APPROVED_PLAN_SHA256=<hash>` (applies that plan only; the site is published by the separate `make publish-site`, T8, under its own gate A8, after the alarm proof of Stage 5). This refines the single `make deploy` of PLAN row 8 and keeps ADR 0010's "same script later" path: L2 will need its own non-interactive entry point with the same plan/approval split (`PLAN.md:281`, `adr/0010-github-actions-ci-cd-with-oidc.md:47-48`) |
| T8 | Publish and rollback | Upload `assets/` first, then the other root files (`favicon.ico`), then `index.html` last, then invalidate `/*` (one path, so the `/` versus `/index.html` question U12 cannot leave a stale entry; `phase-7-infrastructure.md:346`). Content types are set explicitly by extension and checked afterwards with `head-object` on `index.html`, one `.js` and one `.css`. No `--delete` (an old `index.html` in a browser may still ask for an old hashed asset). The publish refuses to run unless `frontend/dist` exists and `check-bundle-secrets`, `check-bundle-csp` and the `localhost:9000` search (V41) passed in the same invocation. After success the published tree is copied to `build/published/<git-sha>-<stamp>/`: the site bucket has no versioning (`phase-7-infrastructure.md:349`), so this snapshot is the quick rollback (`publish-site --from-snapshot <dir>`, same order, bundle checks re-run); rebuilding the previous commit is the fallback, and the front-end build is not proven reproducible **(A)** |
| T9 | AWS CLI use | `scripts/aws.sh` (WP3) matches **service plus operation** against one fixed list and refuses everything else. **Reads:** `sts get-caller-identity`; `s3api head-bucket|head-object|list-object-versions|get-bucket-versioning|get-bucket-encryption|get-public-access-block|get-bucket-policy|get-bucket-cors|get-bucket-lifecycle-configuration|get-bucket-notification-configuration|get-bucket-ownership-controls`; `s3api list-objects-v2` with `--max-keys 1` or an exact `--prefix` and a count-only `--query`; `lambda get-account-settings|get-layer-version-by-arn|get-event-source-mapping|list-event-source-mappings|list-functions` (names); `sqs get-queue-url|get-queue-attributes|list-queues`; `logs describe-log-groups|describe-metric-filters|test-metric-filter|filter-log-events|start-query|get-query-results`; `cloudwatch describe-alarms|get-metric-statistics`; `budgets describe-budgets`; `dynamodb describe-table|describe-continuous-backups|describe-time-to-live|list-backups|list-tables`; `apigatewayv2 get-stage|get-apis`; `cloudfront get-invalidation|list-invalidations` and the fixed `get-distribution` form below; `iam simulate-principal-policy|list-roles`; `resourcegroupstaggingapi get-resources`; `sns list-topics` and `list-subscriptions-by-topic` (without `Endpoint`); `s3api list-buckets` (names starting `expense-tracker-dev-` only); `cloudfront list-functions|list-origin-access-controls|list-origin-request-policies|list-response-headers-policies` (Id and name only); `service-quotas` reads. **Fixed writes, each behind its own gate:** `s3 cp` of the built site into the site bucket with explicit `--content-type` (A8); `cloudfront create-invalidation` with path `/*` (A8); `sqs send-message` to the main receipts queue only, body read from a reviewed file by `aws-actions.sh` and passed as an argument (A10); `cloudwatch set-alarm-state` on the DLQ alarm, state `ALARM` only, never `OK` (A7). **Not through the wrapper:** other `iam` operations, `lambda put-function-concurrency|update-*`, `apigatewayv2 update-*`, any `delete-*`, `s3 rm|rb|sync`, `--delete`. **Flags always refused:** `--debug` (prints headers and credentials), `--endpoint-url`, `--no-verify-ssl`, `--ca-bundle`, `--profile`, `--cli-input-*`, `--generate-cli-skeleton`, `file://` and `fileb://` values, repeated options, and any region other than us-east-1; output is JSON and every call has a fixed `--query`. **Secret-bearing calls only in one fixed form (three of them):** `lambda get-function-configuration` (the environment holds `ORIGIN_VERIFY_SECRETS`, `phase-7-infrastructure.md:74`) as `{Runtime, Architectures, MemorySize, Timeout, Layers[].Arn, CodeSha256, EnvKeys: keys(Environment.Variables)}`; `cloudfront get-distribution` (it returns the `X-Origin-Verify` value, `modules/edge/main.tf:288-290`) as `Distribution.Status` plus the behaviours' path patterns and function associations, never `Origins`; `cloudfront list-distributions` likewise only as `DistributionList.Items[].{Id}`, never `Origins`; `get-distribution-config` and `get-function` are not allowed. `s3 cp` takes only a source whose real path is under `frontend/dist` or a snapshot, refuses symlinks (`--no-follow-symlinks`) and has the destination fixed to the site bucket from `terraform output`. Log queries use fixed patterns and project named fields only (a probe marker, `clientIp`, status) |
| T10 | Evidence | Evidence is saved under `build/phase-8-evidence/` (git-ignored by `build/`). `phase-8-results.md` records PASS/FAIL, counts, durations and request ids, never headers with cookies, signed URLs, policy fields, tokens, emails or account ids (`phase-7-infrastructure.md:114`). The account id and email appear only in the uncommitted tfvars. O2 governs what is shown to an agent |
| T11 | Smoke on AWS | `scripts/smoke.sh --cross-user https://<distribution_domain>` [exists] for features; `scripts/smoke-aws-edge.sh` (WP5) for credential-free AWS-only HTTP checks; `scripts/aws-verify.sh` (WP3) for AWS state and the delayed queue checks. `make aws-smoke` runs the first two in that order and stops at the first failure. The edge script is separate so `smoke.sh` keeps its invariant of never sending `X-Origin-Verify` (`smoke.sh:15`) |
| T12 | Destroy and re-create | **Preview:** before the destroy plan is approved, `aws-verify.sh destroy-preview` prints, for each of the three app buckets, whether it holds at least one object (a yes/no) and the table's approximate `ItemCount` (DynamoDB refreshes it only about every six hours, U26, so it is approximate). **Wait:** `destroy` writes a timestamp when it starts (a partial destroy may already have deleted the queues) and writes it again when it exits, success or failure, so the last write wins and the 90 seconds count from the end of a long CloudFront delete; the file is `${XDG_STATE_HOME:-$HOME/.local/state}/expense-tracker-destroy-stamp` (mode 600, beside the deploy directory, listed in Appendix A) and no cleanup removes it; `apply` for envs/dev refuses until 90 seconds have passed (AWS documents at least 60, U24; the 90 is proposed, decision 20). If AWS answers `QueueDeletedRecently`, wait and plan again (§7.3) |
| T13 | Resource addressing | Most names are not outputs (one eighth output only, decision 12). `aws-verify.sh` derives them from `expense-tracker-dev` (`local.name`, `phase-7-infrastructure.md:62`): Lambdas `<name>-api` and `<name>-worker` (`modules/api/main.tf:15`, `modules/worker/main.tf:9`), queues `<name>-receipts` and `<name>-receipts-dlq` (`modules/receipts/main.tf:201`, `:207`; the main queue's URL comes from `sqs get-queue-url`), SNS topic `<name>-alerts` (`modules/observability/main.tf:44`), alarms `<name>-receipts-dlq-not-empty` and `<name>-receipts-queue-age` (`:57`, `:73`) plus the four message alarms (`:118`), log groups `/aws/lambda/<name>-{api,worker}` and `/aws/apigateway/<name>` (`modules/api/main.tf:200`). The first reviewed plan (A5) is checked against these names |

## 4. Work packages

Same conventions as Phase 7: tests first (red run recorded), one commit per WP, in the order of the dependencies, ask before every commit and push (gate A-c). All work packages are offline and need no credentials. Model routing follows `.claude/rules/project/model-routing.md` (model and effort passed explicitly on every agent call). Every new shell script is covered by a test in `scripts/tests/` that `make test-shell` runs; CI runs it in the `guards` job (`ci.yml:135`) and gets no credentials and no deploy step.

### WP1: Plan, research, baseline, housekeeping (S)

- **Depends on:** G1.
- **Deliverables:**
  - PLAN row 8 linked to this plan;
  - **Research & Reuse** (ECC development-workflow step 0): `gh search repos|code` and the package registries for an existing pinned AWS-CLI or Terraform gate worth adopting instead of WP2/WP3, then the official AWS and Terraform pages for each question below, asked of `ecc:docs-lookup` (haiku/low) with one URL per claim and checked by the main session. Answers go to `docs/plans/phase-8-step0-results.md` (the Phase 7 pattern) before G2. **Q1** `aws configure export-credentials`: flag, formats, expiry field, `ASIA` prefix and session token for SSO credentials, and how issued Identity Center sessions are revoked; **Q2** do HTTP API stage access logs need the account-level API Gateway CloudWatch Logs role (U11); **Q3** SQS wait before a deleted queue name can be reused; CloudFront create and delete times; `/*` invalidation semantics and allowance; **Q4** fields of `lambda get-account-settings` and the minimum unreserved concurrency; **Q5** `describe-table` `ItemCount` refresh interval; PITR after a table delete, whether a SYSTEM backup can be deleted at all, and whether turning PITR off in a gated plan before A11 avoids it; **Q6** the AWS CLI image to pin (publisher, tag, digest, provenance) and whether `s3 cp` needs `--content-type`; **Q7** `terraform init -migrate-state` with a partial backend (does it modify the local `terraform.tfstate`?) and `use_lockfile` behaviour; **Q8** the input and output of `logs test-metric-filter`;
  - recorded baseline of `make lint`, `make tf-test`, `make test-shell` at the start commit;
  - optional (decision 17): change the `eu-west-1` local defaults to `us-east-1` (`.env.example:8`, `docker-compose.yml:163`, `:281`, `docs/local-dev.md:303`, `:315`, `:339`).
- **Acceptance:** the baseline commands exit 0; Step 0 answers carry a URL each, and any answer that contradicts this plan is raised before G2; if the optional item is done, `make up` and `make test-conformance` still pass (local only).
- **Agent:** main session; research by `ecc:docs-lookup` haiku/low; shell runs by `general-purpose` haiku/low.

### WP2: Credentialed Terraform wrapper and plan review (M)

- **Depends on:** WP1.
- **Deliverables:** `scripts/lib/aws-session.sh` (T2); `scripts/terraform-aws.sh` (T1-T5, T12 wait); `scripts/review-plan.sh` (T6); `scripts/tests/terraform-aws.test.sh` and `review-plan.test.sh` with a fake `docker` and fake `terraform show -json` fixtures, in the style of `scripts/tests/fixtures/smoke/docker`; added to `make test-shell`.
- **Tests first (red):**
  - refuses: no session token; a key id that does not start with `ASIA`; credentials expiring within 60 minutes; an unpinned or overridden image; a set `DOCKER_HOST`; a subcommand outside T3; every refused flag of T3; `init` with another argument list than T3 gives for its root and step; `apply` without a saved plan, with an expired plan, with a wrong or missing typed hash, with `-auto-approve`; any destroy of the bootstrap root; `apply` for envs/dev within 90 s of a destroy; an account id different from tfvars; a dirty `infra/`, `scripts/` or Makefile; a zip hash different from the manifest;
  - never prints a credential value (canary value; grep the output and the fake docker's recorded argv); passes credentials only as `-e NAME` and mounts no `~/.aws`;
  - plan files are mode 600; a plan made by one invocation is applied by a second, separate invocation; it is gone after that apply attempt, after a rejected review and after expiry, but **not** after the exit trap of the plan invocation; removal refuses symlinks, paths outside the directory and manifest mismatches;
  - recovery artefacts survive the exit trap, `aws-clean-plans`, expiry and the Stage 9 cleanup; a plan, apply or destroy attempted while they exist is refused and leaves them untouched; the copy is verified by sha256 before the original is removed, and a failed copy leaves the original in place;
  - review script: fails on each forbidden condition in T6 using hand-written JSON fixtures; passes on a fixture shaped like the expected plan; 8+ mutation checks, as in Phase 7.
- **Spike (offline):** confirm from the installed AWS CLI (help text only: no session, no network) that the export-credentials flag and format of T2 exist. If not, T2 falls back to a documented alternative and the owner is asked.
- **Acceptance:** `make test-shell` green; no real Docker or network call in the tests; code-reviewer (sonnet/medium) and security-reviewer (opus/high): no CRITICAL/HIGH. Reviewer verdicts are advisory (O3).

### WP3: AWS CLI wrapper, publishing and verification (M)

- **Depends on:** WP2 (`aws-session.sh`), WP4 (`distribution_id`), decision 4.
- **Deliverables:** `scripts/aws.sh` (T9); `scripts/publish-site.sh` (T8; it reads `site_bucket` and `distribution_id` with `terraform-aws.sh output -raw`, never typed by hand; a dry-run mode prints the file list, content types and snapshot path without calling AWS); `scripts/aws-verify.sh` implementing one subcommand per check for V1-V11 except V3, V19-V23, the log-query halves of V27, V29 and V34, V30-V36, V38 (`leftovers`, the four views), V40 and `destroy-preview` (T12); a `queues` subcommand reading the main queue and DLQ attributes twice 60 s apart; a `dlq-settle` subcommand that, called at least 600 s after a timestamp it is given, samples twice 60 s apart (the 600 s is the 540 s of `modules/observability/main.tf:13-16` plus a margin; the waiting itself is done by the owner between calls); `scripts/aws-actions.sh` with the two fixed-form writes (`set-alarm-state`, `send-message`); `override AWS_CLI_IMAGE` with tag and digest next to the other pinned images (`Makefile:352-367`), chosen in Q6 and approved by the owner. The metric-filter proof reads the deployed patterns with `describe-metric-filters` (not the repo copy) and uses the fixtures in `scripts/tests/alarm-patterns.test.sh` (`phase-7-infrastructure.md:80`, `:332`) with exact expected match counts, both CorruptItem alternatives separately.
- **Pin test (new, because `check-image-pins.sh` does not scan Makefile or script images, `:50-52`):** `scripts/tests/aws-image-pin.test.sh` asserts that `AWS_CLI_IMAGE` and the `TERRAFORM_IMAGE` default each end in `@sha256:<64 hex>` and that no `docker run` in the new scripts names an image except through those variables.
- **Tests first:** fake `aws` binary records calls; assert every refusal of T9 (class, flags, secret-bearing calls outside their fixed form, `--cli-input-json`, `file://`, a symlink in `dist/`, a source outside `frontend/dist`, a destination other than the site bucket); upload order (`assets/`, other root files, `index.html` last), content type per extension (`.js`, `.css`, `.html`, `.ico`, `.svg`, `.woff2`, and `.map` if present), no delete flag, one invalidation of `/*` only after the last upload succeeded and none after a failed one, the `head-object` content-type check, the snapshot written after success, refusal on missing `dist/` or failed bundle checks, refusal on a bucket name that does not match `expense-tracker-dev-site-<hex8>` (`phase-7-infrastructure.md:62`); nothing from `Environment` and no alert email appears in any output (canaries); the DLQ loop fails closed on timeout.
- **`localhost:9000` check:** Stage 0 searches the built bundle for `localhost:9000` (a Phase 5 and 7 carried item; `frontend/src/config.ts:60` names it for development). If absent, `publish-site.sh` enforces its absence (V41). If present, the owner decides at G2 whether it is a blocker (the CSP would stop it on AWS, U14).
- **Acceptance:** shell tests green; the pin test fails when a tag-only image is substituted; code-reviewer and security-reviewer: no CRITICAL/HIGH (advisory, O3).

### WP4: Export `distribution_id` (S)

- **Depends on:** WP1.
- **Deliverables:** output `distribution_id` in `envs/dev/outputs.tf` (from `module.edge.distribution_id`, non-sensitive) and its header comment; `scripts/check-outputs.sh` expects exactly eight (its header, the `EXPECTED` list at line 26 and its messages say seven); the `tf-unit` help text in `Makefile:539`; the composition test in `envs/dev/tests/`; the amendment of D26 (a Phase 7 plan decision, `phase-7-infrastructure.md:84`) recorded in the results file and the ADR 0009 note. No other output is added: the main queue URL, topic ARN and function names are derived (T13).
- **Tests first:** `scripts/tests/check-outputs.test.sh` red for an eighth output until the guard changes; the guard still fails on any secret-bearing output.
- **Acceptance:** `make tf-unit` and `make test-shell` green; no resource changes in any module (`terraform test` assertions unchanged).

### WP5: AWS-only HTTP smoke and `smoke.sh` Phase 8 items (M)

- **Depends on:** WP1.
- **Deliverables:** `scripts/smoke-aws-edge.sh <distribution-url> <api-endpoint-url> [--only <V ids>] [--skip-burst]` (`--only` takes comma-separated ids and ranges, for example `V12-V15,V17`; credential-free, curl only, fixed hand-written inputs, no scanning, output `ok N - name` / `not ok N - name` like `smoke.sh`) covering V12-V15, V17, V25-V29 and V34's request side. Changes to `smoke.sh`, with `scripts/tests/smoke.test.sh` extended: pin the S3 host for https bases to `<bucket>.s3.amazonaws.com` (`adr/0013-aws-region-us-east-1.md:31-32`; `smoke.sh:69`, `:75`); tighten the size+1 check to `EntityTooLarge` (`smoke.sh:535-536`); make `FIELD_VALUE_RE` fail with a clear message rather than a silent skip when a real session token does not fit it (U25).
- **Tests first:** fake `curl` fixtures (the repo's pattern, `scripts/tests/fixtures/smoke/curl`) for pass and fail of each check, including "direct call returned 200 → FAIL" and "forged header echoed → FAIL"; the https allow-list rejects a non-S3 host.
- **Acceptance:** `make smoke` (local) still passes; tests green; the scripts never send the real origin secret and never print a body, header value or cookie.

### WP6: Bootstrap state migration (S)

- **Depends on:** WP1 (it edits only the bootstrap root and adds example files).
- **Deliverables:** `infra/terraform/bootstrap/backend.tf.example` holding the partial block (`backend "s3" { use_lockfile = true  encrypt = true }`, key `bootstrap/terraform.tfstate`, `phase-7-infrastructure.md:63`), **not active**, with the activation steps in a comment; `bootstrap/backend.hcl.example`; `bootstrap/terraform.tfvars.example` (`aws_region`, `aws_account_id`, `bootstrap/variables.tf:3-21`); the comment at `bootstrap/versions.tf:1-2` fixed; the `test-shell` loop (`Makefile:89-93`) gets a second `case` entry that skips `bootstrap-backend.test.sh`, and `tf-test` (`Makefile:555`) runs it as well as `terraform-check.test.sh`. The `*.example` files match no ignore rule (`.gitignore:34-35`). Activation is one small reviewed commit (`feat(infra): activate the bootstrap state backend`, `backend.tf.example` renamed to `backend.tf`) made only at A3, after the bucket exists.
- **Tests first:** `scripts/tests/terraform-check.test.sh` (`make tf-test`) stays green; `scripts/check-prevent-destroy.sh --require-bucket` stays green; a new `scripts/tests/bootstrap-backend.test.sh`, run by `make tf-test`, copies the bootstrap root into a scratch directory under `build/`, activates the block there and checks `init -backend=false`, `validate` and `terraform test`, so the activation commit is known-safe offline.
- **Acceptance:** `make tf-check tf-unit tf-test` green. The migration itself is a gated AWS operation (A3).

### WP7: Make targets and staged deploy (S-M)

- **Depends on:** WP2, WP3, WP4, WP5 (it wires their scripts).
- **Deliverables:** Makefile targets, all proposed, none in `LINT_TARGETS` (`Makefile:345`) so CI never runs them, all in `.PHONY` (`Makefile:38`), `help` text honest about cost and effect. Each target checks its preconditions before it touches a wrapper. Every target is run by the owner.

| Target | Gate | What it does |
|---|---|---|
| `aws-verify CHECK=<name>` | A0 and AV | one `aws-verify.sh` subcommand per call |
| `aws-bootstrap-plan` / `aws-bootstrap-apply` | A1 / A2 | bootstrap `init` (local state) and `plan`; `apply` of the approved plan |
| `aws-bootstrap-migrate` | A3 | the one `init -migrate-state -backend-config=backend.hcl` |
| `aws-init-dev` | A4 | envs/dev `init` with `backend.hcl` |
| `deploy-plan` | A5 | build checks (`lambda-check`, `tf-layers-check`, frontend build and both bundle checks, `secrets-scan-worktree`), zip re-hash, `plan`, `review-plan.sh`, prints the hash |
| `deploy APPROVED_PLAN_SHA256=<hash>` | A6 | applies that plan only; stops there |
| `aws-replan [ROOT=dev|bootstrap]` | read-only verification (AV) | `plan -detailed-exitcode` for V3, V39 and V40 (default `dev`); never applied; its plan is removed by expiry or `aws-clean-plans` |
| `publish-site` | A8 | publish, or a rollback with `--from-snapshot` |
| `aws-alarm-test` / `aws-dlq-probe` | A7 / A10 | `aws-actions.sh` fixed-form writes |
| `aws-smoke [ONLY=<ids>] [SKIP_BURST=1]` | A9 | `smoke.sh --cross-user`, then `smoke-aws-edge.sh` (T11); `ONLY` runs only the edge checks (Stage 6); the re-create passes `SKIP_BURST=1` because V28 runs once |
| `destroy-preview` / `destroy-plan` / `destroy` | A11 / A11 / A12 | T12 preview; `plan -destroy`; apply of that plan with `--confirm-destroy dev`; the typed phrase is `destroy dev <12 hex>`, different from the apply phrase |
| `aws-clean-plans [ALL=1]` | none | removes finished, rejected or expired plans by exact name; `ALL=1` also removes unexpired unapplied plans after confirmation (Stage 9); never touches `recovery/` |
| `aws-recovery-resolve` | A-x | the only way to remove recovery artefacts (T5) |

  A re-create (A13) uses `deploy-plan`, `deploy` and then `publish-site` (A8) again.
- **Tests first:** `scripts/tests/make-aws.test.sh` runs `make -n` and `make` with the fake wrappers and asserts the order of calls, that `deploy` without `APPROVED_PLAN_SHA256` stops before `apply`, that `destroy` without its confirmation stops, and that no target calls the offline wrapper with credentials.
- **Acceptance:** the new targets are not in `LINT_TARGETS`; `make -n deploy` lists the sequence (it still evaluates `$(shell)` and `$(MAKE)` lines, so it is a listing aid, not evidence).

### WP8: Rehearsal without AWS (S)

- **Depends on:** WP2-WP7.
- **Deliverables:** a dry run of the whole sequence with fakes (fake docker/aws/terraform) to show the order and the refusals; written into the results file as the "offline rehearsal"; a runbook check of §5 and §7.3 (every command named in a stage has an owner, a target and a gate).
- **Acceptance:** every gate in §6 is reached in the rehearsal and each AWS-changing step stops without the right typed confirmation. The owner's walkthrough and review of the tooling diff is **G2**, with the Step 0 results in front of the owner.

### WP9: Execution record and docs (S)

- **Depends on:** execution Stages 0-8 (§5) and the A14a decision. Stage 9 steps 3-4 follow WP9, and the V37/A14b addendum follows the observation window; neither is an input to WP9, so there is no cycle.
- **Deliverables:** `docs/plans/phase-8-results.md` (V-matrix results, gate log, observed latencies and memory, unverified-behaviour outcomes, incidents); "Implemented in Phase 8" notes on ADR 0009, 0010, 0012, 0013 (and 0006 if S3→SQS findings differ); PLAN row 8 → Done and the stale wording of row 7 (contradiction 14); the stale statements in `README.md:12` and `:63`; a hand-off list for Phase 9's `deploy.md`. Drafted by `ecc:doc-updater` (haiku/low) from the owner's redacted summaries (O2), checked line by line by the main session.
- **Acceptance:** `make secrets-scan` clean; no account id, email, signed URL, token or full distribution domain in committed text unless the owner approves it.

**Dependencies:** WP1 → WP2; WP4, WP5 and WP6 depend only on WP1 (they can run beside WP2); WP3 needs WP2 and WP4; WP7 needs WP2, WP3, WP4 and WP5; WP8 needs WP2-WP7; execution (§5) needs WP2-WP8 and G1-G3; WP9 trails Stages 0-8 and A14a; Stage 9 steps 3-4 follow WP9; their results and the V37/A14b outcome go into one docs-only addendum after the window. The longest offline chain is WP1 → WP2 → WP3 → WP7 → WP8.

## 5. Execution sequence

**Rules for every stage:** every command in Stages 1-8 is run by the owner in the owner's terminal (O1-O6); one command at a time, in the foreground; the output is read before the next step; a failure stops the sequence (§7.3); a step marked **GATE** needs the owner's go-ahead naming the operation, as in §6. Stages 1-8 are done in AWS sessions: the owner closes all agent sessions, runs `aws sso login`, performs the stage's gates, runs `aws sso logout`, and only then may reopen an agent session and give it a redacted summary (O2). Commands marked [exists] run today; everything else is a [proposed] tool from the named work package. AWS CLI syntax is standard but not yet run here, and flags are checked against the pinned CLI version in WP3.

### Stage 0: Offline preparation (no AWS, no credentials; agents allowed)

1. `make php-image php-install frontend-install contract-install local-aws-install` [exists].
2. `make lint` and `make tf-test` [exists]; record the result (P10). Any red is fixed before going on.
3. WP1-WP8 are built and reviewed; Step 0 results recorded; `make test-shell` green.
4. Build the artefacts the first plan needs (offline; repeated by `deploy-plan` at A5):
   - `make lambda-build lambda-check` [exists] → `build/lambda/backend.zip` and git-ignored `package.auto.tfvars.json`; note the sha256.
   - `make tf-layers-check` [exists].
   - `make frontend-build check-bundle-secrets check-bundle-csp` [exists] → `frontend/dist`. No AWS value is needed (§0). Then the `localhost:9000` search (V41) [proposed: WP3]; a hit goes to the owner at G2.
5. Owner creates `infra/terraform/envs/dev/terraform.tfvars` from the example (`terraform.tfvars.example:4-22`: region `us-east-1`, account id, `origin_verify_active_slot = "a"`, rotations `"1"`, alert email, budget `5`, concurrency `-1` until A0), `envs/dev/backend.hcl`, and, from the WP6 examples, `bootstrap/terraform.tfvars` and `bootstrap/backend.hcl` (the last used only at A3). These hold an account id and an email but no credential, are git-ignored, and are readable by agent sessions in the worktree; that is accepted for a proof of concept (O6). `make secrets-scan-worktree` [exists] confirms none is tracked.
6. **G2: tooling approval.** The owner walks through the rehearsal (WP8), reads the tooling (O3), reads the Step 0 results, decides any `localhost:9000` hit, and records the reviewed commit SHA.
7. **G3: readiness.** The owner confirms P1-P4, P7, P9, P12 and decisions 1-5 in writing, and that the pinned image digests were approved at G2.

### Stage 1: Identity and read-only probes (first AWS contact)

1. Owner: close agent sessions; `aws sso login --profile <name>`.
2. **GATE A0.** Probes with `make aws-verify` [proposed: WP3]: `sts get-caller-identity` (account equals tfvars; V1); `s3api head-bucket` for the state bucket name (expected: not found; **a 403 means the name belongs to someone else: stop**); `lambda get-account-settings` (concurrency limit and unreserved minimum; P8); `service-quotas` and CloudFront reads if needed (U16); layer visibility via `lambda get-layer-version-by-arn` for the two Bref layers (P11).
3. Owner decides reserved concurrency from the numbers, or keeps -1 (decision 7), and edits the tfvars before A5.

### Stage 2: State bootstrap

The order matters: the bootstrap is applied with **local** state because the bucket does not exist yet, and its backend block is committed only after the bucket does (contradiction 10).

1. **GATE A1.** `make aws-bootstrap-plan`: bootstrap `init` (no backend flag) then `plan` [proposed: WP2]. Review: one S3 bucket plus its configuration resources (versioning, encryption, public access block, ownership, policy, lifecycle), zero deletes (T6).
2. **GATE A2 (creates the state bucket).** `make aws-bootstrap-apply` with the owner-typed hash. The bootstrap's own state is local for now (`bootstrap/main.tf:8-9`) and git-ignored (`.gitignore:14-15`). **Immediately copy `terraform.tfstate` to a private backup outside the repo and note its sha256 (decision 9).**
3. V2: versioning Enabled, AES256, public access block all true, TLS-deny policy, lifecycle rule, `head-bucket --expected-bucket-owner` succeeds (name-squatting check, `phase-7-infrastructure.md:342`).
4. **GATE A3 (writes state into the bucket).** The owner approves and makes the small activation commit of WP6 (`backend.tf.example` → `backend.tf`; `make tf-check tf-unit` green first), then runs `make aws-bootstrap-migrate`: `init -migrate-state -backend-config=backend.hcl`, answering the one prompt. Verify V40: the state object exists with a version, and a bootstrap `plan` reports no changes. The local state file is removed only after V40 passes and the owner's explicit go-ahead (A-x); the backup is kept until Stage 9.

### Stage 3: First plan of envs/dev

1. **GATE A4 (state backend contact).** `make aws-init-dev`: `init -backend-config=backend.hcl` for `envs/dev` [proposed]. `init` configures the backend and may write the state object; the lock is taken by plan and apply.
2. **GATE A5.** `make deploy-plan` [proposed]: builds and checks, re-hashes the zip against `package.auto.tfvars.json` (`phase-7-infrastructure.md:361`, item 2), plans, runs `review-plan.sh` and prints the hash. The plan reads AWS but changes nothing. The owner reads the plan and checks:
   - only allow-listed resource types (Appendix B), no deletes or replaces, no IAM user/key/KMS/NAT/WAF/VPC;
   - bucket names match `expense-tracker-dev-{site,receipts,receipt-thumbnails}-<hex8>` (`phase-7-infrastructure.md:62`);
   - no `*` in any IAM policy; worker has no `PutObject` on receipts; both Lambda roles trust only `lambda.amazonaws.com`;
   - Lambda `architectures`, `runtime`, `handler`, layer ARNs for us-east-1 (php-85 v20, gd-php-85 v1);
   - budget and alarm settings equal `phase-7-infrastructure.md:80`;
   - throttle values are integers and match `modules/api/main.tf:34-40`;
   - queue, topic, alarm, function and log-group names equal those derived in T13;
   - sensitive values show as `(sensitive value)`.
3. The reserved-concurrency values (decision 7) are in the tfvars the plan was made from; changing them means a new plan and a new hash.

### Stage 4: First apply

1. **GATE A6 (creates the environment).** `make deploy APPROVED_PLAN_SHA256=<hash>` [proposed]. First-apply risks, all recoverable (§7.3): IAM propagation making the event source mapping fail once (`phase-7-infrastructure.md:340`, U10); CloudFront account verification (A); Budgets permission (P4); quota. A fresh plan and apply is the normal retry.
2. `terraform-aws.sh output -raw <name>` for the eight outputs (names only: `distribution_domain`, `distribution_id`, `site_bucket`, `receipts_bucket`, `thumbnails_bucket`, `api_endpoint`, `dlq_url`, `table_name`; none is a secret). The origin secrets are not outputs (`outputs.tf:1-2`). V4.
3. `make aws-replan` (V3): exit 0 means no drift; exit 2 means a diff to explain.
4. Read the main queue and the DLQ (V7): both at 0 **before any upload**. This is the day-one check for the `s3:TestEvent` that S3 sends when the notification is created (U9); read again at least 10 minutes later, because a message needs about 540 s to reach the DLQ.
5. Configuration checks with `make aws-verify`: V5-V10, V36.
6. Owner confirms the SNS subscription by clicking the link in the email (V31).
7. **GATE A7 (SNS test email; changes alarm state).** `make aws-alarm-test`: `set-alarm-state` on the DLQ alarm to prove delivery (V33); the alarm returns to OK on the next evaluation.

### Stage 5: Alarm proof before traffic

1. `test-metric-filter` on each of the four log filters with genuine and forged samples (V30). Read-only; no log data is written.
2. If any result is wrong: hold publication and apply the documented fallback JSON patterns (`phase-7-infrastructure.md:332`) through an offline code change and a new gated plan (a new session).

### Stage 6: Publish the application

1. **GATE A8 (uploads to S3, invalidates CloudFront).** `make publish-site` [proposed: WP3], after its dry run: `assets/`, then the other root files, then `index.html` last, content types checked with `head-object` (V12, V14), then invalidate `/*`, then the snapshot is written to `build/published/`. The Lambda package is already deployed by the apply.
2. Wait until the distribution is Deployed (V11: the fixed `get-distribution` form) and the invalidation Completed.
3. First look: `curl -sI https://<distribution_domain>/` (V12); V12-V15 via `make aws-smoke ONLY=V12-V15` (edge checks only).

### Stage 7: Live verification

1. **GATE A9 (writes test data to AWS).** Take the V19 object-count baseline, then `scripts/smoke.sh --cross-user https://<distribution_domain>` [exists] (`make aws-smoke`), then read the V19 counts again (V19, V20). The default wait for a receipt is 30 s (`smoke.sh:37-38`). Registration is throttled to 5 per hour per address and `--cross-user` registers two users (`phase-6-monthly-summary.md:22`), so plan **at most two runs per hour**; each run is its own A9. The route throttles are shared by everyone: login 2/s, register 1/s, list 5/s (`modules/api/main.tf:34-40`). The smoke has never run against them, so a 429 is a finding, not a flake. Record it.
2. The edge checks V17, V25-V29 and V34 ran inside `make aws-smoke` in step 1 (`smoke-aws-edge.sh`, WP5); only the log-query halves of V27, V29 and V34 remain, via `make aws-verify`.
3. **GATE A10 (sends one SQS message).** `make aws-dlq-probe`, V21: send a synthetic S3-style message for a key that does not exist, to settle the `ListBucket` prefix question (`phase-7-infrastructure.md:328`).
4. Wait 600 s after the last SQS-bound event (the last receipt upload of the smoke, or the A10 message, whichever is later), then V22-V23 (DLQ still 0 at two samples 60 s apart; API and worker `REPORT` lines), V32 (no alarm in ALARM), V35 (Lambda `Errors` sum 0). A DLQ read taken seconds after the smoke would pass vacuously (contradiction 7).
5. Owner browser walkthrough (V18): register, log in, create expense, attach receipt, see thumbnail and PDF icon, monthly summary, sign out. With the browser console open: **no CSP violation** (U14).
6. Fix-forward loop for any FAIL: §7.3. The fix is made offline in a new agent session after logout; each re-apply is a new plan and a new gate.

### Stage 8: Destroy → re-create → smoke

1. Pre-check: `make destroy-preview` (T12) shows which buckets hold objects and the approximate item count; the owner confirms nothing in the environment must be kept (decision 15). If real data exists, stop and decide on a backup first.
2. **GATE A11 (plan the destroy).** `make destroy-plan` (`plan -destroy`, envs/dev only) [proposed]. Review: only envs/dev resources; the state bucket and its configuration are not in the plan (they belong to the bootstrap root); `force_destroy` is true on the three app buckets (`modules/edge/main.tf:78`, `modules/receipts/main.tf:36`, `:129`), so their objects are deleted.
3. **GATE A12 (destroys the environment and all its data).** `make destroy`, typing `destroy dev <12 hex>` [proposed]. This is the highest-risk step of the phase (§10). Never run against the bootstrap root. Record the duration and every error.
4. V38: the four-view leftover check. Expected remainder: the state bucket only. Record anything else (CloudWatch log groups recreated by late invocations are the likely case, U20, and are exactly what the name-prefix view catches and a tag query would miss).
5. **Wait at least 90 seconds** after the destroy finished (T12: SQS reuse delay, U24; the wrapper enforces it). **GATE A13 (re-create).** `make deploy-plan`, review (V39 compares the resource set with the first apply), `make deploy`, the eight outputs again, `make publish-site` (A8), then `make aws-smoke SKIP_BURST=1` (A9). New bucket names, a new distribution domain and new origin secrets are expected (`random_id` and `random_password` are destroyed with the stack, `envs/dev/secrets.tf:7-23`, `envs/dev/main.tf:11-13`); the queue names are the same as before and are the reason for the wait. Old bookmarks and cookies stop working; the SNS email needs confirming again. If the apply answers `QueueDeletedRecently`, wait and plan again (§7.3).
6. Repeat on the new stack every Stage 7 verification that depends on it: V3, V12-V16, V17, V18 (a short browser walkthrough), V22 and V23 (600 s after the last SQS-bound event again), V25-V27, V29-V32, V34, V35 (V28 and V33 are not repeated; V30 is repeated before publishing). The DLQ must be 0 at the end of both Stage 7 and Stage 8.

### Stage 9: Close-out

1. **GATE A14a: observation or end now.** Immediately after Stage 8 the owner chooses to leave `dev` running for the observation window of V37 (at most 48 hours; this requires V31 to PASS on the new stack, because until the new SNS subscription is confirmed the alarms notify nobody; no AWS login is open during the window) or to destroy it now (decision 14; an immediate destroy repeats A11, A12 and V38). This is the only end-state choice needed to write WP9.
2. WP9: results and docs from Stages 0-8, in an agent session opened after `aws sso logout`; V37 and A14b are written as "pending".
3. Close-out checks (recorded in the same docs-only addendum as V37 and A14b, because they come after WP9): `make secrets-scan`; `make aws-clean-plans ALL=1` and an empty `plans/`; `recovery/` empty or every artefact accounted for in the results file; delete `build/phase-8-evidence/*` only after the owner's explicit go-ahead and after the results are written; keep the bootstrap state backup and `build/published/` snapshots until the owner releases them. Commit and push only on the owner's word; the PR is one branch, squash-merged by the owner after CI is green. The owner may merge before the window ends only by explicitly deferring V37 and A14b to the addendum, which is then a follow-up docs-only commit or PR.
4. After the window: V37 (no unexpected alarm or budget email; the owner checks the Billing console once and records the order of magnitude), then **GATE A14b: final end state**: keep `dev` or destroy it (a destroy repeats A11, A12 and V38). Both are recorded in a short docs-only addendum to `phase-8-results.md` (gate A-c). If the owner destroys at A14a, V37 is marked not applicable and the next-day billing read replaces it.

## 6. Approval gates

Three kinds of approval are kept apart:

- **G1 approves engineering scope and architecture** (this plan and the decisions in §11). It authorizes no AWS action.
- **G2 approves the tested tooling**: tests green, the offline rehearsal, the Step 0 results, the pinned image digests, and the owner's own reading of the tooling diff with the reviewed SHA recorded. It authorizes no AWS action.
- **Authorization of AWS operations** is the owner's own explicit go-ahead at each gate below, given by typing the confirmation in the owner's terminal. G3 first confirms that the account and identity are ready. A go-ahead for one operation never covers another, a second attempt after a failure, or a different plan hash.

The owner types only documented `make` targets and scripts of the reviewed commit, plus values they checked themselves (O4). A gate is requested with: operation, root, account (last four digits), region, plan hash (for plan/apply/destroy), expected effect, expected cost, and how to undo it.

| Gate | Operation | Changes AWS? | What the owner must see first |
|---|---|---|---|
| G1 | Approve scope, architecture and §11 | no | this document |
| G2 | Approve the tested tooling (WP8 rehearsal, Step 0 results, image digests, tooling diff); record the reviewed SHA | no | test output, `make -n deploy`, the diff |
| G3 | Account and identity readiness (P1-P4, P7, P9, P12, decisions 1-5) | no | owner's written confirmation |
| A0 | Read-only probes (identity, state name, quotas, layers) | no (reads) | the exact calls |
| A1 | Bootstrap init and plan | no (reads) | plan summary |
| A2 | Bootstrap apply | **yes** (creates the state bucket) | plan hash, review result |
| A3 | Bootstrap state migration, with the one-file backend activation commit | **yes** (writes state; changes one tracked file) | local backup exists with its sha256; `make tf-check tf-unit` green with the block active |
| A4 | envs/dev init with backend | writes lock/state | backend.hcl contents (bucket name) |
| A5 | envs/dev plan | no (reads) | account and region check, review output |
| A6 | envs/dev apply | **yes** | plan hash, review output, reserved-concurrency values |
| A7 | `set-alarm-state` test | changes alarm state, sends email | alarm name |
| A8 | Site upload and invalidation | **yes** | file list, content types and upload order (dry run); previous snapshot path |
| A9 | Smoke run with test data | **yes** (data) | number of runs this hour |
| A10 | Synthetic SQS message | **yes** (one message) | message body |
| A11 | Destroy plan | no (reads) | scope |
| A12 | Destroy | **yes, destructive** | destroy-plan hash, the T12 preview (bucket objects yes/no, approximate item count), "no data to keep" confirmation |
| A13 | Re-create | **yes** | new plan hash |
| A14a | Observation window or end now (right after Stage 8); an immediate destroy repeats A11, A12 and V38 before WP9 is written | decision | cost and exposure summary |
| A14b | Final end state (after the window) | decision; a destroy repeats A11-A12 | V37 result, billing order of magnitude |
| AV | Read-only verification inside a stage: `aws-verify`, `aws-replan`, `output -raw`, the edge probes and log queries | no | covered by the enclosing stage's go-ahead; it changes nothing, and a failure stops the stage |
| A-c | Any commit or push, including the WP6 activation commit | no AWS | the diff |
| A-x | Any extra: `force-unlock`, `import`, `state push`, `-replace`, `-target`, state surgery, console change, removing the local bootstrap state, `aws-recovery-resolve`, or applying a second plan | **yes** | its own request |

## 7. Security, reliability and operations

### 7.1 Credential and secret handling

- Only short-lived SSO credentials; no IAM user, no access key, no root credentials (T2). Only a key id starting `ASIA` with a session token is accepted (**(A)**, Q1).
- Credentials travel as environment pass-through into a container started per command, with `--cap-drop=ALL` and `no-new-privileges` as in `terraform.sh:17-19`; never in argv, files, logs, plan output or evidence; `~/.aws` never mounted.
- The permission-set session length is set explicitly (at least 4 hours for Stages 4 and 8, or a second login between A12 and A13; one hour would fail the 60-minute check of T2). `aws sso logout` is hygiene: it does not revoke role credentials already issued (O5).
- `terraform.tfvars`, `backend.hcl`, plan files and state are never committed (`.gitignore`); `make secrets-scan` runs before each commit (gitleaks, `Makefile:411-428`).
- State holds the origin-verify secrets in plain text (accepted, ADR 0012; `envs/dev/secrets.tf:1-3`). The bucket policy is only a TLS-deny statement (`bootstrap/main.tf:74-91`), so any principal in the account with `s3:GetObject` can read the state, including the administrator permission set of decision 2; the same secret is also readable through the Lambda and CloudFront configuration. This is why the account is dedicated (decision 1), and the owner accepts the wider read access explicitly at G3. Narrowing (a deny for all but the deploy role, a minimum-TLS-version deny) is carried to L2 (`phase-7-infrastructure.md:344`).
- The AWS CLI is never run with `--debug`, `--endpoint-url` or `--no-verify-ssl` (T9). The secret-bearing calls of T9 run only in their fixed form. Output shown to an agent follows O2.
- If a secret or credential value is printed, pasted into an agent session or committed: stop; end the session; renew the origin-verify slots through their rotation variables (ADR 0012, two reviewed plans, §7.3); if a credential was exposed, use the emergency response of §7.3; rewrite no git history without the owner; record the incident.

### 7.2 State protection

- Bucket versioned, SSE-S3, private, TLS-only; noncurrent versions kept 90 days; `prevent_destroy` on the bucket and each configuration resource, checked by `scripts/check-prevent-destroy.sh` in `make tf-unit` (`bootstrap/main.tf:15-125`).
- `use_lockfile` locking: a lock object sits beside the state while an operation runs (`envs/dev/versions.tf:19-22`). A stuck lock is released only with `force-unlock`, gated (A-x), after checking that no apply is running.
- The local bootstrap state is backed up outside the repo, with its sha256, from A2 until Stage 9 (a lost local state before A3 would leave the bucket unmanaged; recovery would be `terraform import`, gated). It is removed only after V40 and the owner's go-ahead.
- No destroy against the bootstrap root in this phase.
- Saved plans and recovery artefacts follow T5.

### 7.3 Failure handling and recovery

| Failure | How it shows | Recovery | Approval |
|---|---|---|---|
| Wrong account or region | Provider error from `allowed_account_ids` (`providers.tf:5`); V1 mismatch | Stop; fix the profile or tfvars; nothing was created | none |
| State bucket name already taken | `head-bucket` 403 at A0, or apply error | Stop; owner decides (the name is deterministic, `bootstrap/main.tf:12`); never loosen `allowed_account_ids` | owner decision |
| Bootstrap apply fails midway | Terraform error; local state present | Re-plan and re-apply; keep the local state file and its backup | new A1/A2 |
| State migration interrupted | `init -migrate-state` error | Re-run after reading the error; verify the object and its versions; the local backup is kept | A3 again |
| envs/dev apply partly fails | Error; some resources exist; `errored.tfstate` copied to `recovery/` (T5) | Read the error and fix the cause (IAM propagation: retry; quota or account verification: owner opens a request; Budgets permission: owner changes the billing setting), If `recovery/` holds a state file, first push it with `state push` (A-x), verify, then `aws-recovery-resolve` (the wrapper refuses any plan or apply until then). Only then plan again and apply the new plan. No `-target` without approval | new A5/A6 |
| Session expires mid-apply | Credentials error during a long CloudFront step; `errored.tfstate` | Prevented by the 60-minute check (T2). Otherwise as the row above: log in again, `state push` from `recovery/`, re-plan | A-x |
| Apply succeeded, a check fails | V-matrix FAIL | Diagnose from the owner's redacted summaries and logs. Logout, then one fix-forward attempt by `tdd-guide` offline; if it fails, escalate to `build-error-resolver` opus/high (`model-routing.md`), then stop and ask. Code fixes go through tests, review and a rebuilt zip (new hash, new plan) | new A5/A6 |
| Plan made from a different zip than the one applied | The wrapper's re-hash refuses the apply; or the postcondition fires after Lambda holds the code (`modules/lambda-function/main.tf:113-117`) | Rebuild, plan again; if the function is stuck, the owner decides on a `-replace` plan as an A-x step (`phase-7-infrastructure.md:361`) | A-x |
| Bad Lambda code shipped | Smoke failure; Lambda errors | The Lambda module defines no aliases or versions, so there is no instant rollback. Rebuild the previous commit: the zip is reproducible (`phase-7-infrastructure.md:69`, R3 at `:373`) and apply restores the code. Time is minutes (A) | A5/A6 |
| Bad site shipped | Browser errors, CSP violation | `publish-site --from-snapshot build/published/<sha>-<stamp>` (same order, `index.html` last, invalidate `/*`); with no snapshot (first publish), rebuild the previous commit or destroy. The site bucket has no versioning (`phase-7-infrastructure.md:349`) | A8 |
| Cache serves a stale page or a cached error | Old front end after publish; a 403 cached with the year-long `immutable` header (`phase-7-infrastructure.md:346`, U12) | Invalidate `/*` again; check the upload order was followed | A8 |
| Queue name not yet reusable after a destroy | Apply error `QueueDeletedRecently` (U24) | Wait, then plan again and apply the new plan; the 90 s wait (T12) is meant to prevent it | new A13 |
| Origin secret exposed or suspected | A secret value in a log, transcript or commit | Renew the inactive slot through its rotation variable and apply, make it the active slot and apply, then renew the old slot (`docs/adr/0012-runtime-platform.md:104`; `envs/dev/variables.tf:81-113`). Two reviewed plans | A5/A6 twice |
| Stuck state lock | Plan/apply says state is locked | Confirm no process runs, then `force-unlock` with the lock id | A-x |
| State damaged or deleted | Plan wants to recreate everything | Restore the previous S3 object version; last resort `import` | A-x |
| Destroy fails or hangs | Error, CloudFront still deleting | Re-run destroy; no console deletions; record leftovers with the V38 four-view check | A12 again |
| Re-create differs from the first apply | V39 count mismatch | Compare plans; find the cause before accepting | owner decision |

**Emergency response (cost or abuse spike, or an exposed credential).** The signals are a budget email, the queue-age or DLQ alarm, or an S3 request spike. The owner does this by hand, in their own terminal, with commands taken from this plan and the pinned CLI help, not from an agent session (O4); each step is an A-x gate and is recorded afterwards. The commands themselves are written into `docs/plans/phase-8-step0-results.md` before G2 (a WP1/WP3 deliverable; syntax checked against the pinned CLI help), so nobody composes IAM text during an incident.

1. **Contain the API:** set the API stage throttle to 0 (`apigatewayv2 update-stage`) and the API function's reserved concurrency to 0 (`lambda put-function-concurrency`) (**proposed**; AWS behaviour at 0 is **(A)**, U21). For the worker, disable its event source mapping instead of setting concurrency 0, because with `maxReceiveCount` 3 (`modules/receipts/main.tf:29`) a stopped worker would push messages into the DLQ.
2. **Kill outstanding signed URLs (S3 abuse):** attach an explicit-deny S3 policy to the API role (`phase-7-infrastructure.md:291`).
3. **If a credential was exposed:** end the session, then revoke the active sessions of the permission set in IAM Identity Center and, if needed, add a deny conditioned on `aws:TokenIssueTime` (**(A)**, U30: console behaviour to confirm in Q1); `aws sso logout` alone revokes nothing already issued.
4. **Then:** destroy `dev` through the normal gates, or re-converge it with a reviewed plan. The steps above are out of band, so they show as drift or are invisible to `plan` (the deny policy); remove them by hand, record them, and do not apply an unrelated plan until they are reverted. A deny policy on the API role may also block its deletion in A12 (**(A)**).

### 7.4 Observability during and after the phase

What exists: JSON request logs from the API (30-day retention, `modules/lambda-function/main.tf:23`, `:67-68`), worker logs, API Gateway access logs without the query string (`phase-7-infrastructure.md:79`), six alarms and the budget via SNS email (`modules/observability/main.tf`). What does not: Lambda-error and API-5xx alarms (L4, `PLAN.md:283`), dashboards, CloudFront logs (by design, `phase-7-infrastructure.md:75`), X-Ray. So during Phase 8 the owner reads the logs, the Lambda `Errors` metric and the access-log status codes explicitly after each stage (V35). This is a manual substitute, not monitoring. The API Gateway account-level CloudWatch Logs role is an open question (U11): if access logs do not appear, that is the first thing to check. Missing data is treated as "not breaching" by the alarms, so a metric filter that never matches looks like a quiet system; this is why V30 runs before any traffic is trusted.

### 7.5 Destructive cleanup

The only destroy in scope is `envs/dev`. It deletes: the DynamoDB table with all users, expenses and receipt records; the three app buckets with all objects (`force_destroy`, Phase 7 owner decision 5, `phase-7-infrastructure.md:394`); the queues and their messages; both Lambdas; the distribution; the budget; the SNS topic. `deletion_protection_enabled = false` (`modules/table/main.tf:49`) is deliberate so destroy works. What survives: the state bucket and its versions. AWS may keep a system backup after a table with point-in-time recovery is deleted (U29): assume it exists until Step 0 Q5 and V38 show otherwise, and see decision 15. No on-demand backup is taken unless the owner chooses it (decision 15). The T12 preview runs before the destroy plan is approved.

## 8. Verification matrix

Pass/fail is measurable. "Evidence" goes to `build/phase-8-evidence/` and is summarised in the results file (T10). "Tool" is an existing command or a script delivered by WP2-WP7. A row is PASS only with its evidence in hand.

| ID | Check | Tool | Pass criterion | Evidence |
|---|---|---|---|---|
| V1 | Account and region are the intended ones | `aws-verify` (`sts get-caller-identity`) | Account equals tfvars; region us-east-1 | yes/no only (no id) |
| V2 | State bucket hardened | `aws-verify` | Versioning Enabled; AES256; all four public-access flags true; TLS-deny statement present; lifecycle rule 90/7 days; `head-bucket --expected-bucket-owner` succeeds | field values |
| V3 | No drift after apply | `aws-replan` (`plan -detailed-exitcode`) | Exit code 0 | exit code, resource counts |
| V4 | Outputs correct; no secret exposed | `terraform output`; `check-outputs.sh` [exists, updated by WP4] | Eight outputs; no origin secret in any output or plan text | names |
| V5 | Lambda configuration | `aws-verify` with `--query` allow-list | Both: runtime `provided.al2023`, `x86_64`; API memory 1024 / timeout 25; worker 1024 / 30; layers php-85 v20 (+ gd-php-85 v1 for the worker); `CodeSha256` equals the built zip's sha256 (hex to base64); environment **key names** exactly the set in `phase-7-infrastructure.md:86-93` (names only, never values) | field values, key list |
| V6 | Event source mapping | `aws-verify` | Source is the main receipts queue; batch size 1; `ReportBatchItemFailures`; max concurrency 2; enabled | fields |
| V7 | S3 → SQS notification | `aws-verify` | Exactly one notification on the receipts bucket: `s3:ObjectCreated:*`, prefix `receipts/`, target the main queue; none on thumbnails; EventBridge off; the `s3:TestEvent` did not reach the DLQ: main queue and DLQ both 0 immediately after the apply (before any upload) and again at least 10 minutes later | config, queue and DLQ counts |
| V8 | Queues | `aws-verify` | Main: visibility 180, retention 4 days, SSE; redrive to the DLQ with `maxReceiveCount` 3; DLQ retention 14 days | fields |
| V9 | Buckets | `aws-verify` | All three: public access block, BucketOwnerEnforced, SSE AES256, versioning off, TLS-deny; CORS only on receipts with origin equal to `https://<distribution_domain>` and methods POST/GET/HEAD | fields |
| V10 | DynamoDB | `aws-verify` | On-demand; PITR on; TTL on `expiresAt`; `LSI1` present; max read 400 / write 100; deletion protection off | fields |
| V11 | CloudFront | `aws-verify` + `curl` | Status Deployed; 3 behaviours as in `modules/edge/main.tf:294-330`; one function association, on the default behaviour only; invalidation Completed after publish | fields |
| V12 | Site and security headers | `smoke-aws-edge` | `GET /` → 200 text/html (the S3 content type of `index.html` is confirmed by `publish-site.sh` at A8); CSP equals the Terraform string with the us-east-1 S3 hosts (`<bucket>.s3.amazonaws.com`, ADR 0013); HSTS, `nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, Permissions-Policy present; `Cache-Control: no-cache` | header names + values (non-secret) |
| V13 | SPA deep link | `smoke-aws-edge` | `GET /expenses/<uuid>` → 200 with the index document; an encoded-path probe behaves as designed (U13); `GET /missing.js` is not rewritten (record the status, U12) | status |
| V14 | Hashed asset | `smoke-aws-edge` | `GET /assets/<file>` → 200, correct content type, `Cache-Control: public, max-age=31536000, immutable`; the S3 content types on one `.js` and one `.css` are confirmed by `publish-site.sh` at A8 | headers |
| V15 | API health through the edge | `smoke-aws-edge` | `GET /api/v1/health` → 200 JSON; `Cache-Control: no-store`; no `X-Powered-By`; no CSP header on `/api/*` | headers |
| V16 | Feature smoke | `smoke.sh --cross-user` [exists] | Exit 0; every line `ok`; zero `not ok`; receipt reaches `ready` and the invalid file `failed` within the default 30 s wait; the size+1 upload is answered `EntityTooLarge` (`smoke.sh`, WP5); the upload field values of real session tokens are accepted by `FIELD_VALUE_RE` (U25) | full stdout (names only, `smoke.sh:15-17`) |
| V17 | Cookies | `smoke-aws-edge` | Login sets one `__Host-` cookie with `Secure; HttpOnly; SameSite=Lax`; multiple `Set-Cookie` survive the chain (`phase-2-authentication.md:595-598`) | attribute names only, never the value |
| V18 | Browser walkthrough | owner (manual) | The flows in Stage 7 step 5 work; console shows zero CSP violations; the receipt upload (a cross-origin POST to S3) succeeds, which proves the CORS rule | owner's yes/no, screenshots without data |
| V19 | Receipt pipeline artefacts | `aws-verify` | After a valid upload: the original exists under `receipts/<user>/<expense>/<id>` and a thumbnail exists in the thumbnails bucket, shown by object counts under the prefix `receipts/` of each bucket (`list-objects-v2`, count-only `--query`) before and after the smoke; after the invalid file: the original is gone | existence booleans |
| V20 | Receipt deletion cleanup | smoke (covers delete) + `aws-verify` | After deleting a receipt through the API, both objects are gone | existence booleans |
| V21 | Missing object handled as gone (U7) | A10 + `aws-verify` | The synthetic message leaves the main queue without reaching the DLQ; the DLQ is unchanged; no `AccessDenied` in the worker log. If it ends in the DLQ, apply the Phase 7 fix: drop the `Condition` on the worker's `ReceiptsList` statement and update its test (`phase-7-infrastructure.md:328`) | queue/DLQ counts, worker log summary |
| V22 | DLQ stays empty | `aws-verify` (`dlq-settle`) | `ApproximateNumberOfMessagesVisible` on the DLQ equals the baseline 0 (V7) at 600 s after the last SQS-bound event, upload or A10 message (two samples 60 s apart). A message needs about 540 s to arrive (three tries of 180 s), so an earlier read proves nothing. SQS attributes are approximate (A) | counts |
| V23 | Worker health | `aws-verify` (Logs query on `REPORT`) | Worker Max Memory Used ≤ 768 MB (proposed threshold, 75% of 1024 MB; Phase 7 Step 0 R8 measured about 316 MB worst-case RSS, `phase-7-step0-results.md:28`); no `Task timed out`; also record Duration and Init Duration (cold start) of the API and worker `REPORT` lines, which is where Phase 2 and Phase 6 read login and report latency (U19) | max, duration |
| V24 | Upload CORS from a browser | V18 | Folded into V18 (no separate tool) | none extra |
| V25 | Direct API Gateway call is refused | `smoke-aws-edge` | `GET <api_endpoint>/api/v1/health` without the header → 403 `application/problem+json`, no data; with a wrong `X-Origin-Verify` → 403 (`adr/0012-runtime-platform.md:294`) | status, content type |
| V26 | A forged `X-Origin-Verify` through CloudFront is replaced | `smoke-aws-edge` | Through the distribution, a request carrying a bogus `X-Origin-Verify` still gets 200 on health (if CloudFront forwarded the bogus value, the API would answer 403) | status |
| V27 | Forged viewer address is ignored | `smoke-aws-edge` + `aws-verify` (Logs query) | A request through CloudFront with `CloudFront-Viewer-Address: 203.0.113.9:1` (and an underscore variant `CloudFront_Viewer_Address`) to a unique probe path; the matching API request log line exists and its `clientIp` is not `203.0.113.9`; repeated over IPv6 if the owner's network has it, else recorded as untested (U4) | the `clientIp` field only |
| V28 | Throttling works (U2) | `smoke-aws-edge`, bounded | One bounded burst of 40 requests in about 2 s to `/api/v1/health` yields at least one 429, and normal service resumes within 10 s. Not a load test; runs once | counts of 200/429 |
| V29 | Canary does not move alarms | `smoke-aws-edge` + `aws-verify` | `GET /CorruptItem` through CloudFront leaves metric `CorruptItem` with no datapoint above 0 (`phase-7-infrastructure.md:332`) | metric sum |
| V30 | Metric filters on real CloudWatch (U8) | `aws-verify` (`test-metric-filter`) | For each of the four patterns: the genuine line matches exactly 1; the forged samples from `scripts/tests/alarm-patterns.test.sh` (backslash and raw-quote forms) match 0; both CorruptItem alternatives are tested apart. Any wrong result triggers the fallback to JSON patterns | counts per sample |
| V31 | SNS subscription confirmed | `aws-verify` | Status is confirmed, not pending | status |
| V32 | Alarm states | `aws-verify` | Six alarms exist, each with alarm and OK actions to the topic; none in ALARM 10 minutes after the smoke | states |
| V33 | Notification path | A7 | The owner receives the test alarm email within a few minutes | owner's yes/no |
| V34 | Logs: retention, no query string and no origin header | `aws-verify` + `smoke-aws-edge` | Retention 30 days on the three log groups; a request with `?q=phase8-<random>` appears in no access log or API log, and a count-only Logs Insights query for the header name `x-origin-verify` finds 0 in the three groups (never the secret value in a query: query text is recorded in CloudTrail); the marker count is 0 | counts |
| V35 | Errors and 5xx | `aws-verify` | Lambda `Errors` sum 0 for API and worker over the smoke window; no 5xx in the access log (the 429s of V28 are expected and excluded) | sums |
| V36 | IAM does what the tests claim | `aws-verify` (`simulate-principal-policy`) | Worker role: `s3:PutObject` on the receipts bucket is denied; `PutObject` on thumbnails is allowed. API role: `PutObject` only under the key prefix `receipts/*` (`modules/api/main.tf:86-89`); no `dynamodb:Scan`. Both roles trust only `lambda.amazonaws.com` | decisions only |
| V37 | 48-hour follow-up | owner | No unexpected alarm or budget email; Billing console order of magnitude recorded | owner's note |
| V38 | Destroy leaves nothing but the state | `aws-verify` (`leftovers`) | Four independent views agree, because a tag query alone misses untagged or late-created resources (for example log groups re-created by a late invocation): (1) `state list` of envs/dev is empty; (2) direct name-prefix listings per service find nothing named `expense-tracker-dev…`: Lambda functions and event source mappings, HTTP APIs, SQS queues (`--queue-name-prefix`), SNS topics, alarms (`--alarm-name-prefix`), log groups under `/aws/lambda/expense-tracker-dev` and `/aws/apigateway/expense-tracker-dev`, DynamoDB tables, S3 buckets starting `expense-tracker-dev-`, IAM roles, the budget, and CloudFront distributions, functions and origin access controls (Id and name only); (3) the tag query returns only the state bucket (it can return stale ARNs for a while after a deletion, so views 2 and 3 are not expected to agree instantly); also checked: CloudFront origin-request and response-headers policies (not taggable), any `/aws/cloudfront/function/*` log group, and `dynamodb list-backups --table-name expense-tracker-dev --backup-type SYSTEM` (a count); (4) a second run of (2) after at least 10 minutes finds nothing new. Anything found is named, then removed through Terraform or an owner-approved A-x deletion, and the views are repeated until they agree. Not covered by name or tag (resource-policy statements, account-level API Gateway settings, the AWS-managed `aws/lambda` key that persists and is free, noncurrent state versions that keep old secrets for 90 days, accepted) are noted as such, and the next-day billing read (V37) is the last backstop | resource names, counts |
| V39 | Re-create equals first apply | `aws-replan`, `aws-verify` | Same resource-type counts as the first apply; V3, V12-V16, V18, V22, V23, V17, V25-V27, V29-V32, V34 and V35 pass again (V28 and V33 run once; V31 means the new subscription is confirmed); no `QueueDeletedRecently` error, or the wait it needed is recorded (U24) | counts, results |
| V40 | State location and bootstrap unchanged | `aws-verify` + `plan` | (a) After A3: the object `bootstrap/terraform.tfstate` exists and shows at least one version (`head-object`, versioning) and a bootstrap `plan` (`aws-replan ROOT=bootstrap`) exits 0; (b) after A6: `envs/dev/terraform.tfstate` exists with a version, and the local bootstrap state file is absent once the owner has removed it (after (a)); (c) best effort (U27): the lock object (`.tflock`) is seen from a second terminal while a long apply runs, the one allowed exception to the one-command-at-a-time rule, read-only | key names, exit code |
| V41 | No development origin in the published bundle | `publish-site` (`grep` of `frontend/dist`) | `localhost:9000` is absent from every file of `frontend/dist`; a hit goes to the owner (WP4) | file count only |

### 8a. Carried items and where each is handled

Nothing handed over by Phases 1-7 is dropped silently: each item has a check, a work package, or an explicit reason it is not Phase 8. Sources: `phase-7-infrastructure.md:318-350` (carried out to Phase 8) and `:361` (review LOWs), `phase-6-monthly-summary.md:312-322`, `phase-5-receipts.md:451`, `:519`, `phase-2-authentication.md:595-599`, `phase-1-backend-core.md:418-424`, `:468`.

| Carried item | Handled in |
|---|---|
| Real plan review; apply; destroy → re-create | Stages 3, 4, 8; A5, A6, A11-A13; V39 |
| `backend.hcl` and bootstrap state migration | WP6; Stage 2; V40 |
| Account Lambda concurrency quota → reserved concurrency | A0; decision 7 |
| SNS email confirmation | V31, V33 |
| `make deploy` (zip, layers, front-end upload with content types, invalidation) | WP3, WP7; T7, T8; V12, V14 |
| `test-metric-filter` against real log lines | V30; Stage 5 |
| ADR 0012 and Phase 1/2 live checks (direct 403, forged and underscore viewer address, `X_Origin_Verify`, duplicate cookies, encoded paths, IPv6, no `X-Powered-By`) | V13, V15, V17, V25-V27, U4, U23 |
| DLQ depth before and after the smoke; report and login latency | V7, V22, V23, U19 |
| `s3:prefix` giving 403 instead of 404 for a missing key | A10; V21 |
| Build-time bucket origins (Phase 5 L2) | **Not Phase 8** (out of scope); the bundle check for `localhost:9000` is V41 |
| Leftover smoke users | Decision 19 |
| Queue-age alarm for direct-to-S3 abuse | V32 (exists, alarm states); it cannot be provoked safely, so only its presence is proven |
| API Gateway account-level CloudWatch Logs role; IAM propagation on the event source mapping; name squatting; S3→SQS with SSE-SQS; route throttles; no query string in access logs | U11, U10, V2 with A0, U1, V28, V34 |
| Site bucket answers 403 for a missing object; cached error with the `immutable` header; edge caches `index.html` up to 24 h | V13, U12; T8 invalidation |
| Smoke items of Phase 6: `--cross-user` live, direct API Gateway, forged address, size+1 `EntityTooLarge`, S3 host pin, upload-field pattern | V16, V25, V27, WP5, U25 |
| Zip re-hash right before apply | T5, WP2 |
| Other LOWs of `phase-7-infrastructure.md:361` (the `policy_json` wildcard allowlist, tightening two zip-script tests) | **Not Phase 8**: no deployment depends on them; stay on the backlog |
| Phase 7 WP11 follow-up: log the AWS error code, then drop the second CorruptItem alternative | **Not Phase 8** (a backend code change); V30 tests both alternatives as they are |
| State bucket hardening (minimum TLS version, who can read state) | **Carried to L2** (§7.1) |
| Per-user upload throttle (Phase 5 M1); request deadline from the Lambda context (Phase 7 decision 9) | **Not Phase 8** (decision 18) |
| `check-outputs.sh` reads only `outputs.tf` (LOW) | **Not addressed**; WP4 edits the same script and the limit stays documented in its header |

## 9. Register of unverified AWS behaviour

Everything here can only be answered on a real account (U28 was merged into U4). "Source" says where the repository already carries the question; **(A)** marks questions I add.

| ID | Question | Source | Settled by |
|---|---|---|---|
| U1 | S3 → SQS delivery works with SSE-SQS and a TLS-deny queue policy; fallback: drop the TLS deny first | `phase-7-step0-results.md:29` | V7, V16 |
| U2 | HTTP API route throttles: acceptance of values, `$default` vs specific routes, `route_settings` ordering | `phase-7-step0-results.md:17-19`, `phase-7-infrastructure.md:337` | Apply result, V28 |
| U3 | CloudFront replaces a viewer-sent `X-Origin-Verify` and `CloudFront-Viewer-Address`, drops underscore variants; API Gateway accepts requests without `Host` | `phase-7-infrastructure.md:348`, `adr/0012-runtime-platform.md:294-297` | V25-V27 |
| U4 | IPv6 form of `CloudFront-Viewer-Address` through the chain | `adr/0012-runtime-platform.md:296` | V27 repeated over IPv6 if the owner's network has it; otherwise recorded as untested |
| U5 | OAC bucket policy accepted with BlockPublicPolicy on; TLS deny does not block CloudFront | `phase-7-infrastructure.md:348` | A6, V12 |
| U6 | CSP and Cache-Control reach the browser on site and asset responses; `/api/*` keeps `no-store`, no CSP | `phase-7-infrastructure.md:348` | V12-V15 |
| U7 | Worker `ListBucket` with `s3:prefix` gives 404, not 403, for a missing key | `phase-7-infrastructure.md:328` | V21 |
| U8 | The four filter patterns match real lines and reject forged ones | `phase-7-infrastructure.md:332` | V30 |
| U9 | `s3:TestEvent` is discarded by the worker and the DLQ alarm does not fire on day one | `phase-7-infrastructure.md:339`; `backend/src/Receipts/S3EventParser.php:20-23` | V7 |
| U10 | IAM propagation delays the event source mapping on first apply | `phase-7-infrastructure.md:340` | A6 result |
| U11 | The API Gateway account-level CloudWatch Logs role exists or can be created by the deploy identity | `phase-7-infrastructure.md:341` | A6 result, V34 |
| U12 | Cache behaviour of `/` vs `/index.html` after invalidation; whether S3's 403 for a missing site object is cached with the year-long `immutable` header | `phase-7-infrastructure.md:346-348` | V13, V14, publish test |
| U13 | CloudFront Functions pass `request.uri` undecoded | `phase-7-infrastructure.md:348` | V13 |
| U14 | No CSP violation in a real browser (upload XHR, thumbnails, `:style` bindings under `style-src 'self'`) | `phase-7-infrastructure.md:348` | V18 |
| U15 | Several `Set-Cookie` headers survive Bref → API Gateway v2 → CloudFront; the `__Host-` cookie works on `*.cloudfront.net` | `phase-2-authentication.md:595-598` | V17, V18 |
| U16 | A new account can create a CloudFront distribution without extra verification; the Lambda concurrency quota and its unreserved minimum | **(A)**; `phase-7-infrastructure.md:343`, `:376` | A0, A6 |
| U17 | The identity can create a budget (billing access for IAM/SSO roles is an account setting) | **(A)** | A6 result |
| U18 | Bref layers readable from the new account | **(A)**; `phase-7-infrastructure.md:68` | A0, A6 |
| U19 | Login (Argon2id) and report latency at 1024 MB; cold start against the smoke's timeouts | `phase-2-authentication.md:599`, `phase-7-infrastructure.md:327` | V16 timings recorded; tune memory only on evidence |
| U20 | Destroy ordering and leftovers (CloudFront deletion time, log groups recreated by late invocations, a pending SNS subscription) | **(A)** | A12, V38 |
| U21 | `aws configure export-credentials` and the `ASIA` prefix with a session token behave as assumed; Lambda reserved concurrency 0 is accepted | **(A)** | WP2 spike and test; A-x if the emergency steps are used |
| U22 | Point-in-time backups after a table delete | **(A)** | See U29; no backup is relied on |
| U23 | Duplicate query names, encoded paths, `X-Powered-By`, duplicate cookies through the full chain | `phase-1-backend-core.md:418-424`, `:468`; ADR 0012 Phase 1 notes | V15, V17, V27, WP6 |
| U24 | A deleted SQS queue's name cannot be reused for at least 60 seconds (documented by AWS, not seen here) | **(A)**; Stage 8 | Q3 research, V39 |
| U25 | Upload-policy field values built from real Lambda session tokens fit the smoke script's `FIELD_VALUE_RE` (`smoke.sh` field patterns) | `phase-6-monthly-summary.md:312-322` | V16 |
| U26 | `describe-table` `ItemCount` is refreshed only about every six hours, so it is approximate | **(A)** | Q5 research; shown as approximate (T12) |
| U27 | The `use_lockfile` lock object appears beside the state during an operation and a second run is refused | `envs/dev/versions.tf:19-22` | V40, best effort |
| U29 | AWS creates a system backup when a table with point-in-time recovery is deleted and keeps it for a documented period (35 days); it would hold user data, so "destroy deletes all data" would be wrong | **(A)**; Q5 | Q5 research, V38 (`list-backups`); the owner deletes it (A-x) or accepts it (decision 15) |
| U30 | How issued IAM Identity Center role sessions are revoked, and whether an `aws:TokenIssueTime` deny is needed (§7.3 emergency response) | **(A)** | Q1 research; used only if the emergency step is ever needed |

## 10. Costs and risks

### 10.1 Cost drivers

| Item | Source | Status |
|---|---|---|
| Idle: close to $0; S3 and DynamoDB storage cents; PITR about $0.20/GB-month on a tiny table | `phase-7-infrastructure.md:383` | **Documented estimate.** Not verified on any account |
| Free-tier claims: 10 alarms, metric filters, 5 GB logs, CloudFront 1 TB / 10 M requests, Functions 2 M, Lambda 1 M requests / 400k GB-s, SQS 1 M, SNS email, Budgets free | `phase-7-infrastructure.md:383` | **Documented estimate (A)**; whether these are permanent or time-limited tiers for the owner's account is not stated in the repo |
| Light use under $1/month; HTTP API about $1 per million requests; DynamoDB $0.125 per million reads and $0.625 per million writes in us-east-1 | `phase-7-infrastructure.md:384`; prices `phase-7-step0-results.md:21` | Prices read from AWS docs in Phase 7 Step 0 R3; usage assumed |
| Worst case bounded: 400 read units/s ≈ $4/day | `phase-7-infrastructure.md:385` | Planner's estimate from approximate prices |
| x86_64 Lambdas cost about 20% more than arm64 | `PLAN.md:332` | Documented, negligible |
| Phase 8 itself: a few hundred requests (smoke runs, probes, one burst of 40), two full applies, one destroy | this plan | Estimate (A): cents. Creating a CloudFront distribution twice is free (A) |

**Verified facts about cost: none.** No spend has been observed because nothing has been deployed. The only measured numbers are local (worker memory, `phase-7-step0-results.md:28`).

### 10.2 Existing safeguards and their limits

- Budget $5/month, notify at 80% actual and 100% forecast, **account-wide**, evaluated a few times a day: it lags by hours and stops nothing (`modules/observability/main.tf:133-160`).
- DynamoDB on-demand ceiling 400/100 (`envs/dev/locals.tf:7-8`), route throttles (`modules/api/main.tf:29-40`), worker concurrency 2 and batch 1 (`modules/worker/main.tf:108-115`), queue-age alarm 900 s (`modules/observability/main.tf:18`).
- Not covered (accepted in Phase 7, `phase-7-infrastructure.md:291-292`): direct-to-S3 transfer by a registered user reusing a signed link; junk traffic straight to the public `execute-api` address, which can use the shared route limits (login 2/s) and block everyone. Phase 8 adds one runbook mitigation, the emergency response in §7.3. Reserved concurrency stays -1 until the quota is read.
- Open registration on a public URL is part of the design. While `dev` is left running, anyone who finds the URL can register and upload (decision 14).

### 10.3 Cost and data-loss risk of deploy, destroy and re-create

- **Destroy is irreversible for the data inside `dev`:** table rows, receipt objects, thumbnails, queue messages (§7.5). The Phase 7 owner accepted this for dev (`phase-7-infrastructure.md:394`). The control is the A12 confirmation that no real data was entered.
- **Re-create costs no more than the first deploy** (A), but changes names, domain and secrets (§5, Stage 8). Anything that recorded the old domain (bookmarks, the SNS confirmation) must be redone.
- **Each failed or repeated apply** can leave a half-created environment that bills a little (A). The recovery table in §7.3 always ends in a fresh plan or a destroy.
- **The state bucket persists** after destroy and costs cents (A); that is intended.
- **Cost surprise window:** between a runaway and the budget email there can be hours (§10.2). That is why V35 and the alarms are read manually during the phase and the emergency response exists.

### 10.4 Risks specific to this phase

| # | Risk | Likelihood / impact | Mitigation |
|---|---|---|---|
| R1 | The first real plan shows something the mocks hid (names, limits, values) | Medium / medium | Review script and owner review at A5; no apply on a surprise |
| R2 | Credentials leak through a command, log, plan file or an agent transcript | Low / high | §7.1; T2, T5, T9; O1-O4; the wrappers print counts and names only |
| R3 | An apply or destroy runs without a fresh approval | Low / high | Typed hash tied to the saved plan (T5); per-gate rule (§6) |
| R4 | Destroy deletes data someone cares about | Medium / high | See §10.3; dev only; A12 preview and confirmation; optional backup (decision 15) |
| R5 | Re-create surprises: new domain, new secrets, new bucket names | Certain / low | Expected; the publish step runs again; smoke re-run |
| R6 | State loss or lock problems | Low / high | §7.2; versioned bucket; local bootstrap backup; `recovery/` (T5) |
| R7 | Alarms silently dead (patterns do not match on real CloudWatch) | Medium / medium | V30 before traffic; fallback patterns pre-planned |
| R8 | The smoke test trips shared route throttles or the register limit and looks like an app fault | Medium / low | At most two runs per hour; a 429 is recorded as a finding |
| R9 | A new account blocks a service (CloudFront verification, Lambda quota, Budgets permission) | Medium / low (delay) | A0 probes; owner opens requests early |
| R10 | Cost abuse while the app is public | Low / medium | Budget, ceilings, emergency response (§7.3), decision 14 |
| R11 | Scope creep (OIDC, scanners, WAF, per-user throttle, agent isolation) | Medium / medium | Out-of-scope list; decision 18; §14 |
| R12 | The re-create stalls on a reused SQS queue name | Medium / low | Wait of T12; recovery row in §7.3 (U24) |
| R13 | Bootstrap ordering mistake leaves the state bucket unmanaged or the repo with an active backend too early | Low / high | Local-state apply first, backup with sha256, activation commit only at A3 (WP6, Stage 2) |
| R14 | An agent process is active while an SSO login is live (the safeguard is procedural, O6) | Low / high | O1 quiet period, short sessions, dedicated account, budget; stronger isolation deferred (§14) |

## 11. Owner decisions (recommended defaults)

### 11.1 Minimal G1 decision list

G1 approves engineering scope and architecture only. It needs these answers; every other decision below may simply take its recommended default.

1. **Scope and operating model:** this scope and Option B (§3.1): the owner runs every AWS command, agents are offline-only, authentication happens in a quiet period, the safeguard is procedural (decision 3).
2. **Account and identity:** a dedicated, otherwise empty AWS account, and one administrator-level SSO permission set for Phase 8, accepted explicitly as a security trade-off (decisions 1 and 2), with the $5 budget and the alert address (decisions 5 and 6).
3. **AWS CLI:** a pinned `amazon/aws-cli` image by digest, chosen at G2 (decision 4).
4. **Deploy shape:** staged plan then apply of an approved hash, the `distribution_id` output, and the bootstrap activation order (decisions 9, 10, 12).
5. **Destroy and re-create:** accepted for `dev`, with all its data lost (decision 15); the end state is decided later at A14a/A14b (decision 14).
6. **Proposed thresholds** (decision 20) accepted as starting values.

### 11.2 All decisions

1. **AWS account:** which account (the id goes only in uncommitted tfvars)? **Recommended: a dedicated, otherwise empty account**, because the budget is account-wide (`modules/observability/main.tf:133-135`) and the provider is locked to one account id. Say so clearly if a shared account is chosen.
2. **Identity:** one IAM Identity Center SSO user with one administrator-level permission set on the dedicated dev account, for Phase 8 only, with an explicit session length (at least 4 hours for Stages 4 and 8)? **Recommended: yes** (ADR 0010 names SSO), replaced by a least-privilege OIDC role in L2. Alternative: a hand-written least-privilege policy, which costs time and risks failing mid-apply. **Administrator level is a security trade-off the owner accepts explicitly at G1:** a leaked session could do anything in that account, which is why the account is dedicated and the session short.
3. **Operating model (Option B, supersedes the earlier agent-isolation decisions):** the owner executes every AWS command; Claude Code and ECC work offline only and never receive credentials; no agent process is active during an authenticated session (O1); output reaches an agent only as redacted summaries (O2); the owner reads the tooling diff before each session (O3). **Recommended: yes.** Alternative, deferred: agent-run verification with a read-only identity and a separate OS user or machine (§14).
4. **AWS CLI source:** host AWS CLI v2 for `aws sso login` and export only; everything else through a pinned `amazon/aws-cli` image by digest, chosen in Q6 and approved at G2? **Recommended: yes**, consistent with the repo's pinned-image rule (`Makefile:352-367`). Alternative: the host CLI for all calls (unpinned).
5. **Alert email:** which address, with mailbox access confirmed? **Recommended:** the owner's own address, in uncommitted tfvars only.
6. **Budget:** keep $5/month (`variables.tf:122-126`)? **Recommended: yes**; raise it if the account carries other spend.
7. **Reserved concurrency:** decided after A0 and before A5, from the quota read at A0. **Recommended:** keep -1 if any reservation would break the account's unreserved minimum; otherwise reserve a small number for the worker so an API flood cannot starve it. No number is chosen here because the quota is unknown.
8. **Smoke on AWS:** a separate `smoke-aws-edge.sh` for the AWS-only checks and a pin of the S3 host in `smoke.sh` (WP5)? **Recommended: yes.** Alternative: an `--api-gateway-url` mode in `smoke.sh` (Phase 6's suggestion), which mixes credentialed and public concerns.
9. **Bootstrap state:** apply with local state, back the file up with its sha256, then migrate it into the bucket under `bootstrap/terraform.tfstate` by activating the example backend block in one small reviewed commit (WP6, A3)? **Recommended: yes.** Alternatives: keep the state local for good; or an untracked `backend_override.tf` (git-ignored, `.gitignore:25-28`), which leaves no reviewed commit behind and is less auditable.
10. **Deploy shape:** staged `make deploy-plan` / `make deploy` with a typed approval of the plan hash (T5, T7) instead of one command? **Recommended: yes.**
11. **Invalidation path:** `/*`? **Recommended: yes** (one path; avoids the `/` vs `/index.html` question, U12). Alternative: `/index.html` plus `/`, which relies on an unconfirmed cache-key assumption.
12. **Output:** add `distribution_id` as the eighth output and amend D26 (WP4)? **Recommended: yes.** There is no safe alternative (`list-distributions` returns the origin custom header, which is the secret).
13. **Retry policy for failures:** one fix-forward attempt by `tdd-guide` offline, then escalate to opus/high, then stop and ask (routing rule)? **Recommended: yes.**
14. **End state of `dev`:** leave it running after the re-create smoke, or destroy it? **Recommended:** leave it running for the 48-hour observation window of V37 and the day-after billing read (no login open during it), then decide at A14b (A14a only chooses between the window and an immediate destroy) and destroy unless the owner wants to keep using it. Idle cost is documented as about $0, but the app is public with open registration (§10.2). Alternative: destroy at once, which removes the exposure but gives up V37.
15. **Data before destroy:** accept loss of everything in `dev`, as decided in Phase 7 decision 5? **Recommended: yes**, provided the owner confirms at A12 that no real data was entered. Alternative: take an on-demand DynamoDB backup first (not implemented in Terraform; extra cost; restore path untested). Also decide what to do with the automatic system backup AWS may keep after the table is deleted (U29): delete it as an A-x step, or accept its retention.
16. **Bootstrap is never destroyed** in this phase (`prevent_destroy`)? **Recommended: yes.**
17. **Optional housekeeping (WP1):** change the stale `eu-west-1` local defaults (`.env.example`, `docker-compose.yml`, `docs/local-dev.md:303`, `:315`, `:339`) and the stale comment now? **Recommended:** yes, in the first commit of the branch, as a tiny local-only change; it can also wait for Phase 9.
18. **Not in Phase 8:** per-user throttle (Phase 5 M1), build-time bucket origins (Phase 5 L2), request deadline in code (Phase 7 decision 9), WAF, OIDC, agent isolation? **Recommended: yes**, all stay on the later list (§14). If the first live run shows a real need, each becomes its own owner decision.
19. **Leftover smoke users:** accept them (there is no delete endpoint, `phase-6-monthly-summary.md:23`)? **Recommended: yes**; the destroy wipes them, and users created after the final re-create are the owner's.
20. **Thresholds I proposed** (768 MB worker memory, 600 s DLQ wait, a burst of 40 requests, two smoke runs per hour, the 30-minute plan age of T5, the 60-minute credential margin of T2, the 90-second queue-name wait of T12)? **Recommended: yes**; they are not facts from the repository.
21. **Claude Code permission rules** (optional): the owner may add `.claude/settings.json` deny rules for `aws`, `scripts/terraform-aws.sh`, `scripts/aws*.sh`, `scripts/publish-site.sh` and `make deploy*|destroy*|aws-*|publish-site`, so an agent session cannot run them by mistake? **Recommended: yes**, as a guard against mistakes only (O6). I did not and will not edit settings or CLAUDE.md.

## 12. Critical path, agents and the results record

**Critical path:** G1 → decisions 1-5 and account readiness (P1-P4, P7: external lead time, the longest unknown) in parallel with WP1 research → WP2 (wrapper and plan review) → WP3 → WP7 → WP8 → G2 → G3 → A0 → A1/A2/A3 (bootstrap) → A4/A5/A6 (first apply) → A7 (alarm email) → Stage 5 (alarm proof) → A8 publish → A9 smoke and live checks → A10 (synthetic message) → A11/A12/A13 (destroy, re-create, smoke) → A14a → WP9 results → close-out checks → (observation window) V37 → A14b. WP4, WP5, WP6 and the AWS CLI image selection of WP3 (Step 0 Q6) run in parallel with WP2 once WP1 is done. Nothing from Stage 1 onward can start before the owner's account work and G3, so the owner should begin P1-P4 early.

**Work suited to specialised agents** (offline only; routing from `.claude/rules/project/model-routing.md`; model and effort are passed explicitly on every call):

| Work | Agent | Model / effort |
|---|---|---|
| WP2-WP7 test-first implementation | `ecc:tdd-guide` | sonnet / medium |
| Review of each shell, Makefile and Terraform diff (one reviewer per diff) | `ecc:code-reviewer` | sonnet / medium |
| Security review of WP2 and WP3 (credentials, input handling, file operations, external calls) before G2, and of the WP6 activation commit | `ecc:security-reviewer` | opus / high |
| First failed fix after a live FAIL | `ecc:tdd-guide`; then `ecc:build-error-resolver` | sonnet / medium; then opus / high |
| Results file, ADR notes, PLAN row, README | `ecc:doc-updater` | haiku / low |
| Running offline test suites and summarising output; image digest lookups | `general-purpose` | haiku / low |
| Library or CLI documentation lookups (WP1 Step 0) | `ecc:docs-lookup` | haiku / low |
| **Anything that holds credentials or touches AWS** | **no agent: the owner** (§3.1) | n/a |

The main session reconciles agent output and reads every diff, as in Phase 7 where it caught a merged `.PHONY` entry (`phase-7-infrastructure.md:362`). Reviewer verdicts are advisory; the owner's reading at G2 is the control (O3). Run `/ecc:cost-report` at the end and compare it with Phase 7.

**Branch, PR and gates.** One branch (`feat/phase-8-first-deployment`) and one PR, draft from WP2, ready after WP9, squash-merged by the owner. Ask before every commit and push (gate A-c), and watch CI by run id and head SHA. CI gets no credentials and no deploy step; new scripts are tested in the existing `guards` job through `make test-shell` (`ci.yml:135`). Delete nothing in the repository without asking.

## 13. Review record

### 13.1 How this plan was built

- **First draft (2026-11-07):** reviewed by a consistency reviewer (about 90 citations sampled; line numbers, module count, dependencies, V-to-WP coverage, reserved-concurrency timing fixed) and a security reviewer (the gates were only a procedure; secrets leaked through CloudFront, `get-function` and SNS calls; `terraform test` and unconstrained `init` flags; destroy confirmation lived only in the Makefile; plan files in the repo; session expiry mid-apply). The suggestion to mount the repo read-only was not adopted as written: Terraform writes `.terraform/` there, so WP2 decides whether an overlay is feasible.
- **Reconciliation with a second, independently produced plan (2026-11-09).** Every claim about the repository was checked against the working tree. Taken: the bootstrap backend ordering (contradiction 10); the SQS queue-name wait (12); the missing coverage of `check-image-pins.sh` (4); a mandatory research step with a URL per claim (WP1); `index.html` last and a per-publish snapshot (T8); the destroy preview (T12); the `ASIA` plus session-token rule and `umask 077` plan files (T2, T5); refused `-var`, `-refresh=false`, `-lock=false` (T3); extra read-only AWS calls and fixed-form writes for `set-alarm-state` and `send-message` (T9); the `smoke.sh` Phase 8 items (8); the repeated DLQ/worker/error checks in Stage 8 (V39); the stale README and PLAN row 7 text (14); the carried-item table (§8a). Rejected: approval by a `CONFIRM=` variable (typed hash kept); plan files inside the repo; invalidating `/index.html` plus `/`; raw `list-subscriptions-by-topic`; `s3 sync`; `-replace` at plan time; a DLQ read right after the smoke as proof; a 819 MB worker memory bar (the worker limit is `768M`, `modules/worker/main.tf:79`); a separate ADR for the gate design.
- **Three further review passes (2026-11-09)** by a consistency reviewer and a security reviewer found real defects, most of them in the agent-isolation designs added by the previous pass: a read-only identity and permission-set denies, an agent/owner class split, owner-class hand-off files, a break-glass marker and scripts, builds from `git archive`, file-hash records, option A isolation. Their repository-based findings are kept in this plan (the pin-test gap, the recovery artefacts, the plan lifetime between invocations, the A3/V40 bootstrap-state interplay, the V38 four views, the `get-function-configuration` and `get-distribution` fixed forms, the A14a/A14b split, the WP9 ordering). The agent-isolation designs themselves are deferred (§14).
- **Option B simplification (2026-11-09).** The owner selected Option B: the owner runs every AWS command and no agent holds credentials, as a procedural safeguard (§3.1). Removed or deferred: see §14. Kept: every item of the essential list in §3 and §5-§8. The simplified text has not had a further independent review pass; a consistency check of references and dependencies was done by hand (WP numbers, T and O numbers, gates, V rows, U rows, decisions).

## 14. Later (deferred, not part of Phase 8)

These existed only to support AI agents holding AWS credentials, or to harden against a hostile process on the owner's machine. Under Option B they are not needed for a safe first manual deployment. Each becomes its own owner decision if agent-driven AWS work is ever wanted. The earlier designs were reviewed in §13 and are not repeated here.

- **Agent-run verification:** a separate read-only Identity Center user and permission set with explicit denies (state, Lambda environment, CloudFront origins, SNS subscriptions, Budgets subscribers), an agent/owner class split of checks, and an owner-written outputs hand-off file.
- **Agent isolation:** the agent as a new unprivileged OS user (no `docker`, `wheel` or sudo; rootless Docker) or on another machine or VM, per-class Docker endpoint rules, terminal and clipboard hardening, owner/agent git separation.
- **Control of the owner's own tooling against a hostile agent:** a recorded file-hash list enforced by the wrappers, builds from a `git archive` export with no `.git`, a pre-plan static check of Terraform sources, `core.fsmonitor`/hooks guards. (Kept in light form: O3, the owner's diff reading.)
- **Break-glass tooling:** fixed scripts with pinned hashes, a marker file blocking `apply` after the emergency steps, an Identity Center `TokenIssueTime` deny rehearsed at G3, CloudTrail review of write calls and AccessDenied events. (Kept in light form: the manual emergency response of §7.3.)
- **Approval hardening:** `/dev/tty`-read approval as a claimed boundary. (Kept as a typed confirmation, a guard against mistakes.)
- **Other carried work:** OIDC role and `deploy.yml` (L2), tflint/checkov (L3), Lambda-error and API-5xx alarms (L4), WAF (L8), state-bucket hardening (minimum TLS version, state readers; `phase-7-infrastructure.md:344`), the per-user upload throttle (Phase 5 M1), build-time bucket origins (Phase 5 L2).

## Appendix A: Inputs the owner supplies, and values already fixed

| Input | Where | Value | Status |
|---|---|---|---|
| `aws_region` | `terraform.tfvars` (both roots), `backend.hcl` | `us-east-1` | Fixed by ADR 0013 |
| `aws_account_id` | `terraform.tfvars` (both roots) | 12 digits | **Owner supplies**; never committed |
| `alert_email` | `envs/dev/terraform.tfvars` | address | **Owner supplies**; sensitive (`variables.tf:119`) |
| `origin_verify_active_slot` | `envs/dev/terraform.tfvars` | `a` | Recommended default (`terraform.tfvars.example:8`) |
| `origin_verify_rotation_a/b` | `envs/dev/terraform.tfvars` | `"1"` / `"1"` | Defaults (`variables.tf:96`, `:107`) |
| `monthly_budget_usd` | `envs/dev/terraform.tfvars` | `5` | Default; decision 6 |
| `api_/worker_reserved_concurrent_executions` | `envs/dev/terraform.tfvars` | `-1` until A0 | Decision 7 |
| Layer versions | `bref-layers.auto.tfvars.json` (committed) | php-85 v20, gd-php-85 v1 | Generated, checked in CI (`ci.yml:57`) |
| Lambda package path and sha256 | `package.auto.tfvars.json` (generated, git-ignored) | from `make lambda-build` | Built at Stage 0 and by `deploy-plan` |
| Bootstrap inputs | `bootstrap/terraform.tfvars`, `bootstrap/backend.hcl` (from the WP6 examples; git-ignored) | region and account id; bucket, key `bootstrap/terraform.tfstate`, region | **Owner supplies**; the `backend.hcl` is used only at A3 |
| Deploy directory | `${XDG_STATE_HOME:-$HOME/.local/state}/expense-tracker-deploy/` (outside the repo, mode 700): `plans/`, `recovery/` | created by the wrapper | `plans/` holds a plan from `plan` until its apply attempt, rejection or expiry; `recovery/` is never cleaned automatically (T5) |
| Destroy stamp | `${XDG_STATE_HOME:-$HOME/.local/state}/expense-tracker-destroy-stamp` (mode 600) | written by `destroy` at start and at exit | Read by `apply` for envs/dev (T12); never auto-removed |
| State bucket | `backend.hcl` | `expense-tracker-tfstate-<account>-us-east-1` | Derived (`bootstrap/main.tf:12`) |
| State keys | `backend.hcl` | `envs/dev/terraform.tfstate`; `bootstrap/terraform.tfstate` | Fixed (`backend.hcl.example:5`; `phase-7-infrastructure.md:63`) |

## Appendix B: Expected resource types in the first plan (for `review-plan.sh`)

From the module sources (`infra/terraform/envs/dev/*.tf`, `infra/terraform/modules/*/main.tf`): `aws_apigatewayv2_{api,integration,route,stage}`, `aws_budgets_budget`, `aws_cloudfront_{distribution,function,origin_access_control,origin_request_policy,response_headers_policy}`, `aws_cloudwatch_{log_group,log_metric_filter,metric_alarm}`, `aws_dynamodb_table`, `aws_iam_{role,role_policy}`, `aws_lambda_{event_source_mapping,function,permission}`, `aws_s3_bucket` and its `_cors_configuration`, `_lifecycle_configuration`, `_notification`, `_ownership_controls`, `_policy`, `_public_access_block`, `_server_side_encryption_configuration`, `_versioning`, `aws_sns_{topic,topic_subscription}`, `aws_sqs_{queue,queue_policy,queue_redrive_allow_policy,queue_redrive_policy}`, `random_id`, `random_password`. Expected multiplicities, from the source and Phase 7 plan (to be confirmed against the first real plan): 3 app buckets, 3 log groups (API, worker, API Gateway access logs), 2 Lambda roles, 2 `random_password`, 6 alarms, 6 routes (`$default` plus the five throttled ones, `modules/api/main.tf:34-43`). Absent by design: IAM users and keys, KMS, VPC/NAT, WAF, Lambda aliases and versions, X-Ray. A type outside this list is a stop, not a warning.

## Notes on confidence

- **High:** what exists and does not exist in the repository (read and cross-checked); the structure of the sequence and the gates; the list of carried Phase 7 items.
- **Medium:** the proposed tooling is a design. WP2-WP7 are untested ideas until built; the typed-approval mechanism and the `export-credentials` approach are the least certain parts. The bootstrap ordering and the AWS CLI allow-list were checked against the repository but not run.
- **Low / unknown:** everything about the owner's AWS account, identity setup and mailbox; every cost figure (all are documented estimates from Phase 7); every item in §9.
- The thresholds in §3 and §8 that I chose (768 MB, 600 s, a burst of 40, two smoke runs per hour, 30-minute plan age, 60-minute credential margin, 90-second queue wait) are proposals for the owner to approve, not facts from the repository.
- The safeguards against an agent reading credentials are procedural (O6), not a security boundary.

