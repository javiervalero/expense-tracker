# Phase 3: Categories and expenses API. Implementation plan

| | |
|---|---|
| Status | **Approved by the owner, 2026-10-21** (gate G1; all ten section 10 decisions answered with the recommended defaults) |
| Phase | 3 (PLAN §12, essential track) |
| Branch | `feat/phase-3-categories-expenses`, from `main` (Phase 2 merged in PR #5, commit ded0db4) |
| Inputs | BRIEF; PLAN §5, §6, §12; ADR 0002 (Decision, "Implemented in Phase 1/2"), 0003, 0004 (incl. accepted risks), 0006, 0011, 0012 §4.1; [Phase 1 plan](phase-1-backend-core.md) section 6 (Phase 3 items); [Phase 2 plan](phase-2-authentication.md) sections 7.1–7.3; the Phase 2 code (`backend/src/Auth/**`, `Api/Routes.php`, `Http/Routing/*`, `Infrastructure/DynamoDb/*`, `ApiTestCase`, `contract/openapi.yaml`) |
| Drafted by | `ecc:planner` (opus/high). The main session still has to fact-check it against the code |
| AWS | **None.** Phase 3 makes no AWS calls, uses no credentials, deploys nothing and costs $0. Every DynamoDB call goes to DynamoDB Local. No S3 client is built. If any step ever seems to need AWS, the work stops and the owner is asked first. |

**Scope (PLAN §12, row 3):**
- `GET/POST /categories`, `PATCH/DELETE /categories/{id}`;
- `GET/POST /expenses`, `GET/PATCH/DELETE /expenses/{id}`;
- date, category and (owner decision 6) text filters;
- cursor pagination with the 1,000-item read budget;
- the shared category resolver;
- ownership rules;
- a first-class cross-user integration suite.

Contract first (ADR 0011): each operation goes into `openapi.yaml` in the same WP and the same commit as its route.

Not in scope: front-end pages (Phase 4), receipts (Phase 5), the monthly summary (Phase 6), category colours (L5). See section 7.

## 1. Goal and definition of done

**Goal:** a signed-in person can manage their own categories, and record, list, filter, edit and delete their own expenses, through the real API, locally. Another user's data can never be seen or changed, whatever ids or cursors are sent. Each guarantee has a test that would fail if it broke.

**Done means (each item can be demonstrated):**
- **The happy path, with curl** (in `docs/local-dev.md`, with a cookie jar and the CSRF headers):
  - create a category, then an expense in it;
  - list the expenses, filter them by date and by category, and follow `nextCursor`;
  - rename the category, change the expense's date and amount (with `version`), and delete both;
  - after the category is deleted, its expenses show `categoryId: null` and match `category=none`.
- **Isolation:** `CrossUserIsolationApiTest` runs two users against every operation:
  - every id-taking route answers **404** with a byte-identical body for the other user's ids, for random UUIDs and for malformed ids;
  - lists, filters and cursors never return the other user's items;
  - the other user's data is unchanged afterwards (same `version`);
  - `CrossUserCoverageTest` fails if a secured operation is added to the contract without a cross-user case.
- **Pagination:** with 1,050 seeded expenses and a filter matching none of them, `GET /expenses` returns an empty page with a non-null `nextCursor`, after examining at most 1,000 items. Following cursors under concurrent inserts and deletes never skips or repeats an item that existed throughout. A tampered, foreign-filter, oversize or undecodable cursor gets `400`.
- **Integrity:**
  - an expense saved with a category that does not exist (or is another user's) gets `422`;
  - a deleted category resolves to `null` on every read path;
  - saving an expense that holds a leftover id replaces it with `null`;
  - two `PATCH`es sent with the same `version` give exactly one `200` and one `409`;
  - two parallel creates of the same category name give exactly one `201` (storage-level race test).
- **The profile item is never returned:** every query on `PK = USER#<id>` uses `begins_with(SK, …)` or the `LSI1` range. An architecture test enforces this, and an integration test seeds a `PROFILE` item and checks that it never appears.
- **Contract:**
  - every new operation is in `openapi.yaml`, and `frontend/src/api/schema.gen.ts` is regenerated;
  - every integration request is contract-validated;
  - new contract checks: 404 on every operation with a path parameter, 401 on every secured operation, 409 on every `PATCH`, 400 on every non-health operation (B19).
- **`make test`** passes with line coverage ≥ 80% (target: near 100% on `backend/src`), and **`make lint`** passes.
- **CI:** the same 7 required checks are green; no job is added or renamed.
- **Docs and ADRs:** ADR 0002 and ADR 0011 get "Implemented in Phase 3" notes (clarifications only). `docs/local-dev.md` and `README.md` are updated.

## 2. Design decisions (recommended defaults)

"ADR impact" says whether a point stays inside an accepted ADR. **None of them is meant to change a decision.** If the owner reads a "clarification" as a change, it becomes **ADR 0013** before the WP that needs it (gate G2).

| # | Topic | Recommendation | ADR impact |
|---|---|---|---|
| B1 | Money | `amountCents`: a JSON **integer**, `1 ≤ amountCents ≤ 100_000_000` (1,000,000.00 in currency units), constant `Amount::MAX_CENTS`. Floats are refused with `422`, and so are `12.0` (PHP decodes it as a float), strings, booleans, zero and negatives. An integer beyond 64 bits arrives as a string (`JSON_BIGINT_AS_STRING`), so it is refused too. Stored as a DynamoDB Number and read back as an `int` (`wrap_numbers: false`); a stored value that is not an int in range is a corrupt item (`500`). The bound keeps any monthly sum far below JavaScript's 2^53. Refunds and negative amounts are out of scope. | PLAN §5 ("integer cents"); closes the Phase 1 carried item. **Owner decision 1** |
| B2 | Currency | One currency for the whole app (PLAN §5). **There is no currency field** in the API or in storage. The currency code is a front-end display setting decided in Phase 4. Multi-currency is out of scope (BRIEF). | Clarifies PLAN §5. Part of **owner decision 1** |
| B3 | Date | `date`: exactly `YYYY-MM-DD` (`\A\d{4}-\d{2}-\d{2}\z`) plus `checkdate`, between `ExpenseDate::MIN = 2000-01-01` and `MAX = 2099-12-31`. **Future dates are allowed:** the server runs in UTC and does not know the user's time zone, so a "not in the future" rule would refuse a valid local today. The date has no time and no zone; it is the user's calendar day. | As ADR 0002 (`LSI1SK = EXP#<yyyy-mm-dd>#<id>`). Part of **owner decision 7** |
| B4 | Shared text rules | New `App\Http\Input\PlainText`, used for every free-text field:<br>• trim leading and trailing Unicode whitespace (`\s`, `\p{Z}`);<br>• length counted in characters (`mb_strlen`);<br>• refuse control characters (`\p{Cc}`, the Phase 2 rule);<br>• **also refuse the bidi embedding, override and isolate characters** U+202A–U+202E and U+2066–U+2069, so a name or description cannot reorder what the UI shows;<br>• other format characters (ZWJ, ZWNJ) stay allowed, because emoji and some scripts need them.<br>Text is stored byte for byte, with no Unicode normalisation (`intl` is in neither image, Phase 2 A11). Comparison is case-insensitive through `mb_convert_case(…, MB_CASE_FOLD)` (mbstring; R2 checks both images). | — |
| B5 | Description | Required: 1–200 characters after trimming, B4 rules. | Part of **owner decision 7** |
| B6 | Category name and uniqueness | Required: 1–50 characters after trimming, B4 rules. **Unique per user, case-insensitively** (`Food` and `food` are the same name), enforced by a **lock item** in the user's partition: `PK = USER#<id>`, `SK = CATNAME#<sha256 hex of the case-folded name>`, attribute `categoryId`.<br>• **Create:** one `TransactWriteItems` that puts `CAT#<id>` and the lock, each conditional on `attribute_not_exists(PK)`.<br>• **Rename to a different folded name:** the transaction puts the new lock, deletes the old lock (`categoryId = :id`) and updates `CAT#` (version condition). A case-only rename keeps the same lock, so it is one `UpdateItem`; a transaction may not touch one item twice (R3).<br>• **Delete:** removes the category and its lock together.<br>A read-then-write check was rejected: two parallel creates would both pass. The lock costs one extra small item per category. `CATNAME#` never matches `begins_with(SK, 'CAT#')`, and a test pins that. | Adds one item kind in the user partition, the same pattern as ADR 0002's email lock. Recorded as a clarification in ADR 0002. **Owner decision 2**; ADR 0013 if read as a change |
| B7 | Default categories | **None.** Register stays untouched (no coupling from Auth to Categories), and a new user can record an expense right away as Uncategorized (`categoryId: null`). Phase 4 shows an empty state with "Add category". | — . **Owner decision 3** |
| B8 | Limits | **At most 100 categories per user** (`CategoryLimits::MAX_PER_USER`), checked by the create handler from the resolver's list: a soft cap. Parallel creates can overshoot by at most one request round, after which every create is refused, and the resolver handles more than 100 anyway (it pages). The cap bounds the resolver query that every expense read makes. **No cap on expenses:** the 1,000-item budget bounds each list request. Phase 6 must bound a very large month itself (section 7.2). | — . **Owner decision 4** |
| B9 | Ids | Category and expense ids are UUID v4 from `IdGenerator` (Phase 2 A17). **Route ids** go through the new `RouteId::uuidOrNotFound()`: anything other than a lower-case canonical v4 gets the generic `404` with **zero storage reads**. Key builders validate again. | Closes the Phase 1 S-M2 item (Route ids validated as UUIDs) |
| B10 | Ownership | Every key takes `PK = USER#<principal.userId>`, never a value from the request. Malformed ids, unknown ids and other users' ids all get the **same `404` body** ("The resource does not exist."), **never `403`**. `RequireSession` stays outermost, so an anonymous caller gets `401` before any id is looked at. A `categoryId` in a body that is not one of the caller's categories gets `422` (pointer `/categoryId`), the same as a non-existent one. A `category` filter with someone else's id simply matches nothing. | As ADR 0002 and PLAN §5 ("another user's resource always returns 404") |
| B11 | Update semantics | `PATCH` (PLAN §5), body `application/json` (JsonBody accepts only that; there is no `merge-patch+json`), merge semantics:<br>• a member that is present is changed;<br>• `categoryId: null` sets Uncategorized;<br>• `date`, `amountCents` and `description` cannot be `null`;<br>• `version` is required;<br>• at least one editable member is required, else `422`;<br>• unknown members give `422` (at most 5 reported, safe pointers: the CredentialsInput rules, extracted to `App\Http\Input\ObjectMembers`). | As PLAN §5 |
| B12 | Optimistic concurrency | Categories and expenses carry an integer `version` (1 on create, +1 on every update), returned in every response. `PATCH` **must** send the `version` it last read; a mismatch gives **`409`** ("changed since you loaded it; reload"). Every update is a read-modify-write: a consistent `GetItem`, then `UpdateItem … SET version = :next` with `ConditionExpression attribute_exists(PK) AND version = :expected`. No `ADD` is used. **Body field, not `If-Match`/ETag:** the CloudFront origin request policy (ADR 0012 §4.1) forwards only `Origin`, `X-Requested-With`, `Content-Type`, `Accept` and `CloudFront-Viewer-Address`, so `If-Match` would need an ADR 0012 change. **`DELETE` is unconditional**: deleting is the user's explicit intent, and the category delete retries once internally on a version race. | Clarifies ADR 0002's date-change rule (the condition gains `AND version = :expected`); closes the Phase 1 item "non-idempotent updates guarded by a version condition". **Owner decision 5** |
| B13 | False conflicts after an SDK retry | The Phase 2 A19 rule, extended. A `ConditionFailed` on a `PutItem` / `UpdateItem` is **re-read before it is reported**:<br>• expense create: the stored item equals ours, so success;<br>• update: the stored item has `version = expected + 1` and exactly the fields and `updatedAt` we wrote, so success; a missing item is `404`; anything else is `409`;<br>• category create: the lock holds our new id, so success. A lock whose category no longer exists is stale: it is removed conditionally (`categoryId = :stale`) and the create is retried once.<br>`TransactWriteItems` retries are already idempotent: the SDK reuses `ClientRequestToken` (Phase 2 R4). | Extends Phase 2 A19 |
| B14 | Deleting a category | Read `CAT#<id>` (`404` if it is missing), then one transaction: delete `CAT#` (`version = :v`) and its lock (`categoryId = :id`). A version race means a re-read and one retry. **Expenses are not rewritten:** they resolve to Uncategorized (ADR 0002). | ADR 0002 says "deletes only its `CAT#` item"; the lock goes with it, which keeps the intent (no expense rewrite). Clarification, recorded in the ADR note |
| B15 | Deleting an expense; room for receipts | `ExpenseRepository::delete()` is **already** ADR 0002's transaction: delete `EXP#<id>` (`attribute_exists(PK)`, otherwise `404`) plus an unconditional delete of `RCPT#<id>`, which is a no-op in Phase 3. `ExpenseKeys::receipt()` exists, reserved. Phase 5 adds only the S3 clean-up after the transaction (record first, then files, ADR 0006), and decides whether to read `RCPT#` first for its `objectKey`. | As ADR 0002 and ADR 0006 |
| B16 | Category resolver | `App\Categories\CategoryResolver`, built lazily once per request from one paged query (`PK = USER#<me>`, `begins_with(SK, 'CAT#')`):<br>• `resolve(?string $id): ?string` (a missing id gives `null`);<br>• `has(string $id): bool`;<br>• `all()`, sorted by folded name, then id.<br>**Every** read path (expense responses, the list filter, and Phase 6) goes through it, so there is one rule with dedicated tests. Writes check `has()`, which gives `422`. A race with a concurrent category delete leaves a leftover id, which the read rule already handles; a transaction `ConditionCheck` would cost double for no visible gain. | As ADR 0002 |
| B17 | List filters | All optional:<br>• `from` / `to` (B3 format; defaults `MIN` / `MAX`, so no date filter means "all, newest first"; `from > to` gives `400`; no maximum range is needed, because the budget bounds cost);<br>• `category=<uuid>` or `none` (Uncategorized, leftover ids included). A uuid that is not one of the caller's categories returns `{items: [], nextCursor: null}` **without querying `LSI1`**;<br>• `q`: 1–100 characters after B4 trimming; case-folded substring of the description; empty means absent;<br>• `limit`: 1–100, default 50;<br>• `cursor`.<br>**Unknown parameters give `400`** on `GET /expenses` (a typo like `catgory=` must not silently list everything). Other operations ignore query parameters, as today. | As ADR 0002. `q`: **owner decision 6** |
| B18 | Pagination engine and cursor | `ExpenseLister`:<br>• queries `LSI1` descending, strongly consistent, `LSI1SK BETWEEN EXP#<from> AND EXP#<to>~`;<br>• batch `Limit = min(100, budget left, PHP filters active ? 100 : limit − matched)`;<br>• examines items in order and stops at the `limit`-th match, at the end of the range (`LastEvaluatedKey` null and the stop item is the last of the page, which gives `nextCursor: null`), or after **1,000 examined items**.<br>**The cursor** is base64url (no padding) JSON `{"v":1,"sk":"EXP#<uuid>","lsi":"EXP#<date>#<uuid>","f":"<filter hash>"}` of the **last examined** item. The hash is the SHA-256 hex of the canonical filters (`from|to|category|q`, after defaults and normalisation; `limit` excluded, so page size may change).<br>**It is validated before any DynamoDB call:**<br>• at most 512 characters and the strict alphabet;<br>• JSON depth 2, exactly those keys, as strings, `v === 1`;<br>• `sk` and `lsi` match their patterns with the **same** uuid;<br>• the date is inside `[from, to]`;<br>• the hash matches.<br>Any failure gives `400` "The cursor is not valid for these filters.". `PK` comes from the session, so another user's cursor is only a start position inside the caller's own partition. No HMAC is needed: tampering can only move within one's own data. | As ADR 0002; closes the Phase 1 items (cursor 400, `limit` ≤ 100, 1,000 cap). The batch sizing is a clarification |
| B19 | Query errors | Invalid `from`, `to`, `category`, `q`, `limit` or `cursor`, or an unknown parameter, gives **`400`** (`InvalidQuery`), with a detail that names the parameter, never its value. `422` stays for body fields: `ValidationProblemDetails` pointers point into the body. **The contract documents `400` on every non-health operation:** `RequestFactory` already answers a malformed query string with a deferred `400` on any route, which `/auth/me` and others do not document today. | Additive contract change (ADR 0011) |
| B20 | Response shapes | No envelope: the resource itself, as in Phase 2; errors are problem+json.<br>• `Category`: `{id, name, version, createdAt, updatedAt}`.<br>• `Expense`: `{id, date, amountCents, description, categoryId: uuid\|null (resolved), version, createdAt, updatedAt}`.<br>• `GET /categories`: `{items}` (at most about 100, no pagination).<br>• `GET /expenses`: `{items, nextCursor}`.<br>• `201` carries `Location: /api/v1/<collection>/<id>`.<br>Timestamps are ISO 8601 UTC (`…Z`), like the user's `createdAt`. Items are mapped field by field: `PK`, `SK`, `LSI1SK` and `nameKey` never leave the server. All schemas use `additionalProperties: false`. | ADR 0011 |
| B21 | Order within a day | Newest date first. Within one date the order is by expense id: stable, but arbitrary, because ids are random v4. A creation-ordered tie-break (UUID v7 ids) would change ADR 0002's "random UUIDs" and Phase 2's `Uuid::isValid` (v4 only), so it is **not** proposed. If the owner wants it, it needs ADR 0013. | None (flagged). **Owner decision 8** |
| B22 | Write throttling | **None in Phase 3:**<br>• every endpoint needs a session (registration is throttled to 5 per hour per address);<br>• each write is O(1) items;<br>• lists are bounded by the budget, and categories by B8.<br>Floods are left to API Gateway throttling and the optional WAF (Phase 7), the stance ADR 0004 already accepts. Residual: a signed-in user can write many expenses (storage cost only, their own partition). | As ADR 0004 (accepted risks). **Owner decision 9** |
| B23 | Logging | No new info lines: the request line already carries `userId` and the path (ids only). **Names, descriptions, `q`, cursors and amounts are never logged**, and no raw body is logged (Phase 2 WP2 carried note). Exception messages from the new code carry no item data. Note: local nginx's `combined` access log includes query strings, so `q` and cursors appear there locally; Phase 7's API Gateway access-log format must exclude them (carried). | — |
| B24 | Later phases plug in | **Phase 5:** B15, plus `/expenses/{id}/receipt…` routes (another segment count, so no router shadowing); `Expense` gains an optional `receipt` member (additive). **Phase 6:** reuses `ExpenseRangeReader` (the batch iterator under `ExpenseLister`, with no page budget) and `CategoryResolver` for one month. **L5:** category `colour` as an optional member (additive), and the `q` UI if it is not built in Phase 4. | — |

**Endpoints** (every one has `security: [sessionCookie]`, and every one also documents 400, 403, 405, 500 and 503; state-changing ones declare `X-Requested-With`):

| Operation | Success | Other documented statuses |
|---|---|---|
| `GET /categories` | 200 `{items: Category[]}` | 401 |
| `POST /categories` `{name}` | 201 `Category` + `Location` | 401, 409 (name taken, or limit reached), 413, 415, 422 |
| `PATCH /categories/{id}` `{name, version}` | 200 `Category` | 401, 404, 409 (name taken, or stale version), 413, 415, 422 |
| `DELETE /categories/{id}` | 204 | 401, 404 |
| `GET /expenses?from&to&category&q&limit&cursor` | 200 `{items, nextCursor}` | 401 |
| `POST /expenses` `{date, amountCents, description, categoryId?}` | 201 `Expense` + `Location` | 401, 413, 415, 422 |
| `GET /expenses/{id}` | 200 `Expense` | 401, 404 |
| `PATCH /expenses/{id}` `{version, date?, amountCents?, description?, categoryId?}` | 200 `Expense` | 401, 404, 409, 413, 415, 422 |
| `DELETE /expenses/{id}` | 204 | 401, 404 |

**Order inside a handler:** session guard (401) → route id (404, no reads) → body (415/413/400/422) → read (404) → category check (422) → conditional write (409). A second `DELETE` of the same id gives `404`.

**Items** (all in `PK = USER#<userId>`; ISO 8601 UTC strings; Numbers for `amountCents` and `version`):
- Category `SK = CAT#<id>`: `categoryId`, `name` (trimmed, as entered), `nameKey`, `version`, `createdAt`, `updatedAt`.
- Name lock `SK = CATNAME#<nameKey>`: `categoryId`.
- Expense `SK = EXP#<id>`, `LSI1SK = EXP#<date>#<id>`: `expenseId`, `date`, `amountCents`, `description`, `categoryId` (the attribute is **absent** when Uncategorized; an update `REMOVE`s it), `version`, `createdAt`, `updatedAt`.

A missing or wrongly typed attribute is `StorageFailure::CORRUPT_ITEM`, which gives `500` and fails closed, as in Phase 2.

## 3. Work packages

There is **one commit per WP**. Contract and code go in together, because `RoutesMatchContractTest` fails on a contract-only commit (owner decision 2026-10-20). Tests are written first; RED is observed and noted in the WP review notes. Every WP is S or S–M.

**Order:** step 0 → building blocks (WP2–WP3) → storage (WP4–WP7) → endpoints (WP8–WP11) → isolation suite (WP12) → docs (WP13). Everything runs on one branch, one WP after another.

**Where commands run:** unchanged. PHP runs only through make targets, in the pinned PHP 8.5 tools container; Node tools run in the pinned node container; temporary files go in `build/tmp/`; CI calls the same make targets.

**Accepted ADRs are not edited to change decisions.** A real change needs ADR 0013 (gate G2). Clarifications go in the "Implemented in Phase 3" notes written in WP13.

### Shared Docker host rule (applies to every WP marked "Docker: yes")
The Docker host is shared with other projects. An agent may only:
- use the repo's make targets;
- use `docker compose -p expense-tracker …` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`);
- use `docker run --rm` for containers it starts itself.

It may remove **only** containers, networks or volumes that it created in this session, by name. It must **never** run `docker rm $(docker ps -aq)`, any `docker … prune`, `docker compose down -v`, or `docker volume rm` on a volume it did not create. If a cleanup seems to need any of these, it stops and asks the owner.

### Endpoint-security rule (applies to every WP, added 2026-10-19)
The owner's machine runs company endpoint security. An agent must **never** download, vendor, generate or run anything such tools commonly flag: password lists, wordlists or leaked-credential dumps; exploit, payload or fuzzing collections, web shells, the EICAR string or malware samples; offensive tools (nmap, sqlmap, hydra, hashcat, john, metasploit, nikto, gobuster and the like) or any port or network scan, localhost included; unsigned binaries or curl-pipe-to-shell installers. Security tests use invented strings. If a step plausibly needs any of these, the agent stops and the owner decides. (A SecLists password list downloaded for WP6 set off company-wide alerts; see section 10.)

*(The "WP6" and "section 10" in the rule refer to the [Phase 2 plan](phase-2-authentication.md).)* For Phase 3 this means: the injection and text tests (bidi characters, NUL, `#` inside ids, tampered cursors) use a few hand-written strings in the test files, never a downloaded corpus.

### Model routing summary (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium, one brief per WP (file paths plus the exact question; kill only containers you started, **by name**).
- **Review:** one language reviewer per diff: `ecc:php-reviewer`, sonnet/medium.
- **Security:** `ecc:security-reviewer`, **opus/high on WP2–WP12**. Every one touches user input, ownership or DB writes. **Round 2** (fix hunks only) also runs at **opus/high**.
- **Makefile / CI:** reviewed inline by the main session under about 30 lines; larger diffs go to `ecc:code-reviewer` (sonnet/medium).
- **Docs:** `ecc:doc-updater` (haiku/low), always fact-checked by the main session (WP13).
- **Test runs and output summaries:** `general-purpose`, haiku/low.
- **Build or lint failures:** `ecc:build-error-resolver`, sonnet/low on the first attempt, then opus/high.

### Step 0: Research & Reuse (before WP2), fact-checked by the main session
`ecc:docs-lookup` cannot be used here, because it needs Context7 (missing since Phase 1).
- R2 and R4 are answered from the code and the pinned images (`docker run --rm`), by the main session or `general-purpose` haiku/low.
- R1 and R3 are answered from the AWS DynamoDB Developer Guide / API Reference (`general-purpose` sonnet/medium with WebFetch, every claim with a URL), each confirmed by a RED integration test in the WP that needs it.

Each question, and the WP that needs the answer:
- **R1 (WP6, WP7):** a `Query` on `LSI1` with `ExclusiveStartKey` = `{PK, SK, LSI1SK}` of an item that was **deleted, or whose date changed**, between pages. Does it continue from that position (AWS and DynamoDB Local 3.3.1)? Which attributes does an LSI start key need? If it is refused, the cursor design is at stake (G2).
- **R2 (WP2):** `mb_convert_case(MB_CASE_FOLD)`, `mb_strlen` and PCRE `\p{Cc}` / `\p{Z}` in `php:8.5-fpm-alpine` and `bref/php-85:3`. Do both images give identical results for a few invented strings (`Straße`, `İ`, `ǅ`, emoji with ZWJ)?
- **R3 (WP4, WP6):** `TransactWriteItems` on DynamoDB Local:
  - an unconditional `Delete` of a missing item succeeds;
  - `CancellationReasons` keep the order of the operations;
  - two operations on the same key give `ValidationException` (the reason case-only renames use one `UpdateItem`).
- **R4 (WP7, WP11):** what reaches the handler on Lambda for `q` with `+`, `%2B`, `%20` and non-ASCII text, and for repeated `category` (from `QueryStringBrefTest` and Bref's source). Duplicate parameters collapse on AWS (last wins), which the handler must tolerate.

### Step 0 results (2026-10-21)

| # | Answer | Consequence |
|---|---|---|
| R1 | Per the [Query API reference](https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_Query.html), `ExclusiveStartKey` is the start position. On DynamoDB Local 3.3.1 (throwaway table, deleted afterwards), an LSI start key must hold exactly `PK`, `SK` and `LSI1SK` (otherwise `ValidationException`). The item need not exist: after deleting the start item, or changing its `LSI1SK`, the query resumes from the old position with nothing skipped or repeated (a re-dated item moves to its new position). A `PK` that does not match the key condition is refused. The docs do not state the deleted-item case, so WP6/WP7 pin it with tests. [LSI.html](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/LSI.html): items with an **equal** index sort key come back in no defined order. | ADR 0002's cursor works; **no G2**. Ties cannot happen: `LSI1SK = EXP#<date>#<expenseId>` is unique, and B18 checks that `sk` and `lsi` carry the same id. Cursors are only ever built from a real examined item. |
| R2 | `php:8.5-fpm-alpine` (local) and `bref/php-85:3` (Lambda): PHP 8.5.11, PCRE 10.44, mbstring on, intl off; byte-identical output for the probe strings. `Straße` and `STRASSE` both fold to `strasse`; `İ` folds to `i̇` (`69cc87`), `ǅ` to `ǆ`; the ZWJ emoji counts as 3 characters; `\p{Z}` matches U+00A0 and U+3000; `\p{Cc}` matches U+0085; the bidi class matches U+202E. | B4 holds as written. Trimming strips `\p{Z}` only at the edges (an inner NBSP stays). |
| R3 | [TransactWriteItems](https://docs.aws.amazon.com/amazondynamodb/latest/APIReference/API_TransactWriteItems.html) on DynamoDB Local: an unconditional `Delete` of a missing item succeeds; `CancellationReasons` follow the order of the operations (`[None, ConditionalCheckFailed, ConditionalCheckFailed, None]`); two operations on one item give `ValidationException` locally (the docs also list it as a cancellation reason, so AWS may answer `TransactionCanceledException`). | B6/B14/B15 as written. The case-only rename stays one `UpdateItem`, so the same-item case never arises. |
| R4 | Locally `QueryString` decodes `+` as space and percent-escapes (`q=a+b` → `a b`, `%2B` → `+`, `caf%C3%A9` → `café`) and refuses a repeated name with `400`. On Lambda, Bref re-encodes the query string RFC 3986 first; the values reaching the handler are the same. A repeated name becomes `name[0]…`, which `QueryString` also refuses (`400`, other detail). Pinned by `QueryStringBrefTest`. | B17/B19 as written; handlers validate their own parameters. |

### Part A: Building blocks

#### WP1: Plan (S)
- **Files:** this plan (`docs/plans/phase-3-categories-expenses.md`); PLAN §12 row 3 links it.
- **Agent:** the main session, from the planner's output. **Commit:** `docs: add phase 3 implementation plan`.

#### WP2: Input values and shared text rules (S–M)
- **Files:**
  - `backend/src/Http/Input/PlainText.php` (B4) and enum `TextViolation` (`Empty`, `TooLong`, `ControlCharacter`, `BidiControl`).
  - `backend/src/Http/Input/ObjectMembers.php`: unknown-member errors (at most 5, safe pointers), extracted from `CredentialsInput`. `CredentialsInput` uses it **behaviour-preserving**: its tests stay unchanged and green.
  - `backend/src/Expenses/Amount.php` (B1) with enum `AmountViolation`.
  - `backend/src/Expenses/ExpenseDate.php` (B3), with `MIN`/`MAX` and the LSI bounds helpers.
  - `backend/src/Expenses/Description.php` (B5).
  - `backend/src/Categories/CategoryName.php` (B6): `value()`, `key()` (SHA-256 hex of the folded name).
  - `backend/src/Expenses/Http/ExpenseFieldMessages.php` and `backend/src/Categories/Http/CategoryFieldMessages.php`: exhaustive `match`, texts built from constants, never echoing a value (the `FieldMessages` pattern).
- **Tests first (RED):**
  - `Unit/Http/Input/PlainTextTest.php`:
    - trimming of spaces, tabs, NBSP and U+3000;
    - 200 and 201 characters, with multibyte characters counting as one;
    - `\x00`, `\x1F`, `\x7F` and U+0085 refused;
    - U+202E and U+2067 refused;
    - a ZWJ emoji sequence and `#` allowed.
  - `AmountTest`: `1`, `100000000` pass; `0`, `-1`, `100000001`, `12.0`, `12.5`, `"12"`, `true`, `null` and `"99999999999999999999"` (bigint-as-string) are refused, each with its own violation.
  - `ExpenseDateTest`: `2024-02-29` passes, `2023-02-29` fails; `2026-1-05`, `2026-01-05T00:00`, ` 2026-01-05`, `1999-12-31` and `2100-01-01` fail; the bounds pass.
  - `CategoryNameTest`: `"  Food "` gives `Food`; `Food`, `FOOD` and `food` share one `key()`; `Straße` and `STRASSE` give the same key (case folding, as R2 confirms); 50/51 characters.
  - `ObjectMembersTest`, plus the existing `CredentialsInputTest` unchanged.
- **Acceptance:** green; 100% line coverage of the new classes; PHPStan max clean.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)** (input boundary); round 2 at opus/high.
- **Commit:** `feat: add amount, date, description and category name value objects with shared text rules`.

#### WP3: Keys, route ids and the user-partition query guard (S)
- **Files:**
  - `AuthKeys::userPartition(string $userId): string`: the one place `USER#` is built (validated UUID); `AuthKeys::user()` uses it.
  - `backend/src/Categories/CategoryKeys.php`: `PREFIX = 'CAT#'`, `NAME_LOCK_PREFIX = 'CATNAME#'`, `category()`, `nameLock()`.
  - `backend/src/Expenses/ExpenseKeys.php`: `PREFIX = 'EXP#'`, `expense()`, `indexKey(ExpenseDate, id)`, `rangeStart()`, `rangeEnd()` (`~`), `receipt()` (`RCPT#`, reserved for Phase 5).
  - `backend/src/Http/Routing/RouteId.php`: `uuidOrNotFound(Request, string $name = 'id')`, which throws the generic `404` `HttpProblem`.
  - `Principal::of(Request): self`: a `LogicException` when the attribute is missing. A misconfigured guard therefore gives `500`, never anonymous access.
  - `backend/tests/Architecture/UserPartitionQueryTest.php` (`TokenScanner`, with fixtures):
    - every `->query(` call in `src/` has a key-condition literal containing `begins_with(` or `BETWEEN`;
    - the literal `'USER#'` appears only in `AuthKeys.php`.
- **Tests first (RED):**
  - `Unit/Categories/CategoryKeysTest.php`, `Unit/Expenses/ExpenseKeysTest.php`:
    - an upper-case UUID, a v1 UUID, `a#b` and empty are refused;
    - `CATNAME#…` does not start with `CAT#`;
    - `rangeEnd` sorts after every `indexKey` of that date.
  - `RouteIdTest`: a malformed id throws 404, and a counting fake store shows zero calls.
  - `PrincipalTest`.
  - The architecture test flags a leaky fixture with its file and line.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add category and expense keys, route id validation and the user-partition query guard`.
- **Open the PR as a draft after this WP** (owner decision 10).

### Part B: Storage (DynamoDB Local)

#### WP4: Categories with a name lock (S–M)
- **Files:** `backend/src/Categories/{Category,CategoryRepository,CategoryNameTaken,CategoryNotFound}.php`; `backend/src/Infrastructure/DynamoDb/StaleVersion.php`.
- **`CategoryRepository`:**
  - `listAll(userId)`: paged `begins_with(SK, :cat)`;
  - `find()`;
  - `create()`: B6 transaction, items built field by field, with the B13 re-read and stale-lock repair;
  - `rename(current, CategoryName, expectedVersion, now)`: B6/B12, cancellation reasons mapped by position: lock gives `CategoryNameTaken`; category gives a re-read, then `CategoryNotFound`, `StaleVersion` or our own retry;
  - `delete(current)`: B14.
- **Overload:** cancellations whose reasons are only throttling or `TransactionConflict` are rethrown, so WP3's Phase 2 middleware answers `503`.
- **Tests first (RED):**
  - `Integration/Categories/CategoryRepositoryTest.php` on `TemporaryTable`:
    - create, list and find;
    - `Food` then `food` gives `CategoryNameTaken`, and no second item exists;
    - calling `create` twice with the same `Category` succeeds (retry);
    - a stale lock (seeded without its category) is repaired;
    - a case-only rename keeps one lock;
    - a rename to a taken name changes nothing;
    - a rename with an old version gives `StaleVersion`;
    - delete removes both items;
    - a seeded `PROFILE` and an `EXP#` item never appear in `listAll`;
    - a corrupt item gives a storage error.
  - Unit tests with simulated SDK answers for the cancellation-reason mapping (the `SessionRepository` pattern).
  - **Race:**
    - deterministic: an SDK middleware runs creator B's whole `create()` inside creator A's transaction, and exactly one succeeds;
    - `CategoryNameLockConcurrencyTest`: 10 `proc_open` children (the `tests/Support/bin/throttle-hit.php` pattern) create one name, and exactly one succeeds;
    - both are shown to fail against a temporary read-then-write double, which is then removed.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add category repository with a per-user case-insensitive name lock`.

#### WP5: Category resolver (S)
- **Files:** `backend/src/Categories/CategoryResolver.php` (B16; lazy, at most one query per request).
- **Tests first (RED):**
  - `Unit/Categories/CategoryResolverTest.php` (fake repository):
    - a known id resolves to itself; an unknown, deleted or other user's id gives `null`;
    - `null` gives `null`;
    - the repository is called once across many resolves;
    - `all()` is sorted by folded name, then id.
  - `Integration/Categories/CategoryResolverTest.php`:
    - user B's categories are invisible to A's resolver;
    - 120 categories, beyond the soft cap, all resolve (paging);
    - after a delete, a new resolver gives `null`.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**, which may be batched with WP4's round 2.
- **Commit:** `feat: add the shared category resolver`.

#### WP6: Expenses (S–M)
- **Files:** `backend/src/Expenses/{Expense,ExpenseRepository,ExpenseNotFound}.php`.
- **`ExpenseRepository`:**
  - `create()`: `PutItem`, `attribute_not_exists(PK)`, field by field, B13 re-read;
  - `find()`;
  - `update(updated, expectedVersion)`: B12, with `date` and `LSI1SK` set together; two literal update expressions, `SET …` and `SET … REMOVE #categoryId`, chosen by `match`;
  - `delete()`: B15 transaction with `RCPT#`;
  - `pageByDate(userId, from, to, ?startKey, batchLimit): ExpenseBatch` (items plus the last evaluated key), used by WP7.
- **Tests first (RED):** `Integration/Expenses/ExpenseRepositoryTest.php`:
  - create, find, update and delete;
  - a date change moves the item in the `LSI1` range query, and the id stays the same;
  - setting `categoryId` to null removes the attribute;
  - an update with an old version gives `StaleVersion`;
  - an update of a missing id gives `ExpenseNotFound`;
  - **replaying the same `update` succeeds** (B13);
  - delete of a missing id gives `ExpenseNotFound`;
  - delete also removes a seeded `RCPT#` item, and leaves a seeded `RCPT#` of another expense alone;
  - `amountCents` is stored as a Number and read back as an `int`;
  - corrupt items (string amount, bad date, `LSI1SK` disagreeing with `date`) give a storage error;
  - **R1:** pinned by a test that deletes, and one that re-dates, the start-key item between two `pageByDate` calls.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add expense repository with versioned updates and a receipt-ready delete`.

#### WP7: Filters, cursor and the read budget (S–M), security-critical
- **Files:** `backend/src/Expenses/Listing/`:
  - `ExpenseFilter.php`: `fromQuery(array $query, CategoryResolver)`, which validates B17 and B19 (400 details name the parameter), `canonical()` and `hash()`;
  - `CategoryFilter.php`: `id` or `none`;
  - `ListCursor.php`: B18 encode/decode/validate;
  - `ExpenseRangeReader.php`: the batch iterator, the Phase 6 seam;
  - `ExpenseLister.php`: `LIMIT_DEFAULT = 50`, `LIMIT_MAX = 100`, `BATCH = 100`, `EXAMINE_BUDGET = 1000`;
  - `ExpensePage.php`.
- **Tests first (RED):**
  - `Unit/Expenses/Listing/ListCursorTest.php`:
    - round trip;
    - refused: padding, `+` or `/`, 513 characters, non-JSON, a JSON list, an extra key, `v: 2`, `sk` and `lsi` with different uuids, `sk` = `CAT#…` or `PROFILE`, a date outside `[from, to]`, a foreign hash, and a hash for different `q` casing after normalisation;
    - each refusal is a `400` with zero store calls.
  - `ExpenseFilterTest`:
    - defaults;
    - `from > to`;
    - `category=none`, an unknown uuid, `NONE` and an upper-case uuid;
    - `q` of 101 characters, `q` with U+202E;
    - `limit` values `0`, `101`, `1e2`, `050` and `-1`;
    - an unknown parameter;
    - equal canonical forms hash equally.
  - `ExpenseListerTest` (fake reader):
    - stops at `limit` matches;
    - stops after 1,000 examined, with a cursor at the 1,000th;
    - end of range gives `nextCursor: null`;
    - without PHP filters the batch size follows `limit`;
    - an unknown-category filter does no reads.
  - `Integration/Expenses/ExpenseListingTest.php` on `TemporaryTable`:
    - 1,050 seeded items with a non-matching `q` give an empty page, a non-null cursor and ≤ 1,000 examined (counted through an SDK middleware);
    - walking every page with `limit=7` under interleaved inserts and deletes returns each stable item exactly once, newest first;
    - a just-created item is in the very next list (strong consistency);
    - `category=none` includes leftover ids;
    - a seeded `PROFILE` and `CAT#` items never appear.
- **Acceptance:** 100% line coverage of `Listing/`.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add expense filters, opaque cursor and the 1,000-item read budget`.

### Part C: Endpoints (contract and code in one commit each)

#### WP8: Category endpoints (S–M)
- **Contract** (shared pieces arrive with their first user):
  - parameter `ResourceId` (path, `format: uuid`);
  - header `Location`;
  - responses `ResourceNotFound` (404), `EditConflict` (409, covering name taken, stale version and limit; the `detail` tells which), `InvalidQuery` (400);
  - schemas `Category`, `CategoryList`, `NewCategory`, `CategoryRename`;
  - the four operations (endpoint table), `security: [sessionCookie]`, `X-Requested-With` on state-changing ones;
  - `400` added to the existing auth operations (B19);
  - `info.version` 0.8.0; `make contract-gen`.
- **Contract tests:**
  - `EveryOperationDocumentsErrorsTest` gains: 404 on every operation with a path parameter, 401 on every secured operation, 409 on every `PATCH`, 400 on every non-health operation;
  - `ProtectedRoutesMatchContractTest` covers the new routes unchanged.
- **Files:**
  - `backend/src/Categories/Http/`: `CategoryInput.php` (NewCategory, CategoryRename shapes via `ObjectMembers`), `CategoryResponse.php`, `ListCategoriesHandler.php`, `CreateCategoryHandler.php` (B8 cap from the resolver, then `create`), `RenameCategoryHandler.php`, `DeleteCategoryHandler.php`;
  - `Routes.php`: four lazy routes, each `RequireSession(...)` outermost, factories not branching.
- **Tests first (RED):** first add a route without the guard and watch `ProtectedRoutesMatchContractTest` fail. Then:
  - unit tests per handler (fakes): the order of checks, a malformed id with zero reads, `TransactionCancelled([ThrottlingError, None])` reaching the middleware as `503`;
  - `Integration/Categories/CategoriesApiTest.php`, all through `request()`:
    - 201 with `Location`;
    - `food` after `Food` gives 409;
    - the 101st category gives 409;
    - rename with a stale version gives 409;
    - delete gives 204, and a second delete gives 404;
    - `{"name": 5}` gives 422 at `/name`;
    - `text/plain` gives 415;
    - no `X-Requested-With` gives 403;
    - no cookie gives 401.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add category endpoints`.

#### WP9: `POST /expenses` and `GET /expenses/{id}` (S–M)
- **Contract:** schemas `Expense` and `NewExpense` (`amountCents` `type: integer`, `minimum: 1`, `maximum: 100000000`; `date` `format: date` plus a pattern; `categoryId` `oneOf: [uuid, null]`); the two operations.
- **Files:** `backend/src/Expenses/Http/{NewExpenseInput,ExpenseResponse,CreateExpenseHandler,GetExpenseHandler}.php`; `Routes.php`.
- **Tests first (RED):**
  - unit tests: field errors for every B1/B3/B5 case, with pointers; a category the resolver does not know gives 422 `/categoryId` and no write; the response's `categoryId` goes through the resolver.
  - `Integration/Expenses/ExpensesApiTest.php` (create and read):
    - 201 with `Location`, then GET gives 200 and an equal body;
    - `amountCents: 12.5` gives 422;
    - `amountCents: 1e2` gives 422 (a float);
    - a missing description gives 422;
    - a category deleted after the create reads back as `null`;
    - an unknown id and a malformed id give identical 404 bodies.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add post /expenses and get /expenses/{id}`.

#### WP10: `PATCH` and `DELETE /expenses/{id}` (S–M)
- **Contract:** schema `ExpensePatch` (`version` required, `minProperties: 2`); the two operations.
- **Files:** `backend/src/Expenses/Http/{ExpensePatchInput,UpdateExpenseHandler,DeleteExpenseHandler}.php`; `Routes.php`.
- **Behaviour:**
  - the merged state is validated as a whole;
  - a stored leftover `categoryId` is replaced by `null` when the patch does not set one (ADR 0002);
  - a `categoryId` that is set must pass `has()`.
- **Tests first (RED):**
  - unit tests: `{}` and `{version}` alone give 422; `description: null` gives 422; the leftover-id rule; `StaleVersion` gives 409; `ExpenseNotFound` gives 404.
  - integration:
    - a date change shows up in a `from`/`to` list;
    - **two PATCHes with the same version through `requestConcurrently` give exactly one 200 and one 409**, and the stored version is +1;
    - delete gives 204, then GET gives 404 and a second delete gives 404.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high. The checklist includes Phase 2 rule A8: no GET handler changes state.
- **Commit:** `feat: add patch and delete /expenses/{id} with optimistic concurrency`.

#### WP11: `GET /expenses` (S–M), security-critical
- **Contract:**
  - query parameters `from`, `to` (`format: date`), `category` (`oneOf: [uuid, const none]`), `q` (`maxLength: 100`), `limit` (1–100, default 50), `cursor` (`maxLength: 512`);
  - schema `ExpensePage` (`nextCursor: string|null`);
  - the description states "a short or empty page with a non-null `nextCursor` means keep going".
- **Files:** `backend/src/Expenses/Http/ListExpensesHandler.php`; `Routes.php`.
- **Tests first (RED):** unit (handler wiring, 400 mapping). `Integration/Expenses/ExpenseListApiTest.php`:
  - newest first;
  - `from`/`to` inclusive bounds;
  - `category=<id>`, `category=none` and `q` (case-insensitive, `ß` vs `SS` per R2);
  - walking with `limit=2` reaches `nextCursor: null`;
  - a cursor replayed with another `q` gives 400;
  - a hand-edited cursor gives 400;
  - an unknown parameter gives 400;
  - `limit=101` gives 400.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add get /expenses with filters and cursor pagination`.

### Part D: Isolation, end to end and wrap-up

#### WP12: Cross-user isolation suite and log hygiene (S–M), first-class
- **Files:**
  - `backend/tests/Integration/Isolation/CrossUserIsolationApiTest.php`. Users A and B register through the API; A creates 2 categories and 5 expenses. Then:
    - B: `GET`, `PATCH` and `DELETE /expenses/{A's id}` and `PATCH`/`DELETE /categories/{A's id}` give **404**, byte-identical (status, headers, body) to a random UUID's 404 and to a malformed id's 404;
    - B: `POST /expenses` and `PATCH /expenses/{B's id}` with A's `categoryId` give **422** `/categoryId`;
    - B's `GET /categories`, `GET /expenses` (no filter, `category=<A's>`, `category=none`, and a `q` matching A's description) contain **none** of A's ids;
    - B replays **A's cursor**: 200, B's items only;
    - A's resources are unchanged afterwards (`version` and bodies);
    - with no cookie, every operation gives 401 before any 404.
  - `backend/tests/Contract/CrossUserCoverageTest.php`: the set of secured operations in the bundled spec equals the set the isolation test declares it covers (a constant in the test). Phase 5's receipt routes then fail CI until covered.
  - `backend/tests/Integration/Expenses/ExpenseFlowApiTest.php`: category → expense → list → rename category → patch expense → delete category (the expense becomes Uncategorized and matches `category=none`) → delete expense.
  - Markers in `build/auth-test/markers.json` (existing mechanism): a description, a category name and a `q` marker. `Makefile` `test-integration` asserts that none appears in the **`api-php`** logs; the nginx access log is excluded (B23).
- **Acceptance:** `make test-integration` is green twice in a row; each new log check was shown to fail through a temporarily mutated `Makefile`.
- **Docker:** yes. **Agents:** tdd-guide; php-reviewer; Makefile inline if under ~30 lines, otherwise code-reviewer (sonnet/medium); **security-reviewer (opus/high)**, asked specifically to find an isolation gap the suite misses; round 2 at opus/high.
- **Commit:** `test: cross-user isolation suite, expense flow and log hygiene for user content`.

#### WP13: Docs, ADR notes and wrap-up (S)
- **Files:**
  - `docs/local-dev.md`: curl for every new endpoint (cookie jar, `X-Origin-Verify`, `Origin`, `X-Requested-With`); `version` and 409; following `nextCursor`; amounts in cents.
  - `README.md` quick start (one line).
  - "Implemented in Phase 3" notes (clarifications, drafted by the main session):
    - **ADR 0002:** item shapes; the `CATNAME#` lock (B6) and that category delete removes it (B14); the `version` condition on updates (B12); the batch sizing and cursor fields (B18); the absent-attribute form of Uncategorized; B21.
    - **ADR 0011:** `400` on every operation, and the new contract checks.
  - PLAN §12 row 3 → Done; this plan's status → Done.
- **Haiku output is fact-checked:** doc-updater (haiku/low) drafts. The main session checks every make target against `make help`, every path with Glob, and **every curl command by running it**.
- **Before the PR is marked ready:** `/update-docs`, `/code-review`, `/verification-loop`, then `/ecc:cost-report` (compare with Phase 2).
- **Docker:** yes (only to verify the commands). **Commit:** `docs: phase 3 local-dev, readme and adr notes`.

## 4. Dependencies

```
WP1 ─► R ─► WP2 ─► WP3 ─┬─► WP4 ─► WP5 ─┬─► WP8 ──────────────┐
                        └─► WP6 ────────┴─► WP7 ─► WP9 ─► WP10 ─► WP11 ─► WP12 ─► WP13
```
- WP7 needs WP5 (the category filter) and WP6 (`pageByDate`).
- WP8 brings the shared contract pieces that WP9–WP11 reuse.
- WP9 needs WP5 and WP6.
- WP12 needs every endpoint.
- WP8–WP11 all touch `Routes.php` and `openapi.yaml`, so they run one after another.

## 5. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| Unit | Text rules, value objects, keys, `RouteId`, field messages, inputs, cursor, filter, lister, resolver, handlers (fakes), cancellation-reason mapping | `backend/tests/Unit/{Http/Input,Categories,Expenses}/**` | `make test-unit` (CI `backend`) |
| Architecture | User-partition queries use `begins_with`/`BETWEEN`; `USER#` built only in `AuthKeys`; the Phase 2 guards unchanged | `backend/tests/Architecture/**` | `make test-unit` |
| Contract | Route parity; guarded routes = secured operations; 400/401/404/409 rules; cross-user coverage | `backend/tests/Contract/**` | `make test-contract` (CI `integration`) |
| Integration (storage) | Repositories, resolver, listing on `TemporaryTable`: retries, version races, name-lock race (deterministic + 10 processes), budget counting, cursor stability, R1 | `backend/tests/Integration/{Categories,Expenses}/*Test.php` | `make test-integration` |
| Integration (API) | Every endpoint through nginx, contract-validated; flow; **cross-user isolation** | `backend/tests/Integration/{Categories,Expenses,Isolation}/**` | `make test-integration` |
| Log hygiene | No description, name or `q` marker in `api-php` logs | `Makefile` + markers | `make test-integration` |
| Front-end | Unchanged; `schema.gen.ts` regenerated; `vue-tsc` catches drift | `frontend/` | `make frontend-test`, `frontend-lint` |

- **Coverage:** ≥ 80% lines on `backend/src` (enforced). Target 100% on `PlainText`, the value objects, `ListCursor`, `ExpenseFilter`, `ExpenseLister`, `CategoryResolver` and `RouteId`.
- **E2E:** the approved deviation (PLAN §12) still applies. The cross-user suite is part of it; the curl smoke test is Phase 6, Playwright is L1.
- **Speed:** the 1,050-item seed uses `BatchWriteItem`-free `putItem` loops on one temporary table per class (seeded once). The `proc_open` race joins the existing `slow` group only if it takes over 5 s.

## 6. Branch, PR, CI and owner gates

- **Branch** `feat/phase-3-categories-expenses`. **One PR**, opened as a draft after WP3, marked ready after WP13, squash-merged. Body: the definition-of-done checklist, the R1–R4 results, the test plan.
- **CI:** 7 required checks; **no job is added**. If one ever is, the owner updates the ruleset by hand (the main session gives the click steps and runs only read-only `gh api` checks).
- **Deletions:** none planned. Any deletion is asked separately.
- **Owner gates:**
  - **G1, approve this plan**, including section 10.
  - **G2, only if a point would change an accepted ADR:** B6/B14 read as a change to ADR 0002, R1 showing that the cursor design cannot work, or B21 changed to UUID v7. ADR 0013 is written and accepted before the WP that needs it.
  - **G3, PR merge**, including the "Implemented in Phase 3" notes.
  - **G4, only if a CI job is added or renamed.**
  - **AWS:** no gate, because **Phase 3 creates or deploys nothing on AWS ($0)**.

## 7. Not in Phase 3, and traceability of the carried items

### 7.1 Carried items → where they land

| Carried item | Source | Phase 3 |
|---|---|---|
| Money as integer cents or a decimal string, never JSON floats | Phase 1 plan, WP3 security notes | B1; WP2 (`Amount`), WP9 (contract `type: integer`) |
| Route ids validated as UUIDs before building keys | Phase 1 S-M2; Phase 2 §7.1 | B9; WP3 (`RouteId`, zero reads), used in WP8–WP10 |
| Cursor per ADR 0002, validated before DynamoDB, tampering gives 400 | Phase 1 WP9 notes | B18; WP7, WP11 |
| `limit` ≤ 100 and the 1,000-item examination cap | Phase 1 WP9 notes | B17, B18; WP7 (counted in an integration test) |
| Non-idempotent updates guarded by a version condition | Phase 1 WP9 notes | B12, B13; WP4, WP6, WP10 (no `ADD` in Phase 3) |
| Every query on `PK = USER#<id>` uses `begins_with(SK, …)`, tested, so `PROFILE` is never returned | Phase 2 WP7 notes | WP3 (architecture test); WP4, WP5, WP7 (seeded `PROFILE`) |
| Harden `AwsClientConstructionTest` (`::factory`, other client classes, heredoc keys, all three roots) before the first S3 client | Phase 2 WP7 notes (written as "Phase 3") | **Carried to Phase 5:** Phase 3 builds no AWS client; the first S3 client is the receipt upload policy. Code review stays the backstop |
| Key builders take PK from the session principal; creates use `attribute_not_exists(PK)` and build items field by field | Phase 1 WP9; Phase 2 | B10; WP3, WP4, WP6 |
| A conditional write retried after a timeout may be a false conflict: re-read first | Phase 2 A19 | B13; WP4, WP6 |
| Throttling cancellations → 503 + `Retry-After` | Phase 2 A18 | Reused; tested for the category and expense transactions (WP4, WP8) |
| Reject control characters; never use `array_is_list` to tell objects from arrays | Phase 1 WP3 notes | B4; WP2 (`PlainText`, `ObjectMembers`) |
| Never log a raw request body; no text rule for JSON inside a string | Phase 2 WP2 notes | B23; WP12 marker checks |
| Floods are left to API Gateway / WAF (ADR 0004 accepted risks) | ADR 0004, Phase 2 WP14 | B22: the same stance for Phase 3 writes |
| No GET/HEAD/OPTIONS handler changes state (A8) | Phase 2 A8, WP12 | Every new GET only reads; review checklist WP8–WP11 |
| `RequireSession` outermost; route factories do not branch; lazy routes | Phase 2 WP12 notes | WP8–WP11 |

### 7.2 Not in Phase 3 (with the reason)
- **Front-end** (Phase 4). Carried to Phase 4:
  - format `amountCents` with the app currency (B2);
  - send `version` on every `PATCH`, and on `409` offer "reload";
  - "Load more" relies on `nextCursor`, not page length;
  - re-send the same filters with a cursor;
  - an empty-categories state (B7);
  - the Phase 2 carried items still apply.
- **Receipts** (Phase 5): S3 clean-up after the B15 transaction; the `receipt` member; harden `AwsClientConstructionTest` first; add the receipt routes to `CrossUserCoverageTest`.
- **Monthly summary** (Phase 6): reuse `ExpenseRangeReader` and `CategoryResolver`; decide how a very large month is bounded (B8); `scripts/smoke.sh` covers categories and expenses, with a cookie jar and the CSRF headers.
- **Phase 7:**
  - IAM: `Query` on the table and `/index/LSI1`, and `TransactWriteItems` through Put/Delete/Update/ConditionCheck;
  - the API Gateway access-log format excludes query strings (B23);
  - per-route throttling for writes (B22).
- **Category colours, and the `q` UI if not in Phase 4:** L5 (additive).
- **Out of scope (BRIEF, or not asked):** multi-currency, i18n, sharing, totals and page numbers, CSV export (it would need formula-injection escaping of descriptions and names, Phase 2 WP6 note), bulk operations, recurring expenses, negative amounts.

### 7.3 Review notes
As in Phases 1 and 2, each WP's review findings (and anything carried) are added here as "Notes from the WPn reviews" before the next WP starts.

### Notes from the WP2 reviews (2026-10-21)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP2 diff (base `b5a0147`), then round 2 of both (approved). A last LOW batch, suggested by the security reviewer, was checked inline by the main session. `CredentialsInput` now uses `ObjectMembers` with identical behaviour (both reviewers compared the code paths; its tests are unchanged).

- **Possible ReDoS (round 1 HIGH, security):** the edge trim was a regex whose end branch had no start anchor. Benchmarked: under 1 ms for 60,000 inner spaces of U+0020, U+3000 and U+00A0 (PCRE's optimisations kept it linear), so not exploitable as written. Fixed anyway: `mb_trim` with an explicit list (ASCII whitespace plus every `\p{Z}` code point; NUL and U+0085 deliberately absent so they are refused, not trimmed), and a byte pre-check (`> maxChars × 4 + 1024` → `TooLong`) before any regex.
- **PCRE errors fail closed:** `App\Http\Input\Pcre` throws on `null`/`false` with `preg_last_error_msg()` only, never the subject (`zend.exception_ignore_args` keeps it out of traces); it becomes a generic `500`.
- **Invisible text (both reviewers, MEDIUM):** `\p{Cf}` is refused (`TextViolation::InvisibleCharacter`) except ZWSP, ZWNJ, ZWJ, LRM, RLM (U+200B–U+200F), ALM (U+061C) and the tag characters U+E0020–U+E007F, which real keyboards, scripts, emoji and flags need; the blank fillers U+115F, U+1160, U+2800, U+3164 and U+FFA0 are refused; text with no letter, number, symbol or punctuation is `Empty`. U+2028/U+2029 are refused inside text (trimmed at the edges). Bidi embeddings, overrides and isolates stay refused (B4).
- **Category name key (B6):** strips the allowed invisibles, collapses every run of `\p{Zs}` to one space, then `MB_CASE_FOLD` and SHA-256, so `Food`, `Food` + ZWSP, `Fo` + ZWJ + `od`, `Eating  out` and `Eating` + NBSP + `out` collide with their plain forms. The stored name stays byte for byte. Fold results from R2 (`İ`, `ǅ`, `Straße`) are pinned.
- Check order: size guard, encoding, trim, empty, control, bidi, invisible, nothing visible, length (pinned by precedence tests). No field message echoes input (each message test asserts a marker is absent).
- Deviations from the plan, accepted: `TextViolation::InvalidEncoding`; `AmountViolation` = `NotAnInteger` / `TooSmall` / `TooLarge` (a bigint-as-string is `NotAnInteger`); `ExpenseDate::parse()` returns `?self`; `ExpenseDate::isAfter()` and `Amount::MIN_CENTS` added.

Carried:
- **WP4:** store `nameKey` on the `CAT#` item (already in B20's item list) and delete or condition the lock on the **stored** key, never a recomputed one, so a future change in PHP's case-folding tables cannot orphan a `CATNAME#` lock.
- **WP8–WP11:** `Description`, `CategoryName` and `ExpenseDate` take `string`; the input classes must check `is_string` first (one shared "string member or error" helper), or `strict_types` turns a number or `null` into a `500` instead of a `422`.
- **Phase 4 (front end):** count characters by code point (`[...s].length`, not `s.length` / `maxlength`) so the client agrees with `mb_strlen`; clip overflow on names and descriptions (combining-mark floods pass the length check by design).
- **Accepted (ADR 0002 note in WP13):** Arabic prepended marks (U+0600–U+0605, U+06DD) and the Mongolian vowel separator are refused as format characters; no Unicode normalisation (precomposed `é` and `e` + U+0301 are different names).

### Notes from the WP3 reviews (2026-10-22)

From ecc:php-reviewer (sonnet) and ecc:security-reviewer (opus/high) on the uncommitted WP3 diff (base `b6ac133`), then security round 2 (opus/high) on the fixes, whose own small follow-ups were checked inline by the main session. Neither round found anything exploitable today: every finding hardens code that later WPs will write. 1915 unit tests, 99.77% line coverage.

- **Query guard checked text, not the key condition (round 1 HIGH, php):** any `begins_with(` or `BETWEEN` literal anywhere in the call passed (a `FilterExpression`, `begins_with(PK, …)`, a message). Now only the key condition counts (the first argument of `ItemStore::query`, or the `'KeyConditionExpression'` value), and it must be one literal containing `begins_with(SK,` or matching `\b(SK|LSI1SK) BETWEEN\b`, case-sensitive. `#sk` placeholders are deliberately refused: a placeholder can be mapped to `PK`.
- **Other ways to read a partition (round 1 HIGH php / MEDIUM security, round 2 MEDIUM):** `SdkOperationsTest` confines the operation literals `Query`, `Scan`, `ExecuteStatement`, `BatchExecuteStatement`, `ExecuteTransaction`, `BatchGetItem`, `TransactGetItems` and the matching SDK calls (and `*Async`, `getPaginator`, `getIterator`) to `ItemStore.php`; `DynamoDbClientFactoryCallTest` allows `DynamoDbClientFactory::create(` only in `ApiFactory.php`.
- **Runtime safety net (security):** `ItemStore::query` throws `StorageFailure` (`ProfileInQuery`) if any item has `SK = PROFILE`, before anything is returned; `StorageUnavailableMiddleware` logs it at error level as a security event (request id, operation and code only) and it becomes a generic `500`.
- **PK from the principal, enforced (round 1 + 2 MEDIUM, security):** `CategoryKeys` and `ExpenseKeys` take `Principal $owner`, via `AuthKeys::userPartitionOf(Principal)`; `UserPartitionCallTest` allows the string `userPartition(` only inside `AuthKeys.php` (no Phase 2 caller needs it). `PrincipalConstructionTest` allows `new Principal(`, dynamic instantiation and `Principal::class` with reflection only in `SessionAuthenticator.php`; `Principal` refuses `__clone` (including PHP 8.5 clone-with), `__serialize` and `__unserialize`.
- **`USER#` rule:** decodes double-quoted and heredoc literals (`"USER\x23"`, `"USER\043"`, `"USER\u{23}"`), and `AuthKeys.php` is now scanned for every rule except this one.
- Small: `Uuid::require()` message without an article; `nameLock` pinned to `CATNAME#` + 64 hex; trailing `\n` refused; `RCPT#` does not start with `EXP#`; the 2000-01-01 and 2099-12-31 index keys sort inside their day's range; the RouteId test renamed to what it really checks. Fixtures were already excluded from PHPStan and the classmap.
- **Process note:** round 1's fixes were written test and code in one pass (only the `ItemStore` net had a red step); round 2 showed a red step per fix, except the token checks for dynamic `Principal` instantiation.

Accepted limits (documented in the test docblocks): `USER#` built in pieces or by interpolation; a class name passed as a string and instantiated in another file; `begins_with(SK, :p)` with a caller-chosen `:p` (the PK still comes from the session and the safety net catches `PROFILE`).

Carried:
- **WP4–WP7:** pass sort-key prefixes only from the `*Keys::PREFIX` constants. A typed SK-prefix enum is deferred; revisit if a query ever takes a prefix from outside a `*Keys` class.
- **WP8–WP10:** handler tests repeat "a malformed id gives `404` with zero storage reads", now against the real repository fakes.

### Notes from the WP4 reviews (2026-10-23)

From ecc:php-reviewer (sonnet/medium) and ecc:security-reviewer (opus/high) on the uncommitted WP4 diff (base `704aa2b`), then security round 2 (opus/high) on the fixes. No round found a CRITICAL, HIGH or exploitable issue: no path reaches another user's partition, `PROFILE`, sessions, the email lock or `EXP#`; every lock delete is conditioned on its holder; uniqueness rests on conditional puts inside the transaction (no read-then-write gap); messages carry no keys or item data.

- **Rename with a missing or foreign old lock (MEDIUM php / LOW security):** the old-lock delete failing alone (`[None, None, ConditionalCheckFailed]`) rethrew a raw cancellation, so the category could never be renamed (a permanent `500`). Now rename re-reads the category and, if its version and stored `nameKey` are unchanged, resends without the old-lock delete; a foreign lock is never touched. Bounded by `MAX_RENAME_ATTEMPTS = 3`.
- **Delete fallback (added during implementation, beyond B14):** if only the lock condition fails (lock missing or held by another category), the category alone is deleted under its version condition, so a category can never become undeletable. Security round 1 confirmed a foreign lock is never touched.
- **Malformed lock holders (LOW security, rounds 1 and 2):** a lock whose `categoryId` is not a lower-case UUID blocked that name forever. It is now treated as stale and deleted on a condition that can never match a live lock: the exact value for a string holder, `NOT attribute_type(#categoryId, :S)` for a non-string one (numbers do not round-trip exactly with `wrap_numbers = false`), `attribute_not_exists` when absent. No key is ever built from a stored holder that is not a valid UUID.
- **Explicit loop bounds (LOW, both):** create and rename loop to `MAX_*_ATTEMPTS` and rethrow after the loop, like delete.
- **Concurrency test (MEDIUM php / LOW security):** the child prints `conflict` for a `TransactionConflict` cancellation, and the test accepts `taken` or `conflict` for every loser. DynamoDB Local serialises transactions, so this test does not prove the production `409`/`503` split; the simulated unit cases (`[TransactionConflict, TransactionConflict]` for create, rename and delete) do. The contention assertion was kept after 10 passing runs in a row.
- **Smaller fixes:** times are truncated to whole seconds in `Category` itself, so what create and rename return equals a later read; `corrupt()` names the real operation (`ItemStore::QUERY_OPERATION` added, because `SdkOperationsTest` forbids the literal); the recomputing `CategoryKeys::nameLock()` was removed, so every lock is addressed by the stored key through `nameLockOfStoredKey`; one shared `CategoryKeys::NAME_KEY_PATTERN`; typed, per-operation constants; `@throws` lines.
- **Process note:** behaviours driven by the integration tests showed a red step each. The unit tests with simulated SDK answers were written after the code and checked by mutation instead (each mutation failed the named tests). The malformed-holder fix's red run was contaminated by a half-applied edit for another fix; the test itself checks the right behaviour. For the non-string holders (round 2), only the large-number case was red on DynamoDB Local; the M, L, BOOL and NULL cases passed before the fix and are kept as pins for real DynamoDB.

Carried:
- **WP8:** `Category::$nameKey` is a public property. The response mapper test asserts the keys are exactly `{id, name, version, createdAt, updatedAt}` (B20).
- **WP8:** a rename that runs out of attempts can rethrow a raw `ConditionalCheckFailed` cancellation. Any cancellation that is not a name or version outcome must stay a generic `500`/`503`, never a `409` or a name message.
- **WP8 (B8):** the 100-category cap is checked by the create handler, not the repository.

### Notes from the WP5 reviews (2026-10-23)

From ecc:php-reviewer (sonnet/medium) and ecc:security-reviewer (opus/high) on the uncommitted WP5 diff (base `31549f5`). Neither found a CRITICAL, HIGH or MEDIUM issue needing code: the lister always gets the resolver's own `Principal`, non-UUID ids return before any read, `hydrate()` rejects an item from another partition, and the `foldedValue()` extraction keeps every `nameKey` byte-identical.

- **One query per request, also on failure (LOW security):** a failed read was retried on every call, so one corrupt `CAT#` item could cost a full category query per expense. The resolver now keeps the exception and rethrows it; the lister is called at most once per instance.
- **Paging (MEDIUM php / LOW security):** the 120-category integration test fits in one DynamoDB page, so it proves there is no hard cap, not paging. Paging of `listAll` is covered by the simulated two-page unit test in `Unit/Categories/CategoryRepositoryTest.php`; the test comment now says so.
- **Smaller fixes:** a golden-hash test pins `key()` for a name with a zero-width character and an NBSP run; `@throws` lines; the private loader renamed; the docblock states that a resolver is a per-request snapshot, never kept in a long-lived service or reused after a category write; the test fake moved.
- **Process note:** the resolver's unit tests were run red against a stub (5 of 16 failed; the rest assert empty results and only bite once the code exists); the integration tests were written after the code. A sort bug (`<=>` compares numeric-looking strings as numbers) was caught by a test and fixed with `strcmp`.

Carried:
- **WP8 (B8, security L1):** the soft cap can overshoot by as many creates as run in parallel, not "one request round", and every expense read then lists them all. WP8 bounds this at create time (and the B8 wording is corrected in WP13).
- **WP9 (security L3):** a non-string `categoryId` in a body must be refused with `422` before `has()` is called (its `string` type would otherwise throw a `TypeError`, a generic `500`). Already covered by the "check `is_string` first" item.
- **WP7–WP11:** a `StorageFailure` from the resolver ends the request; no caller catches it per item.

### Notes from the WP6 reviews (2026-10-23)

From ecc:php-reviewer (sonnet/medium, verdict approve) and ecc:security-reviewer (opus/high) on the uncommitted WP6 diff (base `e6ca2c0`). Neither found a CRITICAL, HIGH or MEDIUM code defect: every key comes from the principal, `expenseItem` cannot overwrite `PK`/`SK`, the delete transaction touches only `EXP#<id>` and `RCPT#<id>`, a crafted start key can only move within the caller's own expenses, and messages carry no item data.

- **R1 (gate): passed.** On DynamoDB Local 3.3.1, deleting the start-key item, or re-dating it to a newer or older day, between two `pageByDate` calls skips nothing and repeats nothing. No G2, no cursor redesign.
- **Start keys, defence in depth for WP7's cursor (LOW, both):** a start key must also lie inside `[rangeStart(from), rangeEnd(to)]`; every start-key refusal is a dedicated `InvalidStartKey` (so WP7 can map exactly that to `400` without hiding programming errors); and `lastKeyOf` runs the returned key through the same checks, range included (security round 2, LOW), so the server can never hand out a cursor it would refuse (a failure is a corrupt item naming `Query`). Round 2 (opus/high, fixes only) found nothing else.
- **Smaller fixes:** a stored `categoryId` of any non-string type (NULL included) is a corrupt item; the unit tests pin both literal update expressions and the exact name and value key sets; integration covers the replay of an update that sets a category and a page whose limit equals the remaining count (non-null `lastKey`, then an empty batch).
- **Process note:** behaviours were red against a stub (or a missing class) before the code. The unit tests with simulated SDK answers came after the code and were mutation-checked: 15 mutations, 13 killed by unit tests, 2 only by integration tests (the start-key member count gap was closed with a new unit case).

Carried:
- **Refactor before WP7 (MEDIUM php):** the stored-time helpers (`format`, `parseTime`, `wholeSecondsUtc`, the format constant) exist in `Category`, `CategoryRepository`, `Expense`, `ExpenseRepository` and `UserRepository`. Extract them into one class in a separate `refactor:` commit, with a re-review of only the changed hunks.
- **WP7 (B18):** `ExpenseBatch::lastKey` is a valid cursor only when the whole batch was examined; a lister that stops mid-batch builds the cursor from the stop item.

### Notes from the WP7 reviews (2026-10-23)

From ecc:php-reviewer (sonnet/medium) and ecc:security-reviewer (opus/high) on the uncommitted WP7 diff (base `a7b4cb7`). Neither found a CRITICAL or HIGH issue: no cursor, filter or `q` reaches another partition or a non-expense item; every refusal happens before any store call; the cursor is always built from the last examined item; the 1,000-item budget is exact; no message carries `q`, the cursor, ids or values. `Listing/` has 100% line coverage.

- **Resolver ownership (MEDIUM security):** `ExpenseLister::list(Principal, ExpenseFilter, CategoryResolver)` did not check that the resolver belongs to the principal; a lifecycle bug in WP11 would give silently wrong answers (and could leak elsewhere). `CategoryResolver::assertFor(Principal)` now fails loudly first, and the category `has()` check moved from `ExpenseFilter::fromQuery` (which now makes no store call) into `list()`, after the assert.
- **Canonical cursor (MEDIUM php / LOW security):** the JSON inside the cursor was not required to be canonical (duplicate or reordered members, whitespace). `decode()` now refuses anything that `encode()` would not write byte for byte.
- **Invisible-only `q` (MEDIUM php):** a `q` that folds to `''` is absent, so it no longer forces full 100-item batches.
- **Server-built start keys (MEDIUM php, then LOW security round 2):** only a start key from the client's cursor maps to the cursor `400`; a refused key from a later batch, or on a request without a cursor, is a server error.
- **Reader over-delivery (LOW security):** a batch larger than requested is a corrupt-item `StorageFailure`, so the budget cannot be exceeded by a misbehaving store.
- **Smaller fixes:** the filter hash is computed in one place. `FoldedText` (extracted from `CategoryName`, byte-identical) stays without a private constructor, matching `PlainText`. The invisible-only `q` guard is defence in depth: `PlainText` already refuses text with nothing visible, so it survives mutation.
- **Security round 2** (opus/high, fixes only) found nothing beyond the no-cursor case above; it confirmed the final `encode(decoded) === raw` check is strictly stronger than the check it replaced.
- **Flaky integration run:** one full-suite run (in the main session, right after the agent's own run) had 1 failure in 345 tests; eleven further full runs passed and the failing test was not captured. Likely cause (not proven): two test runs overlapping on the shared local stack. The make target restarts worker-runner when a run ends, and the agent's own runs (one cut off by its own timeout) were restarting it around the time of the failing run. Lesson: never run two integration suites against the same stack at once. The prime suspect, the 1,050-item budget test, now asserts the per-query `Limit` list instead of totals counted in SDK callbacks. Every test run now keeps its full log.
- **Process note:** all tests were written first and run red against throwing stubs; most then only proved the stubs wrong, so the code was mutation-checked (39 mutants, 33 killed, the rest equivalent; two real gaps, the 512-character cap and non-canonical base64 trailing bits, were closed).

Carried:
- **WP11:** build the resolver and the lister call from the same session principal (now enforced by `assertFor`). The contract text says `nextCursor` is null only when the range is exhausted, so an empty last page after a non-null cursor is possible. `InvalidQuery` maps to `400`.
- **Review briefs:** reviewers must not run the host PHP (8.4); the WP7 security review ran a few `php -r` checks of built-in JSON/base64 behaviour, harmless but outside the make-only rule.

### Notes from the WP8 reviews (2026-10-23)

From ecc:php-reviewer (sonnet/medium) and ecc:security-reviewer (opus/high) on the uncommitted WP8 diff (base `b23a12e`), then security round 2 (opus/high) on the fixes (approved). No round found a CRITICAL or HIGH issue: every key comes from the session principal; malformed, unknown, deleted and other users' ids get a byte-identical `404`, the malformed ones with zero reads; wrong JSON types and every `version` edge case give `422`, never a `TypeError` `500`; the response maps exactly `{id, name, version, createdAt, updatedAt}`, so `nameKey`, `PK` and `SK` never leave the server; cancellations that are not a name or version outcome stay a generic `500`/`503`.

- **B8 cap, made a real bound (main-session decision, beyond the plan's soft cap):** the create handler counts before creating (`409` limit reached, nothing written), and after a successful create counts again with a fresh, strongly consistent read; above `CategoryLimits::MAX_PER_USER` it deletes the category it just created and answers the same `409`. The count can exceed 100 only while racing requests are in flight, never afterwards (security round 1 confirmed the bound and that the back-out can only remove the request's own new category). Accepted: under a race at the cap several creators may all back out. Remaining gap, documented in the handler: an ambiguous create failure (timeout or 5xx after the commit) skips the recount, so the user can stay above the cap; every further create is then refused. B8's wording is corrected in WP13.
- **Back-out of a category already deleted (MEDIUM php / LOW security):** `CategoryNotFound` from the compensating delete now still answers `409` instead of a `500`; `StaleVersion` there stays a `500`.
- **`PrincipalConstructionTest` false positive:** `Category::new(` was read as a dynamic `new (`, so the first draft copied the factory into the handler. The scanner now skips a `new` after `::` or `function` (over `&`, whitespace and comments), `Category::new` is used again, and, closing a gap found by security round 1, `new self`, `new static` and `new parent` are flagged in the file that declares `class Principal`. Round 2 (LOW): `new class (...) extends Principal` is refused only by `final`, now pinned by `PrincipalTest`.
- **Tests made discriminating (MEDIUM php):** the back-out asserts the version condition and the exact `CATNAME#<stored nameKey>` lock key; the recount test fails if the second count is served from the memoised resolver.
- **`DELETE /categories/{id}` has no `409`** (plan endpoint table): a category that keeps changing during a delete gives a generic `500`.
- **Smaller:** `Routes::guarded()` is the one place that wraps a handler in `RequireSession`, plus a `categories()` helper; pointer constants in `CategoryInput`; the contract's `name` has `minLength: 1` and `maxLength: 1224` (the server's byte pre-check, 50 × 4 + 1024: a contract 50 would refuse names the server accepts after trimming); `BadRequest` and `MethodNotAllowed` texts corrected; `400` documented on `/auth/me` and `/auth/logout` (B19, R12).
- **`memory_limit = 256M` in `phpunit.xml.dist`:** the unit suite under coverage was near the 128M default and the 20,000-line scanner test tipped it over; memory stays flat across the new tests (no leak). Tests only.
- **Process note:** contract tests, the unguarded route (`ProtectedRoutesMatchContractTest` failed on purpose) and all handler unit tests were red before the code; the fix-round fixtures and the back-out test were red before their fixes; the delete-condition and fresh-read tests were added as pins and passed against the existing code. The first integration run failed in the new test itself (a second registration while carrying the first user's cookie ends that session); the test was fixed, not the code. One integration suite at a time throughout.

Carried:
- **WP13:** correct B8's "one request round" wording to the recount bound and its ambiguous-write gap.
- **Phase 4:** the generated client's `maxLength` on `name` is the byte pre-check, not the 50-character rule; the form validates 50 code points after trimming itself.

## 8. Risks and decision points

| # | Risk / trigger | Mitigation / fallback | WP |
|---|---|---|---|
| R1 | An `LSI1` start key for a deleted or re-dated item is refused or skips items | Research R1 plus RED tests; if it fails, G2 (cursor redesign, ADR 0013) before WP7 | WP6, WP7 |
| R2 | Another user's data leaks through an id, filter or cursor | `PK` only from the principal; 404-not-403; the cross-user suite and coverage meta-test | WP3, WP12 |
| R3 | A query returns `PROFILE` (password hash) | `begins_with`/`BETWEEN` architecture test; field-by-field mapping; seeded-profile tests | WP3–WP7 |
| R4 | Lost updates from two tabs | `version` condition and 409 (B12); a concurrent PATCH test | WP6, WP10 |
| R5 | A false 409 or duplicate name after an SDK retry | B13 re-read; replay tests | WP4, WP6 |
| R6 | Duplicate category names under a race | Lock item in a transaction; deterministic and 10-process race tests, shown to fail on a read-then-write double | WP4 |
| R7 | An orphan lock blocks a name forever | Stale-lock repair on create (B13); tested | WP4 |
| R8 | Filtered lists cost too much | 1,000-item budget (about 100 strongly consistent read units at most per request, well under a thousandth of a cent); category short-circuit | WP7 |
| R9 | Precision loss in amounts | Integers only; floats refused; bound below 2^53 for sums | WP2, WP9 |
| R10 | Text that spoofs the UI (bidi overrides) or breaks logs | B4 rules; names and descriptions never logged; Vue escaping in Phase 4 | WP2, WP12 |
| R11 | Bref collapses duplicate query parameters on AWS | Handler validates values; R4 pins the behaviour | WP7, WP11 |
| R12 | The deferred 400 for malformed query strings is undocumented on existing operations | B19: documented on every operation; contract test | WP8 |
| R13 | A very large month makes Phase 6 slow | Carried to Phase 6 with `ExpenseRangeReader` as the seam | Phase 6 |
| R14 | Scope creep into the UI, receipts or the summary | Section 7.2 | all |
| R15 | Shared Docker host damaged; flagged tools on the owner's machine | The section 3 rules, repeated in every brief | all |

## 9. Lessons from Phase 2, applied

1. **Contract and code in one commit** (`RoutesMatchContractTest`), and shared contract pieces only with their first user (the Redocly lint rejects unused components).
2. **Concurrency is proven at the storage level**, not over the local fpm pool (WP13 lesson): name-lock and version races use deterministic SDK-middleware interleaving plus a `proc_open` race, each shown to fail against a broken double.
3. **Re-read before reporting a conflict** (A19) on every conditional create and update.
4. **Fail closed:** corrupt items give 500; a missing principal gives 500; a malformed id gives 404 with zero reads.
5. **Security round 2 at opus/high** on every security-relevant WP, and reviewers confirm each new test fails without its fix.
6. **Explicit `match` arms, no `default`**, for violation enums and cancellation reasons.
7. **Briefs:** kill containers only by name; no downloaded corpora (endpoint-security rule); invented test strings.
8. **Haiku docs fact-checked** by running every curl command; review notes recorded per WP in section 7.3; `/ecc:cost-report` compared with Phase 2.

## 10. Owner decisions (recommended defaults)

**Answered 2026-10-21: all ten recommended defaults.** Decision 2 (the `CATNAME#` lock) is recorded as a clarification of ADR 0002, not a change.

1. **Money (B1, B2).** `amountCents` integer, 1 to 100,000,000; no currency field (the currency is a Phase 4 display setting)? **Recommended: yes.** Alternative: a decimal string (more parsing, same precision).
2. **Unique category names (B6).** Case-insensitive per user, enforced with a `CATNAME#` lock item in the same transaction? **Recommended: yes.** Alternatives: no uniqueness, or a racy read-then-write check.
3. **Default categories (B7).** None at registration? **Recommended: none.** Alternative: seed about 5 in the register transaction (touches Phase 2 code).
4. **Limits (B8).** 100 categories per user (soft cap), no expense cap? **Recommended: yes.**
5. **Concurrency (B12).** A required `version` in the `PATCH` body with 409 on mismatch, and unconditional `DELETE`? **Recommended: yes.** `If-Match` would need an ADR 0012 change (CloudFront allow-list).
6. **Text search `q` (B17).** Implement it in the API now (ADR 0002 specifies it; the cursor hash covers it from day one), with the UI in Phase 4 or L5? **Recommended: yes, API now.** Alternative: defer to L5 as PLAN §12 lists it.
7. **Field rules (B3, B5, B6).** Description required, 1–200 characters; name 1–50; dates 2000-01-01 to 2099-12-31, future allowed? **Recommended: yes.**
8. **Same-day order (B21).** Stable but arbitrary (random v4 ids)? **Recommended: accept.** Creation order needs UUID v7 and ADR 0013.
9. **Write throttling (B22).** None in Phase 3; Phase 7 API Gateway / WAF? **Recommended: yes.**
10. **PR shape.** One PR, draft after WP3, squash-merged? **Recommended: yes.**
