# Phase 6: Monthly summary. Implementation plan

| | |
|---|---|
| Status | **G1 approved 2026-11-02**: the owner approved this plan with every section 10 default. Next: Step 0 (G2), then WP2. Drafted by `ecc:planner` (opus/high); the main session checked the headline claims against the code (contract version, `formatCents` range, `MAX_QUERY_LIMIT`, register throttle, `JQ_IMAGE`) |
| Phase | 6 (PLAN §12, essential track), size S (the smoke script makes it closer to S–M) |
| Branch | `feat/phase-6-monthly-summary`, from `main` at 4735725 (Phase 5 merged in PR #8) |
| Inputs | BRIEF; PLAN §2, §5 (Reports row), §6 ("Monthly summary" access pattern), §8, §9, §10, §12 rows 6–8, §13; ADR 0002 (+ Phase 3 note), 0005, 0011, 0012 (§4.1 forwarded headers; Bref query normalisation); Phase 3 plan B1, B3, B8, B16–B19, B24, §7.2, R13; Phase 2 plan §7 ("Phase 6: smoke.sh sends Origin and X-Requested-With and keeps a cookie jar"); Phase 4 plan C4, C8, C9, C11, C15, decision 13, line ~490; Phase 5 plan (structure, §3.1 rules) |
| AWS | **None.** No AWS calls, no credentials, $0. The smoke script runs against Compose here and against AWS only in Phase 8 |
| Back end + front end | Both. Contract **0.13.0 → 0.14.0** (additive; see contradiction 1) |

**Scope (PLAN §12 row 6):** `GET /api/v1/reports/monthly?month=YYYY-MM`; a summary **table** page with a "Summary" nav item (Phase 4 decision 13); `scripts/smoke.sh` (curl) covering every feature against any base URL; `make smoke`.

**Not in scope:** the Chart.js chart and category colours (L5); percentage-share column (decision 22); CSV export (Phase 3 §7.2); Playwright (L1); the AWS-only smoke checks (a direct API Gateway call is rejected; a forged `CloudFront-Viewer-Address` is ignored; AWS DLQ depth) belong to Phase 8; deploy pipeline (L2).

## Contradictions found while planning

- (1) **Contract version.** The planning brief said the contract is 0.12.0 and goes to 0.13.0. The code says `contract/openapi.yaml` `info.version: 0.13.0`: Phase 5 WP6 bumped it when it added `getReceipt`/`deleteReceipt`. **→ Phase 6 bumps to 0.14.0** (WP3). Additive, so it stays inside ADR 0011.
- (2) **ADR 0002 says "the same query for one month (all pages)".** Phase 3 B8, B24 and R13 say Phase 6 must bound a very large month, because expenses have no cap. **→ D5** (a read cap with `complete: false`). It goes in an "Implemented in Phase 6" note on ADR 0002 as a clarification. If the owner reads it as a change, it becomes ADR 0013 at G2.
- (3) **Phase 4 says the summary "reuses `formatCents`".** But `frontend/src/lib/money.ts` `formatCents` accepts only 1..100,000,000 cents (`isValidCents`) and shows `—` for anything else. A month total can be 0 (an empty month) or above 1,000,000.00, so totals would show `—`. **→ D14**: add `formatTotalCents`, which shares `decimalString` and the formatter and accepts integers 0..`REPORT_MAX_TOTAL_CENTS`. `formatCents` is unchanged.
- (4) **PLAN §10 (L2): the smoke test "asserts `ready` and `failed` and an empty DLQ".** The smoke script must work against any base URL over HTTP, and a DLQ is not reachable over HTTP. Also, `make test-integration` deliberately leaves a poison message in the local `receipts-dlq`, so "DLQ empty" is false locally after any integration run. **→ D21**: `smoke.sh` asserts `ready`/`failed` through the API. `make smoke` also checks that the local DLQ count did **not grow** during the run (count before and after). The AWS DLQ check is carried to Phase 8/L2 (AWS CLI, owner's SSO).
- (5) **Repeatable smoke vs the register throttle.** `ThrottleRule::REGISTER_ADDRESS_LIMIT = 5` per hour per address (20 per /64), so a script that registers on every run fails on the 6th run in an hour. Two users per run (a cross-user check) would allow only 2 runs. **→ D17, D18**: one fresh user per run by default, a clear message on 429, an optional `SMOKE_EMAIL`/`SMOKE_PASSWORD` login mode, and the cross-user check opt-in (`--cross-user`).
- (6) **No account-deletion endpoint** (out of BRIEF scope), so the smoke run cannot remove its user. Locally DynamoDB Local runs `-inMemory`, so restarting the stack clears it. On AWS each run leaves one `USER#`/`EMAIL#` pair. **→ D19** (accepted for Phase 6; decided in Phase 8).
- (7) **Repeated query parameters behave differently** (ADR 0012, Bref normalisation). Locally `month=a&month=b` gets 400 from `QueryString`; on Lambda the last value wins. **→ D4**: the handler validates what it receives. Tests pin the local behaviour only, and the smoke script never sends a repeated parameter.
- (8) **`<input type="month">` is not supported on desktop Firefox or Safari** (they fall back to text), but Phase 4 G3 requires Chrome and Firefox. **→ D12** (Previous/Next buttons plus month and year `<select>`s). Step 0 R1 confirms the current browser support.

## 0. What exists vs what Phase 6 builds

| Area | Exists (read from the code) | Phase 6 builds |
|---|---|---|
| Month read | `Expenses\Listing\ExpenseRangeReader::pageByDate(Principal, ExpenseDate $from, ExpenseDate $to, ?array $startKey, int $batchLimit): ExpenseBatch`, implemented by `ExpenseRepository` (LSI1 descending, strongly consistent, batch 1..`ItemStore::MAX_QUERY_LIMIT` = 1000; start key must lie in range). `ExpenseLister::read()` holds two batch guards (more items than the limit, or an empty batch with a `lastKey`, gives `StorageFailure CORRUPT_ITEM`). `tests/Support/FakeExpenseRangeReader.php`, `ExpenseBuilder.php` | `App\Reports\*`: `ReportMonth`, `MonthlyReportBuilder`, `MonthlyReport`, `CategoryTotal`. The two guards move to one shared place (D6) |
| Categories | `Categories\CategoryResolver` (lazy, one query per request, `assertFor()`, `resolve()` returns null for none, deleted or foreign ids, `all()` sorted by folded name then id; `Category->name`). `tests/Support/CountingCategoryLister.php` | Used as is |
| HTTP | `Routes::all()` (18 routes, factories, `RequireSession` outermost); `InvalidQuery` (400; detail names the parameter, never its value); `ApiKernel` adds `Cache-Control: no-store` to every answer; `StorageUnavailableMiddleware` gives 503/500 | `Reports\Http\{MonthlyReportHandler,MonthlyReportQuery,MonthlyReportResponse}`; route `GET /reports/monthly` |
| Contract | 0.13.0, tags health/auth/categories/expenses/receipts, `InvalidQuery`/`SessionRequired`/... responses; no `/reports` | 0.14.0: tag `reports`, `getMonthlyReport`, schemas `MonthlyReport`, `CategoryTotal` |
| Isolation | `CrossUserIsolationApiTest::SECURED_CALLS` (13 rows), `ID_ATTEMPTS`, world: A has October 2026 (5 expenses, 2 categories, 1 Uncategorized), B has September 2026 (3 expenses); `CrossUserCoverageTest` fails while a secured operation is uncovered | Row `getMonthlyReport` plus one dedicated test (D9) |
| Front end | `lib/money.ts` `formatCents` (1..1e8 only); `lib/dates.ts` (`todayLocal`, `isIsoDate`, `formatDate`, UTC formatter); `lib/expenseQuery.ts` (`filtersToQuery`, `CATEGORY_NONE`, `isUuidV4`); `api/http.ts` `request(..., {query, signal, auth})` (`SAFE_PATH` already allows `/reports/monthly`); `AppHeader.vue` nav = Expenses, Categories; `router/index.ts` (no summary route); `ExpenseTable.vue` (table, caption, `bdi`, clip CSS); `useCategoryLabels.ts` (private `UNCATEGORIZED`); 100% coverage gate | `api/reports.ts`, `lib/months.ts`, `formatTotalCents`, `composables/useMonthlyReport.ts`, `components/{MonthPicker,SummaryTable}.vue`, `views/SummaryView.vue`, route, nav link |
| Scripts | `scripts/` holds shell guards, each with `scripts/tests/*.test.sh` run by `make test-shell` (CI `guards` job); `scripts/check-stack-up.sh`; no `smoke.sh`. `docs/local-dev.md` has curl walkthroughs on `:8080` with `X-Origin-Verify` (curl ≥ 7.79 keeps the `Secure` cookie on `http://localhost`) | `scripts/smoke.sh`, `scripts/smoke-local-dlq.sh`, `scripts/tests/smoke.test.sh` (+ fixtures) |
| Make / CI | `JQ_IMAGE` pinned by digest (`ghcr.io/jqlang/jq:1.8.2@sha256:…`, exported); `test-integration` stops `worker-runner` during part 1 and leaves a poison message in the DLQ; CI `integration` job runs `make up … test-integration` | `make smoke`; one new **step** in the `integration` job (no new job, so no G4) |

## 1. Goal and definition of done

**Goal:** a signed-in user opens "Summary", picks a month, and sees a table of their spending by category for that calendar month: per-category total and count, one Uncategorized row (no category or a deleted one), and a grand total. Every number is exact integer cents. One command, `scripts/smoke.sh <base-url>`, proves every feature end to end against the local stack now and against AWS in Phase 8.

**Done means:**
- **Back end:** `getMonthlyReport` matches contract 0.14.0 (every integration response is validated). It reuses `ExpenseRangeReader` and `CategoryResolver`. A month is bounded (D5). It answers 400 for every invalid query (D4). The cross-user rows pass.
- **Front end:** `/summary` with month picker, table, and loading/error/empty/incomplete states. "Summary" in the nav. `make frontend-lint frontend-test frontend-build check-bundle-csp check-bundle-secrets frontend-audit` pass, with 100% coverage kept.
- **Back-end checks:** `make lint test-unit test-contract`, then `make test-integration` (one run at a time), all pass. 80% bar on `src/`; target ≥ 95% on `src/Reports`.
- **Smoke:** `make smoke` passes against `make up` locally (twice in a row, never concurrently with `test-integration`) and in CI. `make test-shell` covers the script's guards.
- **G3 walkthrough** by the owner (§6).
- **Docs:**
  - `docs/local-dev.md`: "Monthly summary from the command line" and "Smoke test" sections;
  - ADR 0002 note (D5), ADR 0011 note (0.14.0);
  - PLAN §12 row 6 → Done;
  - README plan list.
- **Safety:** no new Composer or npm dependency and no new image (decision 20).

## 2. Design decisions (recommended defaults)

| # | Topic | Recommendation | Source / ADR impact |
|---|---|---|---|
| D1 | Month definition | A month is the **calendar month of the expense's `date`** (B3: a calendar day with no time and no zone). `from = YYYY-MM-01`, `to` = the last day of the month (leap years included, `cal_days_in_month`/`checkdate`). The server never uses "now" or a time zone, so there is no boundary drift. Range `2000-01`..`2099-12` (from `ExpenseDate::MIN/MAX`) | Phase 3 B3; ADR 0002 |
| D2 | Parameter | `month` is **required**. The client sends the user's local current month (`todayLocal().slice(0, 7)`), because the server does not know the user's zone. No server default | Decision 1 |
| D3 | `ReportMonth` value | `App\Reports\ReportMonth::parse(string): ?self` with `/\A(\d{4})-(\d{2})\z/D` (ASCII digits only; no `u` flag), month 01–12, inside the range. Methods: `value()`, `firstDay(): ExpenseDate`, `lastDay(): ExpenseDate` | — |
| D4 | Query validation | `MonthlyReportQuery::fromQuery(array $query)`, run before any read: only `month` is accepted. An unknown parameter → 400 (`InvalidQuery::unknownParameter()`); a missing month, a non-string (`month[]=`), or a value that does not parse (`2026-1`, `2026-13`, `1999-12`, `2100-01`, leading or trailing space, full-width digits, `2026-10-01`) → 400 "The month parameter is not valid." Reuse `Expenses\Listing\InvalidQuery`, adding `const MONTH = 'month'`. The detail never echoes the value. Repeated parameters: local 400 only (contradiction 7) | Phase 3 B17, B19 |
| D5 | **Bounding a very large month** | `MonthlyReportBuilder::BATCH = 1000` (the `pageByDate` maximum) and `MAX_EXPENSES = 10_000` (about 330 a day: no real personal use comes near it). Reading stops at the cap. If the cap is reached and the last batch has a `lastKey`, **one probe** (`pageByDate(..., lastKey, 1)`) tells "exactly 10,000" (complete) from "more" (`complete: false`). With `complete: false` the totals cover the **newest** 10,000 expenses of the month (the read is descending), and the UI says so. Worst case is 11 strongly consistent queries of ≤ about 400 KB, about 1,250 read units (about $0.0003). The cap is a constructor parameter (default the constant), so tests can lower it. No time budget in Phase 6 (Phase 7 carry, as in Phase 5 WP13) | Phase 3 B8/R13; clarifies ADR 0002 "all pages". **Decision 3** |
| D6 | Batch guards shared | Move `ExpenseLister::read()`'s two checks into `ExpenseBatch::checkedAgainst(int $batchLimit): self` (throws `StorageFailure(QUERY_OPERATION, CORRUPT_ITEM)`). The lister and the builder both call it. A refactor commit with the existing lister tests unchanged and green. An `InvalidStartKey` on a key taken from our own batch is a server error (rethrown), as in the lister | DRY; Phase 3 B18 |
| D7 | Aggregation | `build(Principal, ReportMonth, CategoryResolver): MonthlyReport`. Calls `assertFor($owner)` first. Then, per expense, `resolver->resolve($expense->categoryId)` (null = Uncategorized: no category, deleted, or never the user's). Sum `amount->cents()` and count, as PHP `int`, per resolved id. Aggregates per batch; never holds the whole month. Max total = 10,000 × 1e8 = 1e12, below 2^53 and far below PHP_INT_MAX | ADR 0002 deleted-category rule; Phase 3 B1, B16 |
| D8 | Response shape | `200 {month, totalCents, expenseCount, complete, categories: [{categoryId, name, totalCents, expenseCount}]}`. `categoryId` is a uuid or `null`; `name` is the category's current name, or `null` for the Uncategorized row (the client labels it). **Only categories with ≥ 1 expense** in the month. **Order:** `totalCents` descending, then the resolver order (folded name, id), with Uncategorized last on a tie. **Empty month:** `200 {month, totalCents: 0, expenseCount: 0, complete: true, categories: []}`, never 404. No currency field (B2). Field-by-field mapping in `MonthlyReportResponse`. `no-store` comes from the kernel. No new log line (the request line already carries user id and path) | Phase 3 B2, B20, B23. **Decisions 4–7** |
| D9 | Cross-user isolation | Every read uses `PK = USER#<principal>` (`pageByDate` and the resolver both take the session `Principal`; nothing comes from the request but `month`). `SECURED_CALLS += 'getMonthlyReport' => ['GET', '/reports/monthly']` (no route id, so no `ID_ATTEMPTS` row; the 401 loop calls the bare path, and `RequireSession` answers 401 before the query is read). New test `testMonthlyReportsCoverOnlyTheCallersExpenses`: (a) as A, `2026-10` is the control (A food 1000+1002, A travel 1001+1003, Uncategorized 1004, total 5010, count 5); (b) as B, `2026-10` equals B's report for an empty month (fingerprint compare, apart from `month`) and contains none of A's ids or names; (c) as B, `2026-09` gives only B's 3 expenses (B's category 500+501, Uncategorized 502). The snapshot adds A's `2026-10` report body, so `assertUnchanged` also covers it | PLAN §5; Phase 3 cross-user suite |
| D10 | Contract (0.14.0) | Path `/reports/monthly`, `get`, `operationId: getMonthlyReport`, tag `reports` ("The signed-in user's spending summaries."), `security: sessionCookie`. Parameter `month`: required, `type: string`, `pattern: '^[0-9]{4}-(0[1-9]\|1[0-2])$'`. Responses: 200 (`CacheControlNoStore`, `ContentTypeOptionsNoSniff`, `MonthlyReport`), 400 `InvalidQuery`, 401 `SessionRequired`, 403, 405, 500, 503, the same set as `listExpenses` (`EveryOperationDocumentsErrorsTest`). Schemas (`additionalProperties: false`): `MonthlyReport` (`month` same pattern; `totalCents` integer 0..1e12; `expenseCount` 0..10000; `complete` boolean; `categories` array, maxItems 10000) and `CategoryTotal` (`categoryId` uuid\|null; `name` mirrors `Category.name`'s schema or null; `totalCents` 1..1e12; `expenseCount` 1..10000). Info description: "Phase 6 adds the monthly report." Then `make contract-gen` | ADR 0011 (additive) |
| D11 | Route | `new Route('GET', '/reports/monthly', static fn(): RequireSession => self::monthlyReport($services))`; the factory builds `MonthlyReportHandler(new MonthlyReportBuilder(self::expenses($services)), self::categories($services))`. Handler order: principal → query (400 before any read) → resolver for the same principal → build → response | Phase 3 handler conventions |
| D12 | Month picker | `MonthPicker.vue`: a `<form>` with labelled `<select>` Month (January–December, from `Intl.DateTimeFormat(APP_LOCALE, {month: 'long', timeZone: 'UTC'})`) and `<select>` Year (2000–2099, current year selected), a "Show" submit, and "Previous month" / "Next month" buttons (disabled only at `2000-01`/`2099-12`, using `aria-disabled` so focus is kept, as `AppHeader` does). It emits a valid `YYYY-MM` only. Works the same in every browser | Contradiction 8. **Decision 8** |
| D13 | Page and URL | Route `/summary` (name `summary`, `meta.title: 'Monthly summary'`, private). The month lives in the URL: `/summary?month=2026-10` (shareable; Back works, like the expense filters, Phase 4 C11). A missing or invalid `month` → `router.replace` with the local current month. Nav: Expenses, Categories, **Summary** (appended). `useMonthlyReport` composable: one request per month change; the previous request is aborted (`AbortSignal`) and a generation counter drops stale answers (Phase 4 R7). It aborts on unmount | Phase 4 C6, C11, decision 13. **Decisions 9, 10** |
| D14 | Money display | `formatTotalCents(cents)` in `lib/money.ts`: valid for integers 0..`REPORT_MAX_TOTAL_CENTS` (1e12, safe in JS), through the same exact-decimal-string `NumberFormat` path. Anything else gives `UNKNOWN_VALUE`. Row and grand totals use it; counts use `Intl.NumberFormat(APP_LOCALE)` | Contradiction 3; Phase 4 C8 |
| D15 | Table and a11y | `SummaryTable.vue`: `<table>` in a focusable `role="region"` wrapper (as `ExpenseTable`). `<caption>Spending by category, October 2026</caption>`. Columns Category (`th scope="row"`, name in `<bdi>` with the clip CSS; `null` → "Uncategorized"), Expenses (count), Total (right-aligned). `<tfoot>` row "Total" with the grand count and total. Each category cell links to the filtered list: `RouterLink` to `{name: 'expenses', query: filtersToQuery({from, to, category: id ?? 'none'})}`, only when the id passes `isUuidV4` or is null. `UNCATEGORIZED` is exported from one place (moved out of `useCategoryLabels`). Interpolation only, never `v-html` | Phase 4 C10, C15. **Decision 11** |
| D16 | States | **Loading:** "Loading summary…" with `aria-busy` on the region. **Error:** `FormAlert` with the Phase 4 C4 text for the kind, plus a Retry button (focus to the alert). **Empty:** "No expenses in October 2026." plus a link "Add an expense". **Incomplete** (`complete: false`): a visible notice above the table, "This month has more than 10,000 expenses. The totals cover the latest 10,000." **Loaded:** a polite live region announces "Showing October 2026" (fixed text, no amounts). The route's `h1` focus comes from the existing focus management; month changes keep focus on the control that was used | Phase 4 C4, C15 |
| D17 | Smoke script: interface and safety | `scripts/smoke.sh [--cross-user] <base-url>` (bash, `set -euo pipefail`, `set +x` first, `umask 077`). **Base URL:** exactly `https://<host>[:port]`, or `http://localhost[:port]`; no path, query, fragment or userinfo; `127.0.0.1` is refused with "use localhost" (cookie, local-dev.md). It **never** sends `X-Origin-Verify` (locally Vite adds it; on AWS CloudFront does), so no secret is needed. **Headers:** `Origin: <base-url>` and `X-Requested-With: XMLHttpRequest` on every API call, `Accept: application/json`. **curl:** `-sS --max-time 15 --max-redirs 0 --proto =https,http`, cookie jar in a `mktemp -d` directory (0700). Sensitive arguments (JSON bodies with the password, policy `fields`) go through `curl -K <file>` / `--data-binary @<file>` inside that directory, never through argv (`ps`). An EXIT trap removes the directory. **Output:** only `ok N - <check>` / `not ok N - <check> (HTTP <code>: <problem title>)`. It never prints bodies, headers, cookies, passwords, policies, signatures or presigned URLs. **Exit codes:** 0 all passed; 1 a check failed (fail fast, then cleanup); 2 usage or preflight error; 3 checks passed but cleanup failed. **Inputs:** fixed and hand-written only; one negative case per rule; no loops over payloads, no wordlists, no scans | Phase 2 §7; ADR 0005, 0012 §4.1; endpoint-security rule. **Decisions 12, 13** |
| D18 | Smoke: JSON and account | JSON fields are read with the **already-pinned `JQ_IMAGE`** (`docker run --rm -i --network none $JQ_IMAGE`), taken from the environment (make exports it) or from `make print-JQ_IMAGE`, refusing an unpinned value (as `check-image-pins.sh`). `SMOKE_JQ=jq` may name a local jq (e.g. a CI runner). **Account:** a fresh `smoke-<utc stamp>-<8 hex>@example.com` with a random 24-byte password from `/dev/urandom` (base64), never printed. Optional login mode with `SMOKE_EMAIL` + `SMOKE_PASSWORD` (env only). Register 429 → "registration is throttled (5 per hour per address): wait, restart the local stack, or set SMOKE_EMAIL/SMOKE_PASSWORD" | Contradiction 5; memory note "no jq on host". **Decisions 12, 13** |
| D19 | Smoke: checks, in order | 1 `GET /health` 200. 2 `GET /auth/me` without cookie → 401. 3 register (201) or login (200). 4 `POST /categories` **without** `X-Requested-With` → 403 (CSRF). 5 `GET /auth/me` → 200 with the email. 6 categories: create "Smoke food" and "Smoke travel" (201), list (2), rename with `version` (200), duplicate name (409). 7 expenses in fixed months of the fresh user: `2001-01-31`, `2001-02-01` (food), `2001-02-15` (travel), `2001-02-28` (none), `2001-03-01`; get one; PATCH amount with `version` (200), stale `version` (409); list with `from/to/category/q/limit=1` and follow `nextCursor`. 8 **summary:** `2001-02` exact per-category totals, the Uncategorized row, grand total, count 3, `complete: true`; `2001-03` only the boundary expense; `2001-04` empty; `month=2001-13` → 400; no `month` → 400. 9 delete "Smoke travel" → `2001-02` shows its expense under Uncategorized (the deleted-category rule end to end). 10 **receipts** (PLAN §10): valid 1×1 PNG: policy 201 → multipart POST to `url` with `--form-string` per field in order and the file last → 204 → poll `GET …/receipt` (1 s, then ×1.5 up to 5 s, cap 30 s) → `ready` with `thumbnailUrl`; GET `viewUrl` → 200 `image/png` (body discarded). Text bytes declared `image/png` → `failed`/`unsupported-type`. Upload of size + 1 against a policy → refused by S3 (400). `DELETE …/receipt` → 204, then GET → 404. Upload URL check before posting: `https:` or exactly `http://localhost:9000`, no userinfo, query or fragment; field names from the Phase 5 allow-list. 11 (`--cross-user` only) a second fresh user gets 404 on A's expense and receipt, an empty `2001-02` report and an empty list. 12 cleanup (also from the EXIT trap after a failure, best effort, for every id it created): delete expenses (removes receipt files), categories, logout → 204, `/auth/me` → 401. In login mode a non-empty `2001-02`/`2001-03` report at the start fails with "leftover smoke data" | PLAN §10, §12 row 8 |
| D20 | Smoke fixtures | The PNG is a base64 literal in `smoke.sh`, generated once by GD in the php tools container (`imagepng` of a 1×1 truecolor image) and pasted in, so it is hand-made and not downloaded. The invalid file is a short ASCII text. `backend/tests/Unit/Smoke/SmokeFixturesTest.php` extracts both literals from `scripts/smoke.sh` and runs `FileInspector`: PNG accepted, text `unsupported-type`. If a worker rule tightens, the smoke fixture fails in `make test-unit`, not on AWS | Phase 5 §3.1 fixture rule |
| D21 | `make smoke` and the DLQ | `smoke: .env check-env` → `scripts/check-stack-up.sh $(COMPOSE)` → `n0=$(scripts/smoke-local-dlq.sh)` → `scripts/smoke.sh $(SMOKE_FLAGS) http://localhost:5173` → `n1`; fails if `n1 > n0`. `smoke-local-dlq.sh` asks ElasticMQ on `127.0.0.1:9324` (`GetQueueAttributes ApproximateNumberOfMessages` of `receipts-dlq`; queue URL per Step 0 R3) with curl and prints the number. Never run with `make test-integration` at the same time (its part 1 stops `worker-runner`, so receipts would stall) | Contradiction 4. **Decision 16** |
| D22 | Logging and caching | No new log lines; totals, names and `month` are not logged by api-php (`month` is in the local nginx access log, harmless). `no-store` on every answer (kernel) | Phase 3 B23 |

**Front-end error mapping (`src/lib/summaryMessages.ts`, reusing `problem.ts` kinds)**

| Failure | UI |
|---|---|
| Client: invalid month in the URL | replaced by the current month, no request, no message |
| 400 | "The request was not accepted. Reload the page and try again." |
| 401 | global expiry handler (Phase 4 C5) |
| 403/429/503/5xx/network/timeout | Phase 4 C4 texts, with Retry |
| `complete: false` | the D16 notice |

## 3. Work packages

**One commit per WP, tests first, red run recorded in §7.** Order: WP1 → Step 0 → WP2 → WP3 → WP4 → WP5 → WP6 → WP7, one after another on one branch.

### 3.1 Rules every agent brief repeats verbatim
- **Make targets only, in pinned containers.** Never `npm`, `node`, `npx`, `php`, `composer` or `python` on the host. Host `curl` and `bash` are allowed only inside `scripts/smoke.sh` / `make smoke`.
- **Edit tool only.** No `sed -i`, `perl` or `python` rewrites of source files. Re-check any file an agent says it "recovered".
- **One make target at a time, in the foreground.** No background jobs, no `&`, no hand-written polling or `pgrep` loops. Never two `make test-integration` runs at once, and never `make smoke` while `make test-integration` runs. Before either: `make ps` / `scripts/check-stack-up.sh`; never trust "stack is up" from earlier notes.
- **Do not run `make -n test-integration`** (it runs the suite; Phase 5 WP9 note).
- **No new dependency** (Composer, npm, image, apt) without an owner decision.
- **Shared Docker host:** only the repo's make targets, `docker compose -p expense-tracker` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`) and `docker run --rm`. Never `prune`, `down -v` or `volume rm`.
- **Endpoint security:** never download, vendor or generate wordlists, payload or fuzzing corpora, malware samples or offensive tools; never scan ports (localhost included). Smoke and test inputs are hand-written fixed values.
- **No AWS calls**, no real credentials; only the fake `.env` pair. The smoke script never receives or prints `X-Origin-Verify`.
- **Protected config** (`frontend/eslint.config.ts` and the like) is never edited by an agent.
- **Ask before every push.** After a push, watch CI by run id and check `headSha` (`gh run watch` or `gh … --jq`; no host `jq`).

### 3.2 Model routing (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium, one brief per WP.
- **Review, one language reviewer per diff:** `ecc:php-reviewer` (WP2, WP3); `ecc:typescript-reviewer` (WP4); `ecc:vue-reviewer` (WP5); `ecc:code-reviewer` for the shell, Makefile and CI diff (WP6, WP7). All sonnet/medium.
- **Security:** `ecc:security-reviewer` opus/high on WP3 (over the WP2+WP3 diff: user input, DB queries, authorisation), WP6 (credentials, cookies, signed URLs, external calls), and the WP7 whole-feature pass. Round 2 on the fix hunks only.
- **Other:** docs `ecc:doc-updater` haiku/low, checked line by line; test-run summaries `general-purpose` haiku/low; build errors `ecc:build-error-resolver` sonnet/low, then opus/high.

### Step 0: Research & Reuse (before WP2)
Docs questions go to `general-purpose` (sonnet/medium, a URL per claim). Ran checks are run by the main session. GitHub search is read-only. Results go into a "Step 0 results" table.

| # | Question | Needed by |
|---|---|---|
| R1 | Current `<input type="month">` support in desktop Firefox and Safari (MDN, bug trackers); confirms D12 | WP5 |
| R2 | **Ran:** host `curl --version` (≥ 7.79) and the CI runner's version; the docs' register → `/auth/me` flow **through `http://localhost:5173`** (the Vite proxy) with a cookie jar keeps `__Host-session` | WP6 |
| R3 | **Ran:** ElasticMQ queue name/URL of the DLQ (`docker/elasticmq/elasticmq.conf`) and an unsigned `GetQueueAttributes` from the host on `127.0.0.1:9324` | WP6 |
| R4 | **Ran** (php tools container, `TemporaryTable`, throwaway): time to read 10,000 expenses of one month with `pageByDate` batches of 1,000 on DynamoDB Local. Over ~2 s → lower `MAX_EXPENSES` to 5,000 (decision 3) | WP2 |
| R5 | **Ran:** `docker run --rm -i --network none $JQ_IMAGE` reads stdin and prints ordered `to_entries` of an object | WP6 |
| R6 | Read-only GitHub code search for curl smoke-test patterns (cookie jar plus CSRF header, `-K` config to keep secrets out of argv) to adopt rather than invent | WP6 |
| R7 | **Ran:** 1×1 PNG from GD in the php tools image passes `FileInspector` and reaches `ready` through the local pipeline | WP6 |

### WP1: Plan (S)
- **Files:** this plan; PLAN §12 row 6 link. **Agent:** main session. **Commit:** `docs: add phase 6 implementation plan`.

### WP2: Report values and builder (S–M)
- **Files:**
  - `backend/src/Reports/{ReportMonth,MonthlyReport,CategoryTotal,MonthlyReportBuilder}.php`;
  - `backend/src/Expenses/ExpenseBatch.php` (`checkedAgainst`) and `Listing/ExpenseLister.php` (calls it; D6, done as a separate refactor commit first, existing tests unchanged);
  - tests `backend/tests/Unit/Reports/*`.
- **Tests first** (`FakeExpenseRangeReader`, `ExpenseBuilder`, `CountingCategoryLister`):
  - `ReportMonth` accepts and refuses every D4 value; `firstDay`/`lastDay` for 2024-02 (29), 2023-02 (28), 2001-12 and 2099-12; `2000-01` accepted.
  - The builder:
    - reads `[firstDay, lastDay]` only;
    - `assertFor` is called before any read (a foreign resolver gives `LogicException` and no read);
    - categories are summed exactly and Uncategorized merges none, deleted and foreign ids;
    - zero-spend categories are omitted;
    - the D8 order, including ties;
    - an empty month;
    - it follows `lastKey` across batches;
    - the cap: N > cap gives `complete: false` with exactly cap items counted;
    - exactly cap with a `lastKey` and an empty probe gives `complete: true`;
    - the batch limit never exceeds `cap − examined`;
    - a corrupt batch (too many items, empty with `lastKey`) gives `StorageFailure`;
    - a resolver `StorageFailure` is not caught;
    - the resolver reads at most once.
- **Review:** php-reviewer. **Commits:** `refactor: share the expense batch guards`, then `feat: add the monthly report builder`.

### WP3: Contract, endpoint and isolation (S–M), security-critical
- **Files:**
  - `contract/openapi.yaml` 0.14.0 (D10) and `frontend/src/api/schema.gen.ts` (`make contract-gen`);
  - `backend/src/Reports/Http/{MonthlyReportHandler,MonthlyReportQuery,MonthlyReportResponse}.php`;
  - `Expenses/Listing/InvalidQuery.php` (`MONTH`);
  - `Api/Routes.php`;
  - `tests/Integration/Reports/MonthlyReportApiTest.php`;
  - `tests/Integration/Isolation/CrossUserIsolationApiTest.php` (D9).
- **Tests first:** `RoutesMatchContractTest`, `ProtectedRoutesMatchContractTest` and `CrossUserCoverageTest` go red. Then:
  - unit: query parsing order (400 before any read; spy reader at zero calls); response mapping field by field; null name for Uncategorized;
  - integration over nginx, contract-validated:
    - an empty month;
    - month boundaries (Jan 31 / Feb 1 / Feb 28 / Mar 1; leap 2024-02-29);
    - a re-dated expense moves between months;
    - a renamed category shows its new name;
    - a deleted category's expenses go to Uncategorized;
    - every D4 400 case;
    - 401 without a session;
    - with a cap lowered through the builder constructor: 30 seeded expenses and cap 25 give `complete: false`; exactly 25 gives `complete: true`;
  - the cross-user rows and `testMonthlyReportsCoverOnlyTheCallersExpenses`.
- **Review:** php-reviewer; **security-reviewer** (WP2+WP3 diff). **Commit:** `feat: add the monthly report endpoint`.

### WP4: Front-end API and helpers (S)
- **Files:**
  - `frontend/src/api/reports.ts` (`getMonthlyReport(month, signal)`; an invalid month resolves `{ok: false, error: {kind: 'invalid-request'}}` with no request, as `INVALID_ID`);
  - `src/api/types.ts` (`MonthlyReport`, `CategoryTotal` aliases);
  - `src/lib/months.ts` (`isIsoMonth`, `currentMonthLocal(now)`, `shiftMonth(month, ±1)` clamped, `formatMonth` (UTC formatter), `monthRange(month) → {from, to}`);
  - `src/lib/money.ts` (`formatTotalCents`);
  - `src/lib/summaryMessages.ts`;
  - `src/config.ts` (`MONTH_MIN`, `MONTH_MAX`, `REPORT_MAX_TOTAL_CENTS`, `REPORT_MAX_EXPENSES`);
  - `UNCATEGORIZED` exported once.
- **Tests first:**
  - the request path, query and signal;
  - `isIsoMonth` edge cases (`2026-00`, `2026-13`, `1999-12`, `2100-01`, `2026-1`, full-width digits);
  - `currentMonthLocal` at 23:59 local on the last day of a month;
  - `shiftMonth` across a year end and clamped at both ends;
  - `monthRange` for leap February;
  - `formatTotalCents(0)` = "€0.00", `1e12`, `1e12 + 1` → `—`, `1.5` → `—`;
  - every message.
- **Review:** typescript-reviewer. **Commit:** `feat: add the monthly report api client and month helpers`.

### WP5: Summary page and nav (S–M)
- **Files:**
  - `frontend/src/composables/useMonthlyReport.ts`;
  - `src/components/{MonthPicker,SummaryTable}.vue`;
  - `src/views/SummaryView.vue`;
  - `src/router/index.ts`;
  - `src/components/AppHeader.vue`;
  - specs.
- **Tests first:**
  - the route is private (signed out → login with `redirect`);
  - a missing or invalid `?month` → replace with the current month;
  - picker emits only valid months; Previous/Next at the ends; selects are labelled;
  - each D16 state;
  - abort on month change and on unmount; a stale answer is dropped;
  - caption and `tfoot` totals; names in `<bdi>`; a hostile name renders as text;
  - drill-down link query (uuid and `none`; an invalid id gives no link);
  - live-region text is fixed;
  - the nav has "Summary" with `aria-current` on `/summary`.
- **Review:** vue-reviewer (typescript-reviewer for the composable if it is a separate diff over ~30 lines). **Commit:** `feat: add the monthly summary page and nav link`.

### WP6: Smoke script and `make smoke` (M), security-critical
- **Files:**
  - `scripts/smoke.sh` (D17–D20);
  - `scripts/smoke-local-dlq.sh`;
  - `scripts/tests/smoke.test.sh` plus `scripts/tests/fixtures/smoke/` (a stub `curl` and `docker` on `PATH` replaying canned answers);
  - `backend/tests/Unit/Smoke/SmokeFixturesTest.php`;
  - `Makefile` (`smoke` target, `.PHONY`, help text).
- **Tests first (`make test-shell`):**
  - usage and exit 2 for no argument, `http://example.com`, a path, userinfo, `127.0.0.1`, `javascript:`;
  - xtrace forced off;
  - a stubbed 500 at a middle step → exit 1, `not ok` names the step, and the cleanup DELETEs ran;
  - the output and the stubbed argv log never contain the password, the cookie value, the `Policy` or `X-Amz-Signature` values, or the view URL (marker grep, ≥ 10-character markers so the check is not vacuous);
  - a hostile upload URL (`http://evil.example`, with a query, an unknown field name) is refused before any POST;
  - register 429 → the D18 message, exit 1.
- **Then live:** `make smoke` green twice in a row on `make up`, never concurrently with integration. One deliberate mutation shown to fail (e.g. a wrong expected total), then reverted.
- **Review:** code-reviewer (shell/Makefile); **security-reviewer**. **Commit:** `feat: add the curl smoke test and make smoke`.

### WP7: CI step, whole-feature pass, docs, walkthrough (S)
- **CI:** `.github/workflows/ci.yml` `integration` job: step "Smoke test (curl) against the local stack" `run: make smoke`, after the integration tests and before the log dump. No new job, so no ruleset change (G4 not needed); confirm with `make lint-workflows`.
- **`make test`:** append `$(MAKE) --no-print-directory smoke` after `test-integration` (decision 16).
- **Security pass:** security-reviewer (opus/high) over `backend/src/Reports`, the handler, `frontend/src/{api/reports.ts,views/SummaryView.vue,components/SummaryTable.vue}` and `scripts/smoke*.sh`, against §4.
- **Docs** (doc-updater haiku/low):
  - `docs/local-dev.md`: summary curl example (via 8080 with `X-Origin-Verify`, as the existing sections) and "Smoke test" (what it checks, base URL rules, throttle note, login mode, exit codes, "never with test-integration");
  - ADR 0002 "Implemented in Phase 6" (D1, D5, D7); ADR 0011 note (0.14.0, `reports` tag);
  - README plan list; PLAN §12 row 6 → Done.
- **Before ready:** `/update-docs`, `/code-review`, `/verification-loop`, `/ecc:cost-report` (compare with Phase 5).
- **Commit:** `docs: phase 6 summary walkthrough, smoke docs and adr notes`.

**Dependencies:** WP2 → WP3 → WP4 → WP5; WP3 → WP6; everything → WP7.

## 4. Threats and how each is handled

| Threat | Control | Test |
|---|---|---|
| Reading another user's totals | `PK` from session in `pageByDate` and the resolver; `assertFor`; only `month` taken from the request | WP2 unit; WP3 D9 isolation test; smoke `--cross-user` |
| Deleted or foreign category name leaking | Names come only from the resolver (the caller's live categories); unresolved ids become a `null` row | WP2, WP3 |
| Cost or latency amplification by a huge month | `MAX_EXPENSES` cap, 1,000-item batches, one probe; `complete: false` | WP2 cap tests; WP3 lowered-cap integration |
| Query injection or odd values | Strict ASCII regex, `checkdate`-backed range, unknown-parameter 400, value never echoed or logged | WP3 |
| Wrong totals (float, overflow) | Integer cents throughout; max 1e12 in PHP int and JS safe range; `formatTotalCents` on exact decimal strings | WP2, WP4 |
| XSS through category names | Interpolation in `<bdi>`, lint bans `v-html`; drill-down ids checked with `isUuidV4` | WP5 |
| Caching of personal totals | Kernel `no-store`; CloudFront `/api/*` caching disabled (ADR 0005) | Contract headers |
| Smoke leaking secrets (password, session cookie, policy, presigned URLs) into the terminal, CI logs or `ps` | No `-v`/`-i`; only check names printed; secrets via `-K`/`@file` in a 0700 temp directory removed by trap; password never echoed | WP6 marker tests |
| Smoke pointed at an unintended host, or credentials over plain http | Base URL allow-list (https, or http only for localhost); `--max-redirs 0`; `--proto =https,http`; upload URL allow-list | WP6 |
| Smoke used as an attack tool | Fixed hand-written inputs, one negative per rule, no iteration over payloads, no scanning; it only touches its own fresh user | Review |
| Smoke leaving data | Cleanup in the EXIT trap; fresh user; exit 3 on a failed cleanup | WP6; contradiction 6 for the user record |
| Origin secret exposure | The smoke script never sends or needs `X-Origin-Verify` | WP6 review |

## 5. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| PHP unit | `ReportMonth`, builder, batch guard, query, response, smoke fixtures | `backend/tests/Unit/{Reports,Smoke}` | `make test-unit` |
| Contract | Route parity, protected routes, documented errors, cross-user coverage | `backend/tests/Contract` | `make test-contract` |
| Integration | Endpoint over nginx (contract-validated), boundaries, deleted or renamed categories, lowered cap, isolation | `tests/Integration/{Reports,Isolation}` | `make test-integration` (one at a time) |
| Front end | API, months, money, messages, composable, picker, table, view, nav, router; 100% coverage | `frontend/src/**/__tests__` | `make frontend-test` |
| Shell | Smoke guards, hygiene, exit codes, cleanup on failure | `scripts/tests/smoke.test.sh` | `make test-shell` |
| End to end | Every feature over HTTP against Compose; local DLQ delta | `scripts/smoke.sh` | `make smoke` (locally and in CI) |
| Manual | G3 walkthrough | `docs/local-dev.md` | owner |

TDD throughout: red run recorded per WP. 80% minimum on `backend/src` (target ≥ 95% on `src/Reports`), 100% on the front end.

## 6. Branch, PR, CI and gates
- **One PR**, draft after WP3, ready after WP7, squash-merged. **Ask before every push**; watch CI by run id and check `headSha`.
- **G1:** the owner approves this plan, including §10.
- **G2:** Step 0 results recorded. If R4 changes the cap, or the owner reads D5 as a change to ADR 0002, ADR 0013 is accepted before WP2.
- **G3 walkthrough (Chrome and Firefox, http://localhost:5173):**
  - Summary from the nav shows the current month;
  - Previous/Next and the selects change the URL, and Back returns;
  - an empty month;
  - a month with categorised, Uncategorized and deleted-category expenses (the totals match the list);
  - a category link opens the filtered list;
  - a hand-edited `?month=2026-13` falls back;
  - keyboard only; zoom 200%;
  - run `make smoke` once and read its output.
- **G4:** not expected (a step, not a job).
- **G5:** no deletions planned.
- **G6:** the owner merges.
- **AWS:** none.

## 7. Carried items and review notes
- **Carried in:** Phase 3 §7.2 and R13 (D5, B24 reuse); Phase 2 §7 smoke headers and jar (D17); Phase 4 decision 13 and `formatCents` reuse (D14, contradiction 3).
- **Carried out, Phase 7:**
  - the API Lambda timeout must cover the D5 worst case (11 queries) under degraded DynamoDB; take a deadline from the Lambda context, as for the Phase 5 cleanup;
  - consider a per-route throttle on `getMonthlyReport`;
  - the IAM `Query` on `/index/LSI1` already listed;
  - the access-log format (B23; `month` is harmless).
- **Carried out, Phase 8:**
  - run `scripts/smoke.sh --cross-user https://<distribution>`;
  - add the two ADR 0012 checks (direct API Gateway URL refused; forged `CloudFront-Viewer-Address` ignored) as an optional `--api-gateway-url` mode or a separate step;
  - AWS DLQ depth before and after, with the owner's SSO credentials, in `make deploy`'s smoke step;
  - decide leftover smoke users (contradiction 6);
  - record report latency on Lambda.
- **Carried out, later:** L1 a Playwright summary journey; L2 `deploy.yml` calls the smoke script; L5 chart, colours, `make seed`.
- **§7.3 Review notes:** filled per WP (red run, findings, fixes, round 2).

## 8. Risks

| # | Risk | Mitigation | WP |
|---|---|---|---|
| R1 | A partial summary is mistaken for a full one | `complete` flag, visible notice, cap far above real use | WP2, WP5 |
| R2 | Smoke flakiness (receipt timing, shared queue) | Bounded backoff with a 30 s cap; never with test-integration; `check-stack-up.sh` first | WP6 |
| R3 | The register throttle blocks repeated smoke runs | One user per run, a clear 429 message, login mode, cross-user opt-in | WP6 |
| R4 | curl or cookie behaviour differs between host, CI and AWS | Step 0 R2; https on AWS; `localhost` only locally | WP6 |
| R5 | Smoke secrets reach CI logs | Output contract plus marker tests; CI step shows only check names | WP6, WP7 |
| R6 | The shared guard refactor changes lister behaviour | Separate commit, lister tests unchanged and green | WP2 |
| R7 | Scope creep (chart, share %, export) | §10 decision 22; not-in-scope list | all |

## 9. Lessons applied
Tests first with the red run recorded; Edit tool only (Phase 5 WP6 slips); no hand-written wait loops in agent briefs; one integration-class run at a time, and the stack verified before it; hand-written fixtures only, no wordlists or scans (2026-10-19 alert); haiku docs checked line by line; protected config is owner-only; CI watched by run id; `/ecc:cost-report` at the end.

## 10. Owner decisions (recommended defaults)

1. **`month` required, no server default** (the client sends its local month)? **Recommended: yes.**
2. **Month = calendar month of the expense date, no time zone, 2000-01..2099-12?** **Recommended: yes.**
3. **Bounding a large month:** cap at 10,000 expenses (1,000 per query plus one probe), answering `complete: false` with the totals of the newest 10,000 and a visible notice? **Recommended: yes.** Alternatives: 5,000 (if Step 0 R4 is slow); refuse the month with an error (a new problem status); no cap (rely on timeouts, open to cost amplification).
4. **Category name in each row** (a self-contained answer, same snapshot as the totals)? **Recommended: yes.** Alternative: ids only, labelled from the categories store.
5. **Omit categories with no spending in the month?** **Recommended: yes.** Alternative: list all categories with 0.
6. **Order:** total descending, then name, Uncategorized last on ties? **Recommended: yes.** Alternative: alphabetical, Uncategorized last.
7. **One Uncategorized row** for no category and for deleted categories (ADR 0002)? **Recommended: yes.**
8. **Month picker:** Previous/Next plus month and year selects? **Recommended: yes.** Alternative: `<input type="month">` (a plain text box in Firefox and Safari).
9. **Month in the URL** (`/summary?month=YYYY-MM`)? **Recommended: yes.**
10. **Nav order:** Expenses, Categories, Summary? **Recommended: yes.** Alternative: Expenses, Summary, Categories.
11. **Category rows link to the filtered expense list?** **Recommended: yes.** Alternative: no links.
12. **Smoke JSON parsing** through the already-pinned `JQ_IMAGE` (`--network none`), with `SMOKE_JQ` override; no new dependency? **Recommended: yes.** Alternatives: install jq on the host; write the smoke in PHP (not "curl").
13. **Smoke account:** a fresh random user per run, password never printed, optional `SMOKE_EMAIL`/`SMOKE_PASSWORD` login mode? **Recommended: yes.**
14. **Cross-user check in the smoke:** opt-in `--cross-user` (2 registrations per run), on in the Phase 8 runbook? **Recommended: yes.** Alternative: always on (only 2 local runs per hour).
15. **Receipt checks in the smoke:** valid PNG → `ready` (+ view URL fetch), text-as-PNG → `failed`, size + 1 refused by S3, delete; no PDF (integration covers it)? **Recommended: yes.**
16. **`make smoke`** runs against `http://localhost:5173` with a local DLQ-delta check; a CI step in the `integration` job; appended to `make test`? **Recommended: yes.**
17. **The base URL allow-list:** https anywhere, http only for `localhost`? **Recommended: yes.**
18. **Smoke inputs and output:** fixed hand-written inputs, check names only, exit codes 0/1/2/3? **Recommended: yes.**
19. **Leftover smoke user on AWS** (no account-delete endpoint): accept for now and decide in Phase 8? **Recommended: yes.**
20. **New dependencies:** none (no Composer, npm or image)? **Recommended: yes.**
21. **Contract 0.14.0** (additive; the planning brief's 0.12.0 → 0.13.0 was outdated)? **Recommended: yes.**
22. **Share-of-total column:** not in Phase 6 (the chart in L5 shows proportions)? **Recommended: yes.**
23. **Security review scope:** opus/high on WP3 (with the WP2 diff), WP6 and the WP7 pass; WP2, WP4, WP5 use language reviewers? **Recommended: yes.** Alternative: also WP5 (one more opus run).
24. **PR shape:** one PR, draft after WP3, squash-merged? **Recommended: yes.**
