# 0002. DynamoDB single-table data model

- Status: Proposed
- Date: 2026-10-07

## Context

The app stores users, sessions, categories, expenses and receipt metadata. Each user's data is private
(brief: "users can only ever see and change their own data"). Following [ADR 0001](0001-serverless-aws-php-on-lambda-with-bref.md),
the database should be serverless: no always-on server, near-zero idle cost. The whole system must also run locally
without an AWS account.

## Decision

Use **Amazon DynamoDB** with a **single table**: on-demand billing, point-in-time recovery and TTL enabled.

| Item | PK | SK |
|---|---|---|
| User | `USER#<userId>` | `PROFILE` |
| Email lock | `EMAIL#<normalisedEmail>` | `EMAIL` |
| Category | `USER#<userId>` | `CAT#<categoryId>` |
| Expense | `USER#<userId>` | `EXP#<yyyy-mm-dd>#<expenseId>` |
| Receipt | `USER#<userId>` | `RCPT#<expenseId>` |
| Session | `SESSION#<tokenHash>` | `SESSION` (expires by TTL) |
| Login throttle | `THROTTLE#<email or ip>` | `THROTTLE` (expires by TTL) |

**Design rules:**
- **Isolation by key:** every read or write of user data uses `PK = USER#<authenticated user id>`, taken from the
  session and never from the request.
- **Unique emails:** the user item and its email lock are written in one transaction, guarded by a condition
  that the email lock doesn't already exist.
- **Date ranges** are key conditions on the expense sort key.
- **Monthly summary:** query one month and total by category in PHP.
- **Filters:** category and text filters run within the user's own partition.
- **Integrity rules in PHP**, each with tests: category belongs to the user; deleting a category makes its expenses
  Uncategorized; deleting an expense deletes its receipt.

**Local development:** the official `amazon/dynamodb-local` container.

## Alternatives considered

- **RDS PostgreSQL.** Relational integrity and SQL reporting, but it is an always-on server (about $15+/month),
  needs a VPC, and Lambda then needs a VPC too. Rejected on the serverless preference.
- **Aurora Serverless v2 (PostgreSQL) with auto-pause.** Real PostgreSQL that pauses when idle, but the first
  request after a pause takes about 15 s, and it still needs a VPC.
- **Aurora DSQL.** Serverless and PostgreSQL-compatible with no VPC, but it supports only a subset of PostgreSQL
  (for example, no foreign keys), so local PostgreSQL and AWS would behave differently.

## Consequences

- No VPC, no database credentials (access is by IAM), no idle cost, no connection limits from Lambda.
- Per-user partitions make the isolation requirement structural rather than a convention.
- Rules a relational database would enforce (foreign keys, cascades) are our code's responsibility and need tests.
- New ways of querying the data need design work, because DynamoDB favours access patterns known up front.
  Ad-hoc reporting is harder than with SQL.
- Single-table design is less familiar to many developers. The key layout is documented here and in
  [`../PLAN.md`](../PLAN.md).
