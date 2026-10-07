# Implementation Plan — Expense Tracker

> **Status: DRAFT — under review. Not approved.**
> This plan must be reviewed and approved before any implementation starts.
> The architectural decisions it relies on are recorded as ADRs in [`docs/adr/`](adr/README.md), all currently
> with status **Proposed**. See [Review](#14-review) for how to give feedback.

| | |
|---|---|
| Requirements | [`docs/BRIEF.md`](BRIEF.md) |
| Constraints | [`CLAUDE.md`](../CLAUDE.md) |
| Drafted | 2026-10-07 |
| Last updated | 2026-10-07 |

---

## 1. Summary

A personal expense tracker built as a realistic proof of concept: small in scope, but built and deployed like a
production application. It uses a Vue single-page app, a JSON API written in vanilla PHP (no framework), and a
**fully serverless AWS architecture**: nothing runs, and almost nothing is billed, when nobody is using it. The whole
system also runs locally with Docker and no AWS account.

## 2. Requirements (restated from the brief)

**Users can:**
- create an account, sign in and sign out;
- record expenses with an amount, date, description and category;
- manage their own categories;
- view, filter, edit and delete their expenses;
- attach a receipt (image or PDF) to an expense and preview it;
- see a monthly summary of spending by category.

**The system must:**
- let users see and change only their own data;
- let receipts upload without passing through the application server;
- process receipts (e.g. thumbnails) without blocking the user;
- be safe to expose on the public internet;
- run fully locally without AWS, and deploy to AWS from code (create and destroy);
- have automated tests and a CI pipeline.

**Out of scope:** sharing between users, mobile apps, multi-currency conversion, OCR, email flows, i18n.

## 3. Key decisions

| ADR | Decision |
|---|---|
| [0001](adr/0001-serverless-aws-php-on-lambda-with-bref.md) | Fully serverless on AWS; PHP runs on Lambda using the Bref runtime layer |
| [0002](adr/0002-dynamodb-single-table-data-model.md) | DynamoDB single-table data model |
| [0003](adr/0003-vanilla-php-backend-structure.md) | Vanilla PHP back-end structure: Composer autoload, hand-written router, problem-details errors |
| [0004](adr/0004-server-side-sessions-with-cookies.md) | Server-side sessions in an HttpOnly cookie instead of JWTs |
| [0005](adr/0005-single-origin-through-cloudfront.md) | One domain for app and API via CloudFront |
| [0006](adr/0006-direct-to-s3-receipt-uploads-and-async-processing.md) | Direct-to-S3 receipt uploads; asynchronous processing via S3 → SQS → worker Lambda |
| [0007](adr/0007-local-development-with-docker-compose.md) | Local development with Docker Compose and AWS-compatible emulators |
| [0008](adr/0008-frontend-stack.md) | Front-end stack: Vue 3, TypeScript, Vite, Pinia, Vue Router |
| [0009](adr/0009-terraform-for-infrastructure.md) | Terraform for infrastructure as code |
| [0010](adr/0010-github-actions-ci-cd-with-oidc.md) | GitHub Actions for CI/CD, deploying with OIDC (no stored AWS keys) |

## 4. Architecture

```
                         ┌────────────── AWS ───────────────────────────────────────────────┐
Browser ──HTTPS──► CloudFront ──┬── /*      → S3 bucket (built Vue app)                        │
                         │      └── /api/*  → API Gateway (HTTP API) → Lambda: PHP API ──► DynamoDB
                         │                                                  │                  │
                         │                       creates short-lived signed S3 URLs            │
Browser ──signed POST──► S3 receipts bucket ──ObjectCreated──► SQS (+ dead-letter queue)       │
                         │                                            └──► Lambda: PHP worker ─┘
                         │                                                 (thumbnail + status)
                         └──────────────────────────────────────────────────────────────────┘
```

- **No VPC, no NAT gateway, no database server, no database password.** Every component authenticates with IAM.
- **Idle cost is close to $0**: everything is billed per request or per GB stored. AWS WAF has a fixed monthly fee,
  so it is available behind a switch but off by default.
- App and API are served from **one domain**, so the session cookie is first-party and the API needs no CORS.

### Local equivalent (no AWS account needed)

| AWS | Local (Docker Compose) |
|---|---|
| CloudFront + S3 site | Vite dev server (proxies `/api` to the API) |
| API Gateway + Lambda (API) | nginx + php-fpm, running the same PHP code |
| DynamoDB | `amazon/dynamodb-local` |
| S3 | MinIO (S3-compatible) |
| S3 event → SQS → worker Lambda | MinIO bucket notification (webhook) → worker container, running the same PHP handler |

## 5. Back-end design

- **PHP 8.3, no framework.** Composer is used for PSR-4 autoloading and a few libraries: `aws/aws-sdk-php`, plus
  PHPUnit, PHPStan and PHP-CS-Fixer for development.
- `public/index.php` is the single entry point. A small hand-written router dispatches to handlers through
  middleware: error handling, request logging (JSON lines), authentication, and CSRF protection.
- **Errors** follow RFC 9457 "problem details" (`application/problem+json`), with field-level validation errors.
- **Modules:** `Http`, `Auth`, `Categories`, `Expenses`, `Receipts`, `Reports`, `Infrastructure` (DynamoDB, S3, clock,
  config).
- **Money** is stored as integer cents, with one currency configured for the whole app.
- On AWS, Bref provides only the PHP runtime for Lambda. The same code runs under php-fpm locally.

### API (`/api/v1`, JSON)

| Area | Endpoints |
|---|---|
| Auth | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /auth/me` |
| Categories | `GET /categories`, `POST /categories`, `PATCH /categories/{id}`, `DELETE /categories/{id}` (expenses in a deleted category become *Uncategorized*) |
| Expenses | `GET /expenses?from&to&category&q&cursor`, `POST /expenses`, `GET /expenses/{id}`, `PATCH /expenses/{id}`, `DELETE /expenses/{id}` |
| Receipts | `POST /expenses/{id}/receipt/upload` (returns a signed upload policy), `GET /expenses/{id}/receipt` (status + short-lived view URLs), `DELETE /expenses/{id}/receipt` |
| Reports | `GET /reports/monthly?month=YYYY-MM` |
| Health | `GET /health` |

Another user's resource always returns `404`, so the API never reveals that it exists.

## 6. Data model (DynamoDB, single table)

See [ADR 0002](adr/0002-dynamodb-single-table-data-model.md) for the reasoning.

| Item | PK | SK | Main attributes |
|---|---|---|---|
| User | `USER#<userId>` | `PROFILE` | email, passwordHash, createdAt |
| Email lock | `EMAIL#<normalisedEmail>` | `EMAIL` | userId (written in the same transaction as the user, so emails are unique) |
| Category | `USER#<userId>` | `CAT#<categoryId>` | name, colour |
| Expense | `USER#<userId>` | `EXP#<yyyy-mm-dd>#<expenseId>` | amountCents, description, categoryId |
| Receipt | `USER#<userId>` | `RCPT#<expenseId>` | status (`pending`/`ready`/`failed`), objectKey, thumbnailKey, contentType, size |
| Session | `SESSION#<tokenHash>` | `SESSION` | userId, expiresAt (DynamoDB TTL) |
| Login throttle | `THROTTLE#<email or ip>` | `THROTTLE` | attempts, expiresAt (DynamoDB TTL) |

**Access patterns:**
- A user's own data always uses `PK = USER#<current user>`, so isolation is built into the key.
- Expenses in a date range: `SK BETWEEN EXP#<from> AND EXP#<to>~`.
- Monthly summary: query one month of a user's expenses and total them by category in PHP.
- Text and category filters: applied within the user's own partition.
- Fetching a single expense by id requires a secondary index (or the date in the id), to be settled in Phase 1.

**Rules enforced in code (and covered by tests), since DynamoDB has no foreign keys:**
- an expense's category must belong to the same user;
- deleting a category marks its expenses Uncategorized;
- deleting an expense also deletes its receipt.

The table is billed on demand, with point-in-time recovery and TTL enabled.

## 7. Receipts flow

1. The user picks a file. The front-end asks the API for an upload policy.
2. The API returns a **signed S3 POST policy**. It limits the size (≤ 10 MB), the content types (JPEG, PNG, WebP,
   PDF) and the exact key (`receipts/<userId>/<uuid>`). The API also records the receipt as `pending`.
3. The browser uploads directly to S3; the file never passes through PHP.
4. S3 sends an `ObjectCreated` event to SQS. The worker Lambda then:
   - checks the file's real type from its first bytes (magic bytes);
   - makes a thumbnail for images (GD);
   - marks the receipt `ready`, or `failed` if the file is invalid.

   Repeated failures land in a dead-letter queue.
5. The front-end polls the receipt status. Previews use **short-lived signed GET URLs**. PDFs show a generic icon
   and open in the browser's built-in viewer; no PDF thumbnails (that would need Ghostscript in Lambda).

## 8. Front-end design

- Vue 3, TypeScript, Vite, Vue Router, Pinia; plain CSS with a few shared components; Chart.js for the summary.
- **Pages:**
  - Register and Login;
  - Expenses (list, filters, pagination);
  - Expense form (with receipt upload, progress and preview);
  - Categories;
  - Monthly summary.
- Route guards send signed-out users to Login.

## 9. Security baseline

- **HTTPS only.** CloudFront adds security headers: CSP, HSTS, `X-Content-Type-Options`, `Referrer-Policy` and
  frame protection.
- **Sessions:**
  - a random token in a `__Host-` cookie that is `HttpOnly`, `Secure` and `SameSite=Lax`;
  - only a hash of the token is stored;
  - sessions expire via TTL, and sign-out deletes them.
- **Passwords** are hashed with `password_hash` (Argon2id).
- **CSRF:** `SameSite` cookie, plus a required custom request header and an `Origin` check on requests that change
  data.
- **Abuse limits:** login attempts are throttled per account and per IP; API Gateway enforces rate limits.
- **Receipts:** private, encrypted buckets with all public access blocked; strict upload policies; content checked
  by the worker; view links expire within minutes.
- **Least privilege:** each Lambda has its own IAM role, limited to what it needs.
- **Optional:** AWS WAF with managed rule sets (off by default because of its fixed monthly fee).

## 10. Testing and CI/CD

| Layer | Tools |
|---|---|
| PHP | PHPUnit: unit tests, plus integration tests against DynamoDB Local and MinIO; PHPStan (strict); PHP-CS-Fixer |
| Front-end | Vitest + Vue Test Utils; ESLint; Prettier; `vue-tsc` |
| End-to-end | Playwright against the full Docker Compose stack, including a test that user A cannot see user B's data |
| Infrastructure | `terraform fmt` / `validate`, tflint, checkov |

- **CI:** GitHub Actions runs all of the above on every pull request.
- **CD:** a separate, manually triggered workflow, protected by an approval environment. It runs Terraform plan,
  waits for approval, then applies, uploads the front-end and runs a smoke test. It authenticates to AWS with
  **OIDC**, so no AWS keys are stored in GitHub.

## 11. Repository layout

```
backend/            PHP API + worker (shared code), Dockerfile, tests/
frontend/           Vue app, tests/
infra/terraform/    bootstrap/ (state bucket), modules/, envs/dev/
docs/               BRIEF.md, PLAN.md, adr/, architecture.md, local-dev.md, deploy.md
.github/workflows/  ci.yml, deploy.yml
docker-compose.yml
Makefile            make up / make test / make lint …
```

## 12. Phases

Each phase is delivered as one branch and pull request. It follows the ECC workflow:
- tests first, using the `tdd-workflow` skill;
- review with the `php-reviewer`, `vue-reviewer` and `security-reviewer` agents and `/code-review`;
- `/verification-loop` before merge;
- `/update-docs` to keep documentation current.

| # | Phase | Delivers | Size | Status |
|---|---|---|---|---|
| 0 | Foundations | Repo skeleton, Docker Compose (DynamoDB Local, MinIO, php-fpm, worker), Makefile, CI skeleton | S | Not started |
| 1 | Back-end core | Router, middleware, problem-details errors, DynamoDB access layer, table-creation script, `/health`, PHPUnit + PHPStan | M | Not started |
| 2 | Authentication | Register/login/logout/me, sessions, CSRF, login throttling | M | Not started |
| 3 | Categories and expenses API | CRUD, filters, pagination, ownership rules, cross-user tests | M | Not started |
| 4 | Front-end | App shell, auth pages, route guards, expenses and categories UI, Vitest | M | Not started |
| 5 | Receipts | Signed uploads, MinIO → worker locally, thumbnails, status polling, preview | L | Not started |
| 6 | Monthly summary | Report endpoint and chart page | S | Not started |
| 7 | End-to-end tests and full CI | Playwright journeys, all CI checks required on PRs | M | Not started |
| 8 | Infrastructure (Terraform) | State bootstrap, DynamoDB, Lambdas (Bref layers), API Gateway, S3 ×2, SQS + DLQ, CloudFront + headers, IAM, logs/alarms, budget alarm. **Written and planned only, not applied.** | L | Not started |
| 9 | First deployment | GitHub OIDC role, deploy workflow. **Applied only with the owner's explicit go-ahead.** Smoke test, then a full destroy → re-create test | M | Not started |
| 10 | Documentation pass | Architecture diagrams, local-dev guide, deploy/destroy runbook, "how a request flows" walkthrough | S | Not started |

Phases 0–7 run entirely locally and cost nothing. AWS is only involved from Phase 8.

## 13. Risks

| Risk | Level | Mitigation |
|---|---|---|
| Data rules (ownership, cascades) live in PHP, not in database constraints | Medium | Each rule has dedicated tests, including cross-user tests |
| DynamoDB access patterns turn out wrong once built | Medium | Patterns listed up front (ADR 0002); integration tests run against DynamoDB Local from Phase 1 |
| Local and AWS behave differently (MinIO vs S3 events, php-fpm vs Lambda) | Low | Same PHP handlers on both sides; differences only in configuration; smoke test after deploy |
| PHP Lambda cold start after idle (~0.3–0.5 s) | Low | Acceptable for a proof of concept; recorded in ADR 0001 |
| AWS resources left running or costs creeping | Low | Serverless pricing, budget alarm, destroy tested in Phase 9 |
| Bref / Lambda layer versions change | Low | Pin versions |

## 14. Review

This plan is a **draft**. Before implementation starts:

1. Reviewers read this document and the ADRs in [`docs/adr/`](adr/README.md).
2. Feedback goes in the pull request that introduces these documents, or as edits to them.
3. When a decision is agreed, its ADR status changes from **Proposed** to **Accepted** (or **Rejected** /
   **Superseded** if it changes).
4. When all ADRs are settled, the status at the top of this file changes to **Approved**, with the date and the
   approvers. Phase 0 starts only after that.

**Points that would benefit most from review:**
- DynamoDB vs a relational database (ADR 0002): this is the decision with the most knock-on effects.
- Bref as the PHP Lambda runtime, and whether it fits the "no framework" constraint (ADR 0001).
- Cutting PDF thumbnails (section 7).
- WAF off by default (section 9).

**Approval record:**

| Date | Reviewer | Outcome |
|---|---|---|
| — | — | — |
