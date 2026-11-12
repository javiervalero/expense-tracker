# Deploy and destroy runbook (owner's terminal)

> Status: **not yet run on a real AWS account.** Based on [ADR 0014](adr/0014-slim-manual-first-deployment.md)
> and the [Phase 8 plan](plans/phase-8-first-deployment.md). Record surprises in `docs/plans/phase-8-results.md`.

You deploy this proof-of-concept app to the shared AWS sandbox account. You run every AWS command yourself.

## 1. Before you start

You need:

- **Docker** running on this machine, **AWS CLI v2** on the host, and **make**.
- An **AWS SSO profile** for the sandbox account (`us-east-1`, $5 budget). `<profile>` below is your profile name.
- **Claude Code and all other agent sessions closed** before you log in, until `aws sso logout`.

Create these three git-ignored files from their examples. Your account id and alert email go only in these files.

| File to create | Copy from | Fill in |
|---|---|---|
| `infra/terraform/envs/dev/terraform.tfvars` | `terraform.tfvars.example` in the same folder | `aws_account_id`, `alert_email` |
| `infra/terraform/envs/dev/backend.hcl` | `backend.hcl.example` in the same folder | `bucket` (step 3.7) |
| `infra/terraform/bootstrap/terraform.tfvars` | `terraform.tfvars.example` in the bootstrap folder | `aws_account_id` |

## 2. Safety rules

- **Agents never hold credentials.** No agent session may be open while an SSO login is live.
- **Temporary credentials only.** `scripts/terraform-aws.sh` refuses to run without `AWS_SESSION_TOKEN`.
- **Clean, reviewed commit first.** The credentialed container runs the repo's `.tf` files, so `git status` must be clean.
- **Never set `DOCKER_HOST` or `DOCKER_CONTEXT` to a remote daemon.** Your credentials would go to it.
- **Read every plan before you apply it.** Apply only saved plan files.
- **Plan files hold generated secrets.** `deploy-apply` deletes them. Delete any other plan file yourself.
- **Share no raw output with an agent.** No state, plans or `terraform show` output. Pass/fail summaries only. The one
  exception is the output of `make deploy-plan-summary`: it prints only counts and resource types, never names or values.
- **The bootstrap state is the only record of the state bucket.** Back it up outside the repo; never `git clean -fdx`.

## 3. First time only

1. Create the three files from the table in section 1.

2. Log in to SSO and export temporary credentials. Run all later `make` and `scripts/` commands in this terminal:

   ```bash
   aws sso login --profile <profile>
   eval "$(aws configure export-credentials --profile <profile> --format env)"
   ```

3. Check the account. The output must equal `aws_account_id` in `envs/dev/terraform.tfvars`, or run `aws sso logout`:

   ```bash
   aws sts get-caller-identity --query Account --output text
   ```

4. Initialise the bootstrap root (local state):

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/bootstrap init -input=false -lockfile=readonly
   ```

5. Save a plan. The file is created inside the bootstrap folder:

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/bootstrap plan -input=false -out=bootstrap.tfplan
   ```

   Read it. It must show **7 bucket resources to add and 0 to destroy**. Stop if not.

