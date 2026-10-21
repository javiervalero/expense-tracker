# 0002. DynamoDB single-table data model

- Status: Accepted (2026-10-09)
- Date: 2026-10-07
- Revised: 2026-10-09 (PR #1 review comments #2, #3, #8)

## Context

The app stores users, sessions, categories, expenses and receipt metadata. Each user's data is private
(brief: "users can only ever see and change their own data"). Following [ADR 0001](0001-serverless-aws-php-on-lambda-with-bref.md),
the database should be serverless: no always-on server, near-zero idle cost. The whole system must also run locally
without an AWS account.

## Decision

Use **Amazon DynamoDB** with a **single table**: on-demand billing, point-in-time recovery and TTL (on `expiresAt`)
enabled, and one **local secondary index, `LSI1`**, for listing expenses by date.

| Item | PK | SK | LSI1SK |
|---|---|---|---|
| User | `USER#<userId>` | `PROFILE` | — |
| Email lock | `EMAIL#<normalisedEmail>` | `EMAIL` | — |
| Category | `USER#<userId>` | `CAT#<categoryId>` | — |
| Expense | `USER#<userId>` | `EXP#<expenseId>` | `EXP#<yyyy-mm-dd>#<expenseId>` |
| Receipt | `USER#<userId>` | `RCPT#<expenseId>` | — |
| Session | `SESSION#<tokenHash>` | `SESSION` (expires by TTL) | — |
| Login throttle | `THROTTLE#<email or ip>` | `THROTTLE` (expires by TTL) | — |

`LSI1` uses the table's `PK` with `LSI1SK` as its sort key and projects all attributes. It is sparse: only expense
items have `LSI1SK`. All ids are random UUIDs and are never reused.

**Access patterns:**

| Need | Operation |
|---|---|
| Get / update / delete one expense | `GetItem` / `UpdateItem` / `DeleteItem` on `PK = USER#<me>`, `SK = EXP#<expenseId>` |
| Expenses in a date range, newest first | `Query LSI1`: `PK = USER#<me>`, `LSI1SK BETWEEN EXP#<from> AND EXP#<to>~`, descending, strongly consistent |
| Monthly summary | The same query for one month (all pages); totals by category in PHP |
| A user's categories | `Query`: `PK = USER#<me>`, `begins_with(SK, CAT#)` |
| An expense's receipt | `GetItem`: `SK = RCPT#<expenseId>` |

**Design rules:**
- **Isolation by key:** every read or write of user data uses `PK = USER#<authenticated user id>`, taken from the
  session and never from the request.
- **Unique emails:** the user item and its email lock are written in one transaction, guarded by a condition
  that the email lock doesn't already exist.
- **Date ranges** are key conditions on `LSI1SK`.
- **Changing an expense's date** is one `UpdateItem` that sets `date` and `LSI1SK` together, conditioned on
  `attribute_exists(PK)` (otherwise `404`). The primary key has no date in it, so the expense id, its URL, its receipt
  item and its S3 object key never change. DynamoDB updates the index atomically with the item.
- **Deleting an expense** removes the expense and receipt items in one `TransactWriteItems` (same partition), then
  deletes the receipt's S3 objects ([ADR 0006](0006-direct-to-s3-receipt-uploads-and-async-processing.md)).
- **Deleting a category** deletes only its `CAT#` item; expenses are not rewritten. Every read path (expense
  responses, filters, monthly summary) resolves `categoryId` against the user's categories, loaded with one query,
  and reports an id with no matching category as `null` (*Uncategorized*). Ids are never reused, so a leftover id
  cannot match a later category. When such an expense is next saved, the leftover id is replaced by `null`.
- **Filtering and pagination.** `GET /expenses` takes `limit` (default 50, max 100) and an opaque `cursor`, and returns
  `{ items, nextCursor }`. Category and text filters can't be key conditions, so the API queries `LSI1` for the date
  range in batches of 100 and filters in PHP (category through the category resolver, with `category=none` for
  Uncategorized; text as a case-insensitive substring of the description). It examines items in order and stops at
  the `limit`-th match, at the end of the range, or after examining 1,000 items in the request.
  - A page holds **at most** `limit` items. A short or empty page with a non-null `nextCursor` means "keep going";
    only `nextCursor: null` means there are no more results.
  - The cursor is base64url JSON of the last examined item's `SK` and `LSI1SK` plus a hash of the filters, so nothing
    is skipped or repeated. `PK` always comes from the session, so a cursor can't reach another user's data. A cursor
    that doesn't decode or was issued for different filters gets `400`.
  - There is no total count and no page numbers (that would mean reading the whole range); the UI uses "Load more".
    This deliberately departs from the usual `total/page/limit` pagination metadata.
- **Integrity rules in PHP**, each with tests: an expense's category must exist in the user's partition when the
  expense is saved (otherwise `422`); deleted categories resolve to Uncategorized on read; deleting an expense deletes
  its receipt.

**Local development:** the official `amazon/dynamodb-local` container, which supports local secondary indexes.

## Alternatives considered

- **RDS PostgreSQL.** Relational integrity and SQL reporting, but it is an always-on server (about $15+/month),
  needs a VPC, and Lambda then needs a VPC too. Rejected on the serverless preference.
- **Aurora Serverless v2 (PostgreSQL) with auto-pause.** Real PostgreSQL that pauses when idle, but the first
  request after a pause takes about 15 s, and it still needs a VPC.
- **Aurora DSQL.** Serverless and PostgreSQL-compatible with no VPC, but it supports only a subset of PostgreSQL
  (for example, no foreign keys), so local PostgreSQL and AWS would behave differently.
- **Date in the expense sort key, plus a GSI on `expenseId`** (earlier draft). A date change becomes a delete + put
  transaction, and lookups by id go through an eventually consistent index (a `GET` straight after `POST` can 404).
- **Date encoded in the expense id.** A date change would change the id, the URL and the receipt keys.
- **A GSI instead of `LSI1`.** It can be added later and has no per-user size limit, but it is only eventually
  consistent, so a just-saved expense can be missing from the list.
- **Rewriting expenses when a category is deleted** (synchronously, in a transaction, or as a background job).
  Unbounded writes, transactions are capped at 100 items, a partial failure leaves mixed data, and a background job
  would still need the read-time rule while it runs. **Refusing to delete a category in use** was rejected as worse
  for the user.
- **DynamoDB `Limit` with a `FilterExpression`.** `Limit` applies before filtering, so pages are often empty.
  **A GSI per category** costs an extra index and doesn't help text search. **OpenSearch Serverless** has a fixed
  minimum monthly cost.

## Consequences

- No VPC, no database credentials (access is by IAM), no idle cost, no connection limits from Lambda.
- Per-user partitions make the isolation requirement structural rather than a convention.
- Rules a relational database would enforce (foreign keys, cascades) are our code's responsibility and need tests.
- Expenses may hold ids of deleted categories by design. All reads must go through the shared category resolver,
  which has dedicated tests.
- `LSI1` must exist when the table is created. Adding or removing it later means a new table and a data copy.
- With an LSI, all of one user's items share a 10 GB limit (tens of millions of expenses), far beyond this app's needs.
- Each expense write also writes its index entry (a date change writes it twice), and expense attributes are stored
  twice. At proof-of-concept volume this is fractions of a cent per month.
- Filtered lists can return short or empty pages, so clients must rely on `nextCursor`, not page length. Items that
  are filtered out still cost read units; the 1,000-item budget bounds cost and latency per request.
- New ways of querying the data need design work, because DynamoDB favours access patterns known up front.
  Ad-hoc reporting is harder than with SQL.
- Single-table design is less familiar to many developers. The key layout is documented here and in
  [`../PLAN.md`](../PLAN.md).

## Implemented in Phase 1 (2026-10-15, PR #4)

- **One table definition:** `infra/dynamodb/table.json` (CreateTable fields: keys `PK`/`SK`, `LSI1` on `PK` +
  `LSI1SK` projecting all attributes, on-demand billing, plus `TimeToLiveAttribute: expiresAt`). The table name is
  not in it; it comes from `DYNAMODB_TABLE`. The local bootstrap creates the table from this file, a conformance test
  checks that the local table equals it, and Phase 7's Terraform reads the same file. There is no second copy to
  drift.
- **Access layer:** `App\Infrastructure\DynamoDb\ItemStore`, a thin typed adapter that later repositories use.
  Reads are always strongly consistent, as this ADR requires. Expressions always go through name and value maps, and
  expression strings are typed `literal-string`, so static analysis rejects one built from input. `query()` accepts
  only the table or `LSI1`. Errors map to `ConditionFailed`, `TransactionCancelled` or `StorageFailure`, which never
  carry the AWS message or item data.
- No keys, repositories or cursor yet: those arrive with their first features (Phases 2 and 3). The review notes
  carried to them are in [the Phase 1 plan](../plans/phase-1-backend-core.md) ("Notes from the WP9 reviews").

## Implemented in Phase 2 (2026-10-21, PR #5)

The first keys and repositories (decisions A15, A17, A19 in [the Phase 2 plan](../plans/phase-2-authentication.md)). Keys are built in one place,
`App\Auth\AuthKeys`.

| Item | `PK` | `SK` | Attributes |
|---|---|---|---|
| User | `USER#<uuid>` | `PROFILE` | `userId`, `email` (normalised), `passwordHash`, `createdAt` |
| Email lock | `EMAIL#<normalised email>` | `EMAIL` | `userId` |
| Session | `SESSION#<sha256 hex of the token>` | `SESSION` | `userId`, `createdAt`, `renewedAt`, `absoluteExpiresAt`, `expiresAt` (TTL) |
| Throttle bucket | `THROTTLE#<scope>#<key>` | `THROTTLE` | `attempts`, `expiresAt` (TTL) |

- **Register** writes the user and the email lock in one transaction, each conditional on `attribute_not_exists(PK)`,
  so one email can hold one account. The ids are random UUID v4.
- **Throttle scopes** (`LOGIN-EMAIL`, `LOGIN-IP`, `LOGIN-NET`, `REGISTER-IP`, `REGISTER-NET`) refine the
  `THROTTLE#<email or ip>` key of this ADR. The `LOGIN-EMAIL` key is the SHA-256 hex of the normalised email, so a
  mistyped email is never stored in clear.
- **A create that meets `ConditionFailed` is re-read before it is reported** (an SDK retry may find its own first
  attempt): a lock or session that holds our own new values is a success, anything else a real conflict.
- **Overload** (DynamoDB throttling, transaction conflicts, a transaction still in progress) becomes `503` with
  `Retry-After: 1`; every other storage failure stays a generic `500`.