6. Apply that plan, then delete the plan file:

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/bootstrap apply -input=false bootstrap.tfplan
   rm infra/terraform/bootstrap/bootstrap.tfplan
   ```

7. Back up the bootstrap state to a private folder outside the repo, for example `<private-backup-folder>`:

   ```bash
   cp infra/terraform/bootstrap/terraform.tfstate <private-backup-folder>/
   ```

   Set `bucket` in `envs/dev/backend.hcl` to `expense-tracker-tfstate-<account id>-us-east-1`.

8. Initialise the dev stack against that bucket. Check that Terraform reports the S3 backend as configured:

   ```bash
   make deploy-init
   ```

## 4. Every version

1. Build the Lambda package and front end. No credentials needed. It must finish without errors. On a fresh checkout
   run `make frontend-install` once first (the front-end build fails without `frontend/node_modules`):

   ```bash
   make deploy-build
   ```

2. Save a plan and read it: no unexpected destroy or replace, and names start `expense-tracker-dev-`:

   ```bash
   make deploy-plan
   make deploy-plan-summary
   ```

   The summary lists how many resources are created, updated, deleted or replaced, by type. Stop if it shows a
   `WARNING` you did not expect. This first run should only create things. If you share the result with an agent, paste
   only the summary lines (from the `plan summary` header to the last line), not Terraform's or make's other messages.

3. Apply the saved plan. If your SSO login is old, log in and export credentials again (step 3.2) just before applying,
   so they do not expire mid-apply:

   ```bash
   make deploy-apply
   ```

4. Upload the site and invalidate CloudFront. Nothing is deleted:

   ```bash
   make publish-site
   ```

5. **First time only:** click the SNS confirmation link in the email sent to `alert_email`. Until then, alerts are not delivered.

6. Run the smoke test, then walk through the app in a browser with the console open (no CSP errors):

   ```bash
   make deploy-smoke
   ```

7. Check that the API cannot be called around CloudFront. A direct call without the secret origin header must return
   **403** (any path; the endpoint already starts with `https://`):

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev output -raw api_endpoint
   curl -i <api_endpoint>/api/v1/health
   ```

8. Check the site headers. Get the domain, then check the root and one file from `assets/`:

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev output -raw distribution_domain
   curl -I https://<distribution_domain>/
   curl -I https://<distribution_domain>/assets/<file-name>
   ```

   Root: `text/html; charset=utf-8` and `no-cache`. Asset: `public, max-age=31536000, immutable`.

9. Wait at least **10 minutes after the smoke test**, then check the dead-letter queue. The depth must be `"0"`:

   ```bash
   scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev output -raw dlq_url
   aws sqs get-queue-attributes --queue-url <dlq_url> --attribute-names ApproximateNumberOfMessages
   ```

10. Log out of SSO: `aws sso logout`.

## 5. Destroy

Destroy removes the `envs/dev` stack only. The bootstrap root and state bucket are never destroyed.

1. Save a destroy plan (only `DESTROY=1` makes one):

   ```bash
   make deploy-plan DESTROY=1
   ```

2. Read it. It must touch only `expense-tracker-dev-*` resources in `envs/dev`. Stop if not. Run
   `make deploy-plan-summary`: it must show the `DESTROY` header and deletes only.

3. Apply it: `make deploy-apply`

4. Check for leftovers. Only the state bucket should remain, and no `expense-tracker-dev` log groups:

   ```bash
   aws resourcegroupstaggingapi get-resources --tag-filters Key=project,Values=expense-tracker --query 'ResourceTagMappingList[].ResourceARN'
   aws logs describe-log-groups --log-group-name-prefix /aws/lambda/expense-tracker-dev
   ```

5. Check the next day's billing in the AWS console and note it in the results file.

Expected: DynamoDB keeps a **system backup** of a deleted table for up to **35 days**. Before you re-create anything,
**wait at least 60 seconds** (SQS queue names are reused slowly).

## 6. When something goes wrong

- **`QueueDeletedRecently`:** wait 60 seconds, then run `make deploy-plan` again.
- **Stuck state lock:** confirm nothing is running anywhere (no other terminal, no CI), then:
  `scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev force-unlock <LOCK_ID>`
- **Credentials expire during apply:** Terraform may write `infra/terraform/envs/dev/errored.tfstate` (it holds
  secrets). Export credentials again, run
  `scripts/terraform-aws.sh -chdir=infra/terraform/envs/dev state push errored.tfstate`, **then delete the file**.
- **First apply partly fails** (IAM propagation, API Gateway role, Budgets permission): run `make deploy-plan`
  and `make deploy-apply` again.
- **HTTP 429 during smoke:** the register limit is 5 per hour and login is throttled. Wait, then run it again.

## 7. Known unknowns (not yet confirmed on AWS; record what you see)

- How long SSO credentials last during a long CloudFront create or delete.
- Whether the Budgets alarm fires in a shared account. It lags by hours and is the only money backstop.
- Whether `aws s3 sync` sets the right content types (check with `curl -I`).
- Whether the Bref layers are accessible from the sandbox account.
- Credentials are visible to your user's processes and in `docker inspect` during a run. Accepted for a sandbox.
