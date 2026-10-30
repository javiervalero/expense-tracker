# Phase 5: Receipts. Implementation plan

| | |
|---|---|
| Status | **G1 approved 2026-10-29**: the owner approved this plan with every section 10 default. **G2 done 2026-10-29**: Step 0 results recorded; no ADR amendment needed (decision 15 is (a), decision 21 keeps the timings). **WP2 done 2026-10-29.** **WP3 done 2026-10-29.** **WP4 done 2026-10-30.** **WP5 done 2026-10-30** (exact-size policy: owner decision, C5). **WP6 done 2026-10-30.** Next: WP7 |
| Phase | 5 (PLAN §12, essential track), size L |
| Branch | `feat/phase-5-receipts`, from `main` at ec1b8e0 (Phase 4 merged in PR #7) |
| Inputs | BRIEF; PLAN §5 (Receipts API row), §6 (Receipt row, cleanup rules), §7, §9 (Receipts), §10, §12–13; ADR 0002, 0005, 0006 (key), 0007 (incl. the Phase 0 spike result, the "Presigned GET and the Host header" note and R13), 0008 ("Implemented in Phase 4"), 0011, 0012 §2–3 and the GD results; Phase 3 plan (back-end WP style, cross-user suite); Phase 4 plan (C2–C4, C16, whole-PR notes L6/L16) |
| Drafted by | `ecc:planner` (opus/high). The main session still has to check it against the code |
| AWS | **None.** No AWS calls, no credentials, $0. Everything runs against the Compose emulators. IAM, bucket encryption, the real notification and CSP headers belong to Phase 7 |
| Back end + front end | Both. Contract 0.11.0 → 0.12.0 (additive) |

**Scope (PLAN §12 row 5):** signed uploads; emulator → relay → ElasticMQ → worker; thumbnails bucket; conditional status updates and cleanup; TTL on pending; status polling; preview (image thumbnail, PDF icon). Front end: upload in the expense form, with progress, status and preview.

**Not in scope:** PDF thumbnails (PLAN §7, ADR 0006), OCR, a receipt marker in the expense list (decision 11), Terraform/IAM/CSP headers (Phase 7), smoke script (Phase 6), Playwright (L1).

## Contradictions found while planning

Each item ends with where it is resolved. "Decision N" means §10 (you choose); a C-row in §2 or a WP means the plan fixes it with no real alternative.

- (1) ADR 0008's target CSP (`connect-src 'self'; img-src 'self'`) and ADR 0005's "one origin, no CORS" do not fit ADR 0006's direct browser→S3 POST and signed previews, so the S3 hosts have to be added. **→ Resolved by C20** (Phase 7 CSP target with the two bucket hosts, recorded in the ADR 0008 note in WP13; nothing changes locally). Not a §10 decision: direct upload to S3 needs it.
- (2) ADR 0006 has the API delete S3 objects, but `api-php` sits only on `api-internal`, which cannot reach `s3`; ADR 0007 R13 says to revisit if a non-AWS image joins that network. **→ Decision 15** (recommended: (a) an unpublished nginx listener; (b)/(c) need an ADR 0007 amendment at G2).
- (3) ADR 0006 says "delete expense + receipt in one transaction, then the objects", but `ExpenseRepository::delete` uses TransactWriteItems, which cannot return the old `objectKey`. **→ Resolved by C8, decision 16** (prefix cleanup of both buckets after the transaction).
- (4) ADR 0006's replace uses `PutItem ReturnValues=ALL_OLD`, but that cannot also check that the expense still exists, so a replace racing an expense delete leaves an orphan record. **→ Resolved by C4** (one transaction: `ConditionCheck` on the expense + conditional `Put` of the receipt; 404 / 409). Not a §10 decision: it is a correctness fix; decision 18 covers only the status code.
- (5) The Phase 4 lint bans `XMLHttpRequest` everywhere, yet upload progress needs it (fetch has no upload progress). **→ Resolved by C17, decisions 13 and 14** (an owner-added ESLint override for `src/api/upload.ts` only).
- (6) The bootstrap CORS rule allows only `http://localhost:5173`, while `APP_ORIGINS` also allows `http://127.0.0.1:5173`. **→ No change needed.** Signing in already works only on `http://localhost:5173` (the `__Host-` `Secure` cookie, `docs/local-dev.md` sign-in note), and an upload needs a signed-in user, so `127.0.0.1` can never reach the S3 POST. WP13 repeats the "use localhost" note in the receipt walkthrough; Phase 7 sets the real CORS origin (§7 carry list).
- (7) `ReceiptPipeTest` and the Makefile check for the Phase 0 "receipt received" log line, using a non-UUID key and junk bytes that the real worker will ignore. **→ Resolved in WP9**: the test seeds a pending receipt record and uploads under its valid key, so it checks the real worker path (`receipt ready` / `receipt failed`), not a log line for ignored keys.
- (8) PLAN §8 puts receipt upload in "the expense form", but the API needs an existing expense id, so the new-expense form cannot upload. **→ Resolved by C16, decision 10** (upload on the edit page only).

## 0. What exists vs what Phase 5 builds

| Area | Exists (read from the code) | Phase 5 builds |
|---|---|---|
| Worker plumbing | `Queue/SqsBatchProcessor` (partial batch failures, whole-batch validation, error logging without foreign messages); `Receipts/S3EventParser` (TestEvent → `[]`, `urldecode`, ignores `eventSource`); `S3ObjectRef`; `InvalidS3Event`; `Worker/WorkerFactory` (the one swap point); `entrypoints/lambda/worker.php` | `ReceiptProcessor` replaces the placeholder `ReceivedReceiptLogger` (deleted at G5); file inspection, GD thumbnail, conditional updates, cleanup |
| Local pipeline | silo (presigned POST enforced, 8/8 cases), `receipts` + `receipt-thumbnails` buckets, CORS (POST/GET/HEAD from `S3_CORS_ALLOWED_ORIGIN`), notification `receipts/` → relay → ElasticMQ (redrive 3, visibility 10 s) → `worker-runner` (8 s timeout) → `worker` (Bref php-85 + GD, RIE, no env, no credentials) | Worker env (DynamoDB, S3, buckets, fake keys); API → S3 path (decision 15) |
| Data | `ExpenseKeys::receipt()` (`RCPT#<expenseId>`); `ExpenseRepository::delete` deletes `EXP#` + `RCPT#` in one transaction (no object cleanup); `TableLayout::EXPIRES_AT`; TTL on `expiresAt` (`infra/dynamodb/table.json`) | `ReceiptRepository`; `ItemStore` gains a delete that returns the old item (`ReturnValues=ALL_OLD`) |
| S3 in `src/` | **None.** No S3 client in `backend/src/Infrastructure` (only in `docker/local-aws/src/Bootstrap` and test support). `aws/aws-sdk-php` is already a dependency (`PostObjectV4`, presigners) | `Infrastructure/S3/*` |
| API | No receipt routes. `CrossUserCoverageTest` already says "Phase 5's receipt routes are caught here" | 3 routes, contract, cross-user rows |
| Tests | `ReceiptPipeTest` (junk upload consumed; poison message → DLQ in 3 receives), `S3ToQueueTest`, `RelayHttpTest`, `RieInvocationTest`, `PresignedPostUpload` support, Makefile log-marker checks | Lifecycle, race, cleanup and cross-user integration tests; updated pipe markers |
| Front end | `http.ts` (same-origin, `redirect:'error'`, safe-path allow-list, CSRF header); `problem.ts`; `ExpenseFormView.vue` (~420 lines, no AbortSignal: L6/L16); lint bans `fetch` outside `http.ts`/`health.ts` and `XMLHttpRequest` everywhere | `api/receipts.ts`, `api/upload.ts` (XHR, the only place allowed), `useReceipt` composable, `ReceiptPanel` |

## 1. Goal and definition of done

**Goal:** on the edit page of their own expense, a user attaches a JPEG, PNG, WebP or PDF of up to 10 MiB. The file goes straight to S3 with visible progress. The page shows "Processing", then a thumbnail (images) or a PDF icon, and opens the original through a short-lived link. Replace and delete work. Invalid, oversized and disguised files are rejected and nothing is left behind. No user can reach another user's receipt.

**Done means:**
- **Back end:** the 3 endpoints match contract 0.12.0 (every integration response is validated against it). The worker handles every case in ADR 0007's list "How the SQS path is tested", unit-tested with both AWS and silo fixtures.
- **Integration (Compose, real relay + runner + worker):**
  - a valid image becomes `ready` with a thumbnail;
  - a valid PDF becomes `ready` with no thumbnail;
  - a disguised file becomes `failed` and its object is deleted;
  - an upload whose record was deleted, replaced or expired gets its objects removed;
  - redelivery is a no-op;
  - a malformed message goes to the DLQ after 3 receives;
  - expense delete removes the objects;
  - cross-user calls get 404.
- **Front end:** `make frontend-lint frontend-test frontend-build check-bundle-csp check-bundle-secrets frontend-audit` all pass, with **100% coverage kept**.
- **Back end checks:** `make lint test-unit test-contract test-local-aws`, then `make test-conformance` and `make test-integration` (one at a time), all pass. 80% bar on `src/`; target ≥ 95% on `src/Receipts`.
- **G3 walkthrough** by the owner (§6).
- **Docs:**
  - ADR 0006/0007/0008 get "Implemented in Phase 5" notes (CSP target with S3 hosts, the network change, the runner timing if changed);
  - `docs/local-dev.md` gets the receipt walkthrough;
  - PLAN §12 row 5 → Done.
- **Safety:** no new Composer/npm dependency (decision 20); CI keeps the same 7 required jobs.

## 2. Design decisions (recommended defaults)

| # | Topic | Recommendation | Source / ADR impact |
|---|---|---|---|
| C1 | Object keys | Original: `receipts/<userId>/<expenseId>/<receiptId>`. Thumbnail: `<userId>/<expenseId>/<receiptId>.jpg`. The user id comes from the session `Principal`; the expense id is a validated route UUID; the receipt id is a fresh server UUID v4. **The client's filename is never used or stored.** `ReceiptObjectKey::parse()` accepts only `^receipts/<uuid>/<uuid>/<uuid>$` (lower-case canonical, `Uuid::isValid`), after `urldecode` | ADR 0006 Storage; PLAN §6 |
| C2 | Record | `PK USER#<u>`, `SK RCPT#<e>`. Fields: `receiptId`, `objectKey`, `status` (`pending`/`ready`/`failed`), `contentType` (declared), `size` (declared), `createdAt`, `expiresAt` (pending: +24 h; failed: +7 d; removed on ready), `failureReason` (failed only), `processedAt`. Hydrated field by field like `ExpenseRepository` (a corrupt item is a `StorageFailure`) | ADR 0002, 0006 |
| C3 | TTL is not correctness | Reads treat `pending`/`failed` with `expiresAt ≤ now` as "no receipt" (`ReceiptRepository::find` returns null), so behaviour never depends on when DynamoDB actually deletes (up to days late; DynamoDB Local was never shown to expire, ADR 0007 Phase 1 note). The worker's conditional update also compares `expiresAt > :now`, so an expired pending record cannot become ready | ADR 0006 Lifecycle row 1 |
| C4 | `POST /expenses/{id}/receipt/upload` | Body `{contentType, size}`: `contentType` ∈ the 4 types, `size` an integer from 1 to `MAX_BYTES`. Otherwise 422 at `/contentType` or `/size`. Steps: (1) `GetItem` the current receipt; (2) one `TransactWriteItems`: `ConditionCheck EXP#<e> attribute_exists(PK)` plus `Put RCPT#<e>` with condition "unchanged since step 1" (`attribute_not_exists(PK)` or `receiptId = :old`); (3) only after it commits, delete the old original and thumbnail if there was one; (4) return the policy. The expense condition fails → 404. The receipt condition fails → 409 "changed elsewhere, reload" (one retry is not worth it). Answer: **201** with `Location: /api/v1/expenses/{id}/receipt` and `{receiptId, url, fields, expiresAt, maxBytes}`. `Cache-Control: no-store` | ADR 0006 Flow 1 and replace row; fixes contradiction 4. Decision 18 |
| C5 | Upload policy | `PostObjectV4`, signed against the **public** endpoint (locally `S3_PUBLIC_ENDPOINT=http://localhost:9000`, path style; ADR 0007 Host-header note). No network call. Conditions: `bucket` eq, `key` eq, `Content-Type` eq the declared type, `content-length-range [size, size]` (exactly the declared size, 1 ≤ size ≤ `MAX_BYTES`; owner decision 2026-10-30, WP5 security review), 5-minute expiry. No `acl`, no `success_action_redirect`; S3 rejects unsigned extra fields (spike case 6) | ADR 0006; spike table |
| C6 | `GET /expenses/{id}/receipt` | 404 when the expense is not the caller's, or there is no live receipt (C3). 200 `{receiptId, status, contentType, size, createdAt, failureReason, viewUrl, thumbnailUrl, urlsExpireAt}`. The URLs are set **only when `ready`**: `viewUrl` = presigned GET of the original with `ResponseContentType` = the type the worker verified and `ResponseContentDisposition: inline; filename="receipt.<ext>"`; `thumbnailUrl` for images only. Lifetime 5 min (decision 6). `Cache-Control: no-store` | ADR 0006 Flow 5; PLAN §9 |
| C7 | `DELETE /expenses/{id}/receipt` | `DeleteItem RCPT#<e>` with `ReturnValues=ALL_OLD`, then `DeleteObjects` of that original and thumbnail. 204; 404 when nothing was there (decision 25) | ADR 0006 delete row |
| C8 | Expense delete cleanup | Keep the existing transaction. Afterwards, list and delete the prefixes `receipts/<u>/<e>/` (receipts bucket) and `<u>/<e>/` (thumbnails), using `ListObjectsV2` + `DeleteObjects`. That needs no old key, and it also catches strays. Safe because no receipt can be created once the expense is gone (C4 ConditionCheck), and the ids are never reused | ADR 0006 expense row; fixes contradiction 3. Decision 16 |
| C9 | S3 failure after the record is gone | SDK retries (default 3), then an `ERROR` log line `receipt cleanup failed` with bucket and keys (never URLs or signatures). The HTTP answer is still the success code: the user's intent (record gone) is done. Phase 7 adds a metric filter and an alarm on that message | ADR 0006 last row |
| C10 | Worker algorithm per S3 record | (1) the bucket must equal `RECEIPTS_BUCKET` and the key must parse (C1); otherwise `info` log and skip (success). (2) `GetItem RCPT#<e>` (strongly consistent); if it is missing, has another `objectKey`, or is not `pending`, go to (7). (3) `GetObject`: `NoSuchKey` → re-read; if the record is still pending with this key, throw (retry/DLQ), else (7). Also refuse `ContentLength > MAX_BYTES`. (4) Inspect (C11). (5) Images: write the thumbnail (C12). (6) `UpdateItem` `SET status=ready, contentType=:detected, processedAt REMOVE expiresAt, failureReason`, condition `objectKey = :key AND #status = :pending AND expiresAt > :now`. Invalid file: delete the original, then `status=failed, failureReason, expiresAt=now+7d` with the same condition. (7) Condition failed or not ours: re-read. Same key and already `ready`/`failed` → duplicate, do nothing. Missing, different key, or expired → delete the original and the thumbnail (idempotent deletes). Any S3 or DynamoDB exception is thrown, so the record lands in `batchItemFailures` (retry, then DLQ after 3). **An invalid file is never a failure** (no DLQ) | ADR 0006 Flow 4.1–4.6 |
| C11 | File inspection | Magic bytes at offset 0: JPEG `FF D8 FF`; PNG the 8-byte signature; WebP `RIFF????WEBP`; PDF `%PDF-`. The detected type must **equal the declared** `Content-Type`, else `unsupported-type`. Images: `getimagesizefromstring` (reads the header, does not decode) must agree on the type; width and height between 1 and 12,000, and width × height ≤ 40,000,000 (else `too-large`); only then `imagecreatefromstring` inside an error handler that turns GD warnings into `unreadable`. Anything else (SVG, HTML, GIF, HEIC, ZIP, text) is `unsupported-type`. PDFs are not parsed (magic check only); they are served with a forced type from the S3 origin (C14) | PLAN §7, §13 risk; ADR 0006 4.2 |
| C12 | Thumbnail | GD: fit inside 480×480 (never upscale), `imagecopyresampled` onto a white truecolor canvas (flattens transparency), JPEG quality 80, `ContentType: image/jpeg`. Metadata is dropped by the re-encode. EXIF orientation is applied only if the `exif` extension is in the worker image (Step 0 R2). Memory is freed (`unset`) before the next record | ADR 0006 thumbnails row. Decisions 4, 5 |
| C13 | Least privilege in code | The worker gets two narrow ports. `OriginalObjects` can only `get` and `delete` in `receipts`; `ThumbnailObjects` can only `put` and `delete` in `receipt-thumbnails`. An architecture test pins that nothing under `src/Receipts/Processing` or `src/Worker` can reach a `putObject` on the receipts bucket. On AWS, IAM enforces it (Phase 7). Locally, silo's root keys are used (decision 26) | ADR 0006 Storage; PLAN §9 |
| C14 | Cross-user isolation | Every API read and write uses `PK = USER#<principal>` (key built from the session). Another user's expense gives the same 404 as an unknown one, on all 3 routes. A policy covers exactly one key, under the caller's prefix. View URLs cover one object for 5 min. Receipt ids are random v4, so keys cannot be guessed, and even a guessed key gives nothing without a signature. Originals are served from the **S3 origin, not the app origin**, with a forced `Content-Type`, so a hostile PDF or polyglot cannot run script with the app's cookie | ADR 0002, PLAN §5 ("always 404") |
| C15 | Logging | Log message ids, bucket, key, status, failure reason and timings. **Never** policies, signatures, presigned URLs or file bytes. A log-hygiene marker test (WP9) covers it | Phase 3 log-hygiene pattern |
| C16 | Front end: where uploads live | The receipt panel appears **on the edit page only** (the API needs an expense id). The new-expense form shows "You can attach a receipt after saving." | Contradiction 8. Decision 10 |
| C17 | Front end: upload transport | `src/api/upload.ts` is the **only** file allowed to use `XMLHttpRequest` (owner-made ESLint override, WP10). It posts a `FormData` built from `fields`, in the order received, with the file appended **last**. `withCredentials = false`. **No custom headers** (a simple CORS request, no preflight, and never the `X-Requested-With` header: that header is for the API only). Timeout 120 s; aborted by an `AbortSignal`. Progress comes from `xhr.upload.onprogress`. It never throws and returns `UploadResult`. Before sending it checks the URL: `https:`, or exactly `http://localhost:9000` in dev; no userinfo, query or fragment. Field names must come from an allow-list (`key`, `Content-Type`, `Policy`, `X-Amz-*`). All other API calls stay in `http.ts` | Phase 4 C2, C3, C16; contradiction 5. Decisions 13, 14 |
| C18 | Polling | After the upload's 204: `GET …/receipt` at 1 s, then ×1.5 up to 5 s. Stops on `ready`, `failed`, 404, unmount (abort), or a 60 s total cap; after the cap it shows "Still processing" with a "Check again" button. Pauses while `document.visibilityState === 'hidden'`. A generation counter drops stale answers (Phase 4 R7 pattern) | Decision 8 |
| C19 | Preview | Ready image: `<img :src="thumbnailUrl" alt="Receipt thumbnail" width height loading="lazy">`. Ready PDF: an inline-SVG `PdfIcon` component (template markup, so no resource and no CSP impact) plus "PDF, 1.2 MB". Both get an "Open receipt" link: a same-tab `<a :href="viewUrl" rel="noreferrer">`, no `target="_blank"` (C16 lint). If the URLs are within 60 s of `urlsExpireAt`, the click first refetches the status (decision 6). URLs go into `src`/`href` only after passing the same shape check as C17. Never `v-html` | PLAN §7; Phase 4 C16 |
| C20 | CSP | No bundle change: the S3 hosts reach the app only at runtime, so `check-bundle-csp.sh` needs no change. Uploads use XHR, which falls under `connect-src`, **not** `form-action` (that stays `'self'`). Target for Phase 7, written into the ADR 0008 note: `connect-src 'self' https://<receipts-bucket-host>`, `img-src 'self' https://<thumbnails-bucket-host>`. Opening the original is a top-level navigation, which CSP does not limit. Locally Vite sends no CSP. ADR 0006 CORS stays: `receipts` allows POST/GET/HEAD from the app origin only; thumbnails need none (a plain `<img>`) | ADR 0005, 0006, 0008; contradiction 1 |
| C21 | Accessibility | Labelled `<input type="file" accept="image/jpeg,image/png,image/webp,application/pdf">` with its rules in `aria-describedby`. A `<progress max="100">` with a visible "Uploading… 45%" text. The always-present polite live region (ADR 0008 note) announces only the milestones (upload started, uploaded/processing, ready, failed), never every percent. Errors go to `FormAlert`, with focus moved to it. Status is shown in text, never colour alone. Replace and delete use `ConfirmDeleteButton`; replace asks "Replace the current receipt? It is deleted at once." | Phase 4 C15 |
| C22 | Phase 4 follow-ups | **L6:** `ExpenseFormView`'s requests get an `AbortSignal`, aborted on unmount and route change (WP11). **L16:** all receipt logic lives in `useReceipt` + `ReceiptPanel`; the view gains about 15 lines at most. The full refactor of the existing form logic stays a follow-up | Phase 4 whole-PR notes |

**Error mapping (front end, `src/lib/receiptMessages.ts`)**

| Where / failure | UI |
|---|---|
| Client pre-check: wrong type, empty file, > 10 MiB | "Choose a JPEG, PNG, WebP or PDF file up to 10 MB." No request is sent |
| Policy 404 | "This expense no longer exists." |
| Policy 409 | "The receipt changed in another tab. Reload." |
| Policy 422 | The field message under the file input |
| Policy 401/403/429/503/5xx/network | Phase 4 C4 texts |
| S3 400 (`EntityTooLarge`/`TooSmall`) | the size message |
| S3 403 (policy expired or refused) | "The upload link expired. Try again." (a Retry button asks for a new policy) |
| S3 network, timeout, abort | "Upload interrupted. Try again." (abort on unmount is silent) |
| `failed` + `unsupported-type` | "This file isn't a valid JPEG, PNG, WebP or PDF." |
| `failed` + `too-large` | "The image is too large (max 40 megapixels)." |
| `failed` + `unreadable` | "The file could not be read." |
| Polling cap reached | "Still processing. Check again in a moment." with a button |
| Delete 404 | "Already removed." |

## 3. Work packages

**One commit per WP, tests first. The red run is recorded in §7 review notes.** Order: WP1 → Step 0 → WP2 … WP13, one after another on one branch.

### 3.1 Rules every agent brief repeats verbatim
- **Make targets only, in pinned containers.** Never `npm`, `node`, `npx`, `php`, `composer` or `python` on the host. One spec at a time via `make -n …`, as in `docs/local-dev.md`.
- **One make target at a time, in the foreground.** No background jobs, no `&`, no hand-written polling or `pgrep` loops, never two test runs at once (above all, never two `make test-integration`).
- **No new dependency** (Composer, npm, apt, PHP extension, image) without an owner decision. Proposed ones are listed in section 10.
- **Shared Docker host:** only the repo's make targets, `docker compose -p expense-tracker` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`), and `docker run --rm`. Never `prune`, `down -v`, `volume rm`, or `rm $(docker ps -aq)`. Remove only what you created, by name; otherwise stop and ask.
- **Endpoint security:** never download, vendor or generate payload/fuzzing corpora, malware samples, wordlists or offensive tools, and never scan ports (localhost included). Test files are **hand-written**:
  - tiny images generated **inside the test** with GD (`imagecreatetruecolor` + `imagejpeg`/`imagepng`/`imagewebp` into a string);
  - header-only "huge dimension" PNG/JPEG/WebP built byte by byte in the test (PNG IHDR with its `crc32`);
  - a 1-page PDF written as a literal string;
  - text like `<svg …>`/`<html>` saved under an image content type.
  Nothing is fetched from the internet.
- **No AWS calls** and no real credentials. Only the fake `.env` pair.
- **Protected config:** never edit `frontend/eslint.config.ts` (or other protected config) by any route, including `sed` or shell. Lint-config changes are owner steps (WP10).
- **Ask before every push.** After a push, watch CI by run id and check `headSha` (`gh run watch`, or `gh … --jq`; no host `jq`).

### 3.2 Model routing (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium, one brief per WP (file paths, named tests, make targets).
- **Review, one language reviewer per diff:**
  - `ecc:php-reviewer` for PHP;
  - `ecc:vue-reviewer` for `.vue` diffs;
  - `ecc:typescript-reviewer` for pure-TS diffs;
  - `ecc:code-reviewer` only for cross-cutting Compose/Makefile/CI diffs over ~30 lines.
  All sonnet/medium.
- **Security:** `ecc:security-reviewer`, **opus/high**, on WP2, WP3, WP4, WP5, WP6, WP7, WP8, WP10, WP12 and the WP13 whole-feature pass (uploads, signed URLs, file parsing, DB conditions and IAM-like splits all trigger `code-review.md`). Round 2 on the fix hunks, also opus/high. WP9 and WP11 rely on the language reviewer, plus WP13 (decision 22).
- **Other agents:**
  - docs: `ecc:doc-updater` haiku/low, checked line by line by the main session;
  - test-run summaries: `general-purpose` haiku/low;
  - build errors: `ecc:build-error-resolver` sonnet/low, then opus/high.
- **Pipeline per WP:** tdd-guide → main session re-runs the WP's make targets → reviewer(s) → one fix list → round 2 → notes → commit.

### Step 0: Research & Reuse (before WP2)
Docs questions go to a `general-purpose` agent (sonnet/medium, WebFetch, a URL for every claim). The **ran** checks are run by the main session with `docker run --rm` of the pinned images, or through make. GitHub search is read-only. Results go into a "Step 0 results" table before WP2.

| # | Question | Needed by |
|---|---|---|
| R1 | aws-sdk-php `PostObjectV4`: conditions format, `X-Amz-Security-Token` with session credentials (Lambda), expiry. `createPresignedRequest(GetObject)` with `ResponseContentType`/`ResponseContentDisposition`. One client signs for the public endpoint while another calls the internal one (path style) | WP3 |
| R2 | **Ran** in the worker image: `php -m` (gd, exif?), `gd_info()`, Bref function-runtime `memory_limit`, and the wall time and peak memory to inspect and thumbnail a 40 MP JPEG and PNG **generated in-container**. Compare with the runner's 8 s timeout | WP7, decision 21 |
| R3 | **Ran:** does the `php` dev tools image (`docker/php/Dockerfile` target `dev`) have GD with JPEG/PNG/WebP? (needed for unit tests) | WP7, decision 19 |
| R4 | `getimagesizefromstring` on truncated input, a huge IHDR, WebP VP8/VP8L/VP8X, CMYK JPEG. Is there any GD pixel cap in PHP 8.5? | WP7 |
| R5 | **Ran** against silo: presigned GET with response overrides; `ListObjectsV2` + `DeleteObjects`; the CORS answer to a multipart POST from `http://localhost:5173` (status readable; ACAO present) | WP3, WP10 |
| R6 | **Ran** against DynamoDB Local: `TransactWriteItems` ConditionCheck + conditional Put, and the cancellation reasons by position; `DeleteItem ALL_OLD`; `UpdateItem … REMOVE expiresAt` with a condition | WP4 |
| R7 | jsdom 30: `File`, `Blob`, `FormData`, and how far a stubbed `XMLHttpRequest` (fake class through `vi.stubGlobal`) can fire `upload.onprogress` | WP10 |
| R8 | CSP: confirm `form-action` does not govern XHR; the S3 virtual-hosted host form per bucket; Chrome/Firefox open a presigned PDF with `inline` in the built-in viewer | WP12, WP13 |

#### Step 0 results (2026-10-29, G2)

Docs answers came from a `general-purpose` agent (sonnet/medium) reading the aws-sdk-php, php-src, libgd and jsdom sources and the CSP3 spec/MDN. "Ran" rows were run by the main session: the worker and php images with `--network none` and the worker's hardening flags, and the php tools container against `make up`. Throwaway data only, all deleted afterwards.

| # | Result | Effect on the plan |
|---|---|---|
| R1 | `PostObjectV4($client, $bucket, $formInputs, $conditions, $expiry)`: the conditions are copied as given. It adds **no** `bucket`/`key` condition by itself. With session credentials it adds `X-Amz-Security-Token` to both the inputs and the policy. A presigned GET signs `response-content-type`/`-disposition` into the query. The signed host is the signing client's endpoint host (virtual-hosted unless `use_path_style_endpoint`) | C5 already lists every condition explicitly; WP3 unit tests assert each one is present. Two clients (public for signing, internal for calls) as planned |
| R2 | **Ran.** The worker image has GD (JPEG, PNG, WebP; no AVIF) and `exif`. Bref's default `memory_limit` is 10240M (the Lambda memory size is the real bound). A 40 MP (8000×5000) source: JPEG 0.41 s, PNG 0.41 s, from inspect to a 480×300 thumbnail at q80 (~49 KB). PHP's peak counter rose by 157 MB (JPEG) and 271 MB (PNG), so GD's pixel buffers **do** count against `memory_limit` | **Decision 21: keep 8 s / 10 s.** EXIF rotation is on (C12). Phase 7 worker memory ≥ 2 × 271 MB, so 1024 MB |
| R3 | **Ran.** The `php` dev tools image has **no** GD and no `exif` | **Decision 19 applies:** add `gd` (JPEG, PNG, WebP) and `exif` to the `dev` stage of `docker/php/Dockerfile` in WP7 |
| R4 | `getimagesizefromstring` reads headers only and reports a huge PNG IHDR as is; truncated input → `false`. **`imagecreatefromstring` allocates width × height × 4 up front**, before decoding. WebP VP8/VP8L/VP8X sizes are read (since PHP 7.1). CMYK JPEG: `channels = 4`, and libgd loads it with a basic CMYK→RGB conversion. No GD pixel cap; `gd.jpeg_ignore_warning` only controls warnings | C11's "check size before decoding" is required, not optional (a decompression bomb otherwise). CMYK JPEGs are accepted; WP7 adds a CMYK fixture case |
| R5 | **Ran.** A presigned GET with overrides returns `application/pdf` + `inline; filename="receipt.pdf"`; a tampered override → 403. `ListObjectsV2` by prefix + `DeleteObjects` removes exactly that prefix (`e1/` does not touch `e10/`). **`DeleteObjects` with an empty list → `MalformedXML`.** A multipart POST from `http://localhost:5173` → 204 with `Access-Control-Allow-Origin` and `Expose-Headers: ETag`; from another origin → 204 too, with no ACAO header (CORS only hides the answer; the policy is what protects the bucket) | C8 cleanup: prefixes end with `/`; **skip `DeleteObjects` when the listing is empty**, and page through the listing (1000 keys per page). WP6 test for an expense with no receipt |
| R6 | **Ran.** The C4 transaction commits when there is no receipt; a stale read → `TransactionCanceledException` with reasons `[None, ConditionalCheckFailed]` (→ 409); the expense gone → `[ConditionalCheckFailed, None]` (→ 404). The C10 conditional update with `REMOVE expiresAt` works; a stale key or a duplicate → `ConditionalCheckFailedException`. `DeleteItem ALL_OLD` returns the old item, or no `Attributes` when there was none | C4/C10 as planned: map the reasons **by position** (0 = expense, 1 = receipt) |
| R7 | jsdom 30 has `File`, `Blob`, `FormData` (append keeps order), `XMLHttpRequestUpload` and `ProgressEvent` | WP10 fakes XHR with `vi.stubGlobal`; the fake must dispatch `ProgressEvent`s itself |
| R8 | CSP3: `form-action` covers only form submissions; `connect-src` covers XHR/fetch (MDN). Virtual-hosted host: `<bucket>.s3.<region>.amazonaws.com`. Firefox opens `application/pdf` + `inline` in its viewer; **Chrome is not documented** | C20 as planned. The G3 walkthrough checks the PDF in both browsers (already listed) |

### Part A: Back-end building blocks

#### WP1: Plan (S)
- **Files:** this plan; a PLAN §12 row 5 link. **Agent:** main session. **Commit:** `docs: add phase 5 implementation plan`.

#### WP2: Receipt values (S), security-critical
- **Files:** `backend/src/Receipts/{ReceiptObjectKey,ReceiptContentType,ReceiptStatus,FailureReason,Receipt,ReceiptLimits}.php` (`MAX_BYTES`, `MAX_PIXELS`, `MAX_SIDE`, `POLICY_TTL_SECONDS`, `VIEW_URL_TTL_SECONDS`, `PENDING_TTL_SECONDS`, `FAILED_TTL_SECONDS`); unit tests under `tests/Unit/Receipts/`.
- **Decided in review:** the key is URL-decoded in **one place only**, `S3EventParser`. `ReceiptObjectKey::parse()` takes an already-decoded key (`S3ObjectRef::$key` or a stored `objectKey`), refuses any `%`, and a key that parses equals its `originalKey()`. `Receipt` derives `receiptId()` from its key.
- **Tests first:**
  - `ReceiptObjectKey`:
    - build → parse round-trips;
    - `thumbnailKey()`;
    - `parse` refuses upper-case UUIDs, extra segments, `..`, `%2F` left after decode, a trailing `/`, a missing prefix, the `pipe+` test keys, an empty string and NUL.
  - `ReceiptContentType`:
    - the 4 MIME strings only;
    - `detect()` on hand-built byte prefixes (each signature, a 2-byte file, `RIFF…WAVE`, `<svg`, `<!DOCTYPE html`, `GIF89a`, a UTF-8 BOM before `%PDF`);
    - `extension()`.
  - `Receipt`: `isLive(now)` for pending/failed before and after `expiresAt`, and ready.
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: add receipt key, content type and status values`.

#### WP3: S3 infrastructure and the API → S3 path (S–M), security-critical
- **Files:**
  - `backend/src/Infrastructure/S3/{S3Settings,S3ClientFactory,UploadPolicySigner,ViewUrlSigner,ObjectStore,S3ObjectStore}.php`;
  - `Receipts/{OriginalObjects,ThumbnailObjects,ReceiptObjectCleaner}.php` (the narrow ports of C13; the cleaner does the C9 logging);
  - `ApiConfig` gains S3 settings (`S3_ENDPOINT`, `S3_PUBLIC_ENDPOINT`, `RECEIPTS_BUCKET`, `THUMBNAILS_BUCKET`; local endpoints checked by `LocalEndpoint`, required when `APP_ENV=local`);
  - `docker-compose.yml` + `docker/api/nginx.conf` (decision 15);
  - `.env.example`;
  - `Makefile check-env`.
- **Tests first:**
  - unit:
    - the signer's policy holds exactly the C5 conditions and expiry (`FrozenClock`);
    - the view URL carries the overrides and the 5-minute expiry;
    - the cleaner logs `receipt cleanup failed` with keys only (no URL or signature) when deletion throws;
    - config errors name variables, never values.
  - integration (`tests/Integration/S3/`), sent to `http://s3:9000` with the `Host: localhost:9000` header (ADR 0007 note):
    - the policy accepts the exact key and type;
    - it refuses another key, another type, 10 MiB + 1 and an extra field;
    - the view URL returns the bytes with the forced type;
    - prefix delete removes both buckets' objects;
    - `api-php` reaches S3 only through the chosen path.
- **From the WP2 security review:** on AWS the signer must produce **virtual-hosted** URLs (`<bucket>.s3.<region>.amazonaws.com`), so each bucket is its own browser origin; path style (`s3.<region>.amazonaws.com/<bucket>`) would put every bucket on one shared origin. Path style stays only for the local endpoint. A unit test pins the style per endpoint.
- **Review:** php-reviewer; code-reviewer for the Compose/nginx part if over 30 lines; **security-reviewer**. **Commit:** `feat: add s3 policy and url signing and the api's object store`.

#### WP4: Receipt repository (S–M), security-critical
- **Files:** `backend/src/Receipts/ReceiptRepository.php` (`find`, `startUpload`, `remove`, and for the worker `findByKey`, `markReady`, `markFailed`); `Infrastructure/DynamoDb/ItemStore.php` (a `deleteItem` variant returning the old item); tests in `tests/Unit/Receipts/` and `tests/Integration/Receipts/ReceiptRepositoryTest.php` (`TemporaryTable`).
- **Tests first:**
  - `find` hides expired pending/failed records (C3);
  - `startUpload` on a missing expense → `ExpenseNotFound`;
  - a concurrent change between read and transaction → `ReceiptChanged`;
  - replace returns the old key;
  - `remove` returns the old item or null;
  - `markReady`/`markFailed` succeed once, a second time report "already processed", fail for another key, and fail for an expired pending record;
  - a corrupt item is a `StorageFailure`;
  - **cross-user:** user B's principal never reads or changes A's `RCPT#`.
- **From the WP2 reviews (hydration checks, each a `StorageFailure` with a unit test):**
  - the stored `objectKey` must parse and round-trip: `ReceiptObjectKey::parse($stored)?->originalKey() === $stored`;
  - the key's user id and expense id must equal the item's `PK USER#<u>` and `SK RCPT#<e>` (C14: otherwise a corrupt item could get user A a view URL for user B's file);
  - `:now` for the conditions comes from the same clock as `Receipt::isLive()`, as `getTimestamp()`.
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: add the receipt repository with conditional writes`.
- **Outcome (2026-10-30):**
  - Red run: 53 errors and 18 failures (class and method not found).
  - Round 1: no critical or high findings. Medium items: `receiptOwnerPartition` was not guarded, and `remove()` deleted before checking and ignored expiry. Fixed with:
    - two new architecture tests (`ReceiptOwnerPartitionCallTest`, `ReceiptWorkerCallTest`; string-literal references are flagged too) and a public-surface test;
    - the `UserPartitionCallTest` left unchanged (owner decision: a dedicated `AuthKeys::receiptOwnerPartition` instead of an allow-list entry);
    - `remove()` semantics pinned by tests and passed to WP6;
    - a same-id replace refused.
  - Round 2: the `remove()` docblock overstated "deleted". Fixed so that only `CORRUPT_ITEM` means deleted (`ItemStore::deleteItemReturningOld` now reports an unreadable old item as `CORRUPT_ITEM`).
  - Separate commit: `scripts/check-stack-up.sh` now guards `make test-integration`, which used to half-start a stopped stack through `compose run` dependencies.

### Part B: Endpoints (contract and code in one commit each)

#### WP5: Contract + `POST /expenses/{id}/receipt/upload` (S–M), security-critical
- **Files:**
  - `contract/openapi.yaml` 0.12.0: paths `/expenses/{id}/receipt/upload` (`createReceiptUpload`) and `/expenses/{id}/receipt` (`getReceipt`, `deleteReceipt`, documented now); schemas `ReceiptUploadRequest`, `ReceiptUpload`, `Receipt`; errors 400/401/403/404/405/409/413/415/422/500/503 as in Phase 3; `X-Requested-With` on POST/DELETE;
  - `frontend/src/api/schema.gen.ts` (`make contract-gen`);
  - `Receipts/Http/{CreateReceiptUploadHandler,ReceiptUploadInput,ReceiptFieldMessages}.php`;
  - `Api/Routes.php`;
  - `ApiServices` (lazy S3 pieces);
  - `tests/Integration/Isolation/CrossUserIsolationApiTest.php` (`SECURED_CALLS` + 3 rows; the GET/DELETE rows are skipped until WP6 only if the suite allows; otherwise the routes land together).
- **Tests first:** `RoutesMatchContractTest`, `ProtectedRoutesMatchContractTest` and `CrossUserCoverageTest` are red. Then:
  - input: type outside the list, `size` 0 / 10 MiB + 1 / float / string / missing, unknown member;
  - handler order: principal → route id → body → store;
  - a replace deletes the old objects **after** the commit (a spy shows the order);
  - integration over nginx: 201 + `Location`, `no-store`, contract-valid; another user's expense → 404 with no record written; missing CSRF header → 403.
- **From the WP4 reviews:** a `TransactionConflict` cancellation (two concurrent starts on the same `RCPT#`) is rethrown as `TransactionCancelled`. Map it to 503, as the other repositories' callers do. `startUpload` refuses a pending receipt whose id equals the current one, so the handler must always mint a fresh v4 id.
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: add the receipt upload policy endpoint`.
- **Outcome (2026-10-30):**
  - **Contract split:** only `createReceiptUpload` (path `/expenses/{id}/receipt/upload`, schemas `ReceiptUploadRequest` and `ReceiptUpload`) is in 0.12.0. The parity tests (`RoutesMatchContractTest`, `ProtectedRoutesMatchContractTest`, `CrossUserCoverageTest`) require a route and a cross-user row for every documented operation, and redocly refuses an unused `Receipt` schema. So `getReceipt`, `deleteReceipt` and `Receipt` move to WP6.
  - **503:** the handler does not catch `TransactionCancelled`. The existing `StorageUnavailableMiddleware` maps a `TransactionConflict`-only cancellation and throttling to 503 with `Retry-After: 1`, and other cancellations to 500, as for the expense routes.
  - **Exact size (owner decision):** the policy pins `content-length-range [size, size]`; `maxBytes` in the answer stays the 10 MiB cap. The silo refuses one byte less (`EntityTooSmall`) and one byte more (`EntityTooLarge`).
  - **Order:** commit, then sign (local, no network), then clean up the old objects, then 201. The cleanup sits in a `finally`: once the commit points the record at the new key, the old files are unreferenced, so they are deleted even if signing throws (500). This avoids orphaned personal data (round-2 security review).
  - **Missing RED runs:** the integration tests and the new silo size cases were written before the code but were first run green.

#### WP6: `GET` and `DELETE` receipt, expense-delete cleanup (S–M), security-critical
- **Files:** `Receipts/Http/{GetReceiptHandler,DeleteReceiptHandler,ReceiptResponse}.php`; `Expenses/Http/DeleteExpenseHandler.php` (+ cleaner); `Routes.php`; tests.
- **From WP5:** the contract goes to 0.13.0 with path `/expenses/{id}/receipt` (`getReceipt`, `deleteReceipt`) and the `Receipt` schema, then `make contract-gen`. Add both operations to `CrossUserIsolationApiTest` `SECURED_CALLS`/`ID_ATTEMPTS`, and make its `assertUnchanged` also compare `RCPT#` records.
- **Tests first:**
  - GET: 404 with no receipt, an expired pending one, or another user's; `pending` has null URLs; `ready` image has both URLs; `ready` PDF has `viewUrl` only; `failed` carries `failureReason`; `no-store`.
  - DELETE: 204, then 404; record gone before objects (spy order); S3 failure → still 204 plus the ERROR log.
  - Expense delete: objects under both prefixes removed; S3 failure → 204 plus the log.
  - The cross-user rows pass.
- **From the WP3 reviews:**
  - `ViewUrlSigner` gets the type **the worker verified** (the stored `contentType` after `ready`), never the declared one;
  - prefix cleanup on expense delete: bound the work per request (pages or a total deadline) so 20 s × 3 attempts × N pages cannot outlast the Lambda timeout; whatever is left is logged by the cleaner (C9);
  - the cross-user guarantee of the cleanup rests on passing the **session's** user id, never one from the request; a test pins it.
- **From the WP4 reviews (`ReceiptRepository::remove`):**
  - The delete is unconditional and returns the old record, even an expired one. The handler answers 404 when `isLive($now)` is false (C3, matching GET) and still deletes that record's objects.
  - `StorageFailure` with code `CORRUPT_ITEM` means the record IS deleted but unreadable. Clean up only **this expense's** two prefixes, built from the Principal (`ReceiptObjectKey::originalPrefix($owner->userId, $expenseId)` and the thumbnail prefix), never the user's whole prefix. Log it.
  - Any other `StorageFailure` means the outcome is unknown and the record may still exist. Do **not** clean up; answer 5xx (a retry is safe). A test pins that this path never deletes objects.
  - The worker-only methods (`findByKey`, `markReady`, `markFailed`) may be called only from `src/Worker/` (`ReceiptWorkerCallTest`), and the repository's public methods are pinned (`ReceiptRepositoryPublicSurfaceTest`). The API handlers use `find`, `startUpload` and `remove` only.
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: add receipt status, view links and delete, and clean up on expense delete`.
- **Outcome (2026-10-30):**
  - **Contract 0.13.0:** `getReceipt`, `deleteReceipt` and the `Receipt` schema. Both operations are in `CrossUserIsolationApiTest`; user A owns a pending receipt, and `assertUnchanged` compares `GET …/receipt`.
  - **CORRUPT_ITEM on DELETE → 204:** the unconditional `DeleteItem` has already removed the record. A warning is logged, and only this expense's two prefixes are swept, built from the Principal. Accepted: that sweep can also remove a file the same user uploads at the same moment (own data only, and it needs a corrupt record first).
  - **Cleanup is bounded in time (round-1 security review):**
    - `ReceiptObjectCleaner` gets an 8 s budget per call (`Deadline`, from the injected clock). The deadline is checked before each S3 call and before each `deletePrefix` page. When it passes, the cleaner logs `receipt cleanup failed` with `DeadlineReached` and the key or prefix that is left.
    - The cleanup S3 client (`S3ClientFactory::forCleanup`) uses a 2 s connect timeout, a 5 s timeout and 2 attempts, and the 3-page cap per prefix stays.
    - Combined failure codes are reported, e.g. `AccessDenied,PageLimitReached`.
  - **Expense DELETE builds an S3 client** when its route matches, so a bad S3 setting gives a 500 before the transaction runs, and nothing is deleted.
  - **The implementing agent broke two rules:** it ran a bare `python3` on the host (nothing ran), and a `sed` with an empty line number damaged two files, which it repaired. The reviewer confirmed the final diffs are clean. Briefs now say: edit with the Edit tool only.

### Part C: Worker

#### WP7: File inspection and thumbnails (S–M), security-critical
- **Files:** `backend/src/Receipts/Processing/{FileInspector,Inspection,ThumbnailMaker}.php`; tests (images generated in the test, §3.1).
- **Tests first:**
  - each valid type passes and reports its dimensions;
  - declared PNG with JPEG bytes → `unsupported-type`;
  - SVG/HTML text as `image/png` → `unsupported-type`;
  - a PNG IHDR of 50,000 × 50,000, and of 12,001 × 1 → `too-large` **without decoding** (peak memory stays under a small bound; `memory_get_peak_usage` delta asserted);
  - a truncated JPEG → `unreadable`, and no warning escapes;
  - a PDF with `%PDF-` passes; a PDF magic under `image/jpeg` fails;
  - the thumbnail of a 2000×1000 PNG with alpha is a JPEG ≤ 480 wide on white; an image of 100×50 is not upscaled;
  - the output has no EXIF (APP1 marker absent).
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: add receipt file inspection and gd thumbnails`.

#### WP8: Receipt processor and worker wiring (S–M), security-critical
- **Files:**
  - `Receipts/Processing/ReceiptProcessor.php` (implements `RecordProcessor`, C10);
  - `Worker/{WorkerFactory,WorkerConfig}.php`;
  - `docker-compose.yml` `worker` env (DynamoDB/S3 endpoints, table, buckets, fake keys, `AWS_EC2_METADATA_DISABLED`);
  - delete `Receipts/ReceivedReceiptLogger.php` and its test (G5);
  - architecture test for C13.
- **Tests first** (unit, with fakes for the repository and the ports; fixtures `docs/spikes/fixtures/silo-object-created-post.json` and an AWS-shaped event):
  - valid → thumbnail then ready;
  - invalid → original deleted then failed;
  - TestEvent → nothing;
  - foreign bucket or bad key → logged and skipped;
  - duplicate after ready/failed → no write;
  - record deleted, replaced or expired → both objects deleted;
  - `NoSuchKey` with a pending record → throws; without one → no-op;
  - a batch with one throwing record → exactly that `itemIdentifier`;
  - `WorkerFactory::create()` builds the real processor from env, and refuses missing or non-local endpoints.
- **From the WP2 reviews:** a processor test feeds a real `S3EventParser` result into the key check: an event key `receipts/<u>/<e>%252F<r>` must be skipped (logged, success), never normalised into another object's key.
- **From the WP5 reviews:** an upload policy stays valid for 5 minutes after a replace or an expense delete, so a file can land at an old key after the cleanup ran. A processor test pins that an original whose key does not match the current record (or has no record) is deleted along with its thumbnail (C10 step 7).
- **Review:** php-reviewer; **security-reviewer**. **Commit:** `feat: process receipts in the worker with conditional updates and cleanup`.

#### WP9: End-to-end pipeline, isolation and log hygiene (M)
- **Files:**
  - `tests/Integration/Pipeline/ReceiptPipeTest.php` (seed a pending receipt record and upload a valid file under its parseable key, so the test follows the real worker path; the poison-message → DLQ case stays as it is; contradiction 7);
  - new `tests/Integration/Receipts/ReceiptLifecycleTest.php` (API policy → multipart POST to `s3:9000` with the public `Host` → poll the record for at most 15 s with the existing helper style);
  - `Makefile` `test-integration` markers (the worker's `receipt ready` / `receipt failed` lines for a run-unique key; no `X-Amz-Signature`/`Policy` value in the `api-php` or `worker` logs).
- **Cases:**
  - JPEG → ready plus thumbnail;
  - PDF → ready, no thumbnail;
  - an HTML file as `image/png` → failed, original gone;
  - policy issued, record deleted, then upload → both objects gone;
  - replace during processing → the old objects gone, the new one ready;
  - re-sending the same S3 event to the queue → no change;
  - poison message → DLQ (existing slow test kept);
  - expense delete after ready → prefixes empty;
  - B's GET/DELETE/POST on A's expense → 404, and A's objects untouched.
- **Acceptance:** `make test-integration` green twice in a row (never concurrently). Each new Makefile check shown to fail once through a temporary mutation.
- **Review:** php-reviewer; code-reviewer for the Makefile. **Commit:** `test: run the receipt pipeline end to end with cleanup races and isolation`.

### Part D: Front end

#### WP10: Upload client and receipt API (S–M), security-critical
- **Owner step first (protected file):** the owner adds to `frontend/eslint.config.ts` an `app/allow-upload` block for `src/api/upload.ts` that drops only `XMLHttpRequest` from `OTHER_GLOBALS` (the main session drafts the exact lines; the owner pastes and commits them). Then the main session confirms that a fixture file outside `upload.ts` using `XMLHttpRequest` still fails `make frontend-lint`.
- **Files:**
  - `frontend/src/api/{receipts,upload}.ts`;
  - `src/lib/{receiptFile,receiptMessages,backoff,receiptUrl}.ts`;
  - `src/api/types.ts` (aliases);
  - `src/config.ts` (`RECEIPT_MAX_BYTES`, `RECEIPT_TYPES`, `POLL_*`, `UPLOAD_TIMEOUT_MS`, `DEV_UPLOAD_ORIGIN`).
- **Tests first** (stubbed `fetch` and a fake `XMLHttpRequest`):
  - API calls go through `request` with `resourcePath`; the receipt paths pass `isSafePath`;
  - upload: fields in order with the file last; `withCredentials` false; no headers set; progress values; 204 → ok; 400/403 → kinds; timeout and abort; a URL with userinfo, query, fragment, `javascript:`, `http://evil.example` or an unknown field name is refused **before** `open()`;
  - `receiptFile`: type and size pre-checks (0 bytes, 10 MiB, 10 MiB + 1, `image/svg+xml`, empty type);
  - `backoff` sequence and cap;
  - `receiptUrl` accepts only `https:` or the dev origin;
  - the policy request declares `size` = `file.size` exactly (WP5: the policy pins the exact size, so any other number makes S3 answer 400).
- **Review:** typescript-reviewer; **security-reviewer**. **Commit:** `feat: add the receipt api and the direct-to-s3 upload client`.

#### WP11: `useReceipt` composable and form abort signals (S–M)
- **Files:** `frontend/src/composables/useReceipt.ts`; `src/views/ExpenseFormView.vue` (L6 AbortSignal only); specs.
- **Tests first:**
  - state machine idle → requesting → uploading(progress) → processing → ready/failed;
  - polling backoff, cap with "Check again", pause when hidden, abort on unmount, stale-answer drop after replace;
  - the replace flow asks for confirmation when a receipt exists;
  - 404 while polling → none;
  - URL refresh when near `urlsExpireAt`;
  - form requests are aborted on route change and unmount, and an aborted answer changes nothing.
- **Review:** typescript-reviewer for the composable, vue-reviewer for the view hunk (one per language). **Commit:** `feat: add the receipt state composable and abort form requests on leave`.

#### WP12: Receipt panel, preview and accessibility (S–M), security-critical
- **Files:** `frontend/src/components/{ReceiptPanel,ReceiptPreview,PdfIcon}.vue`; `ExpenseFormView.vue` (mount on edit, hint on new); specs.
- **Tests first:**
  - the file input's label and `accept`;
  - the `progress` value and text;
  - the live region gets the milestones only;
  - focus on `FormAlert` after errors;
  - the thumbnail `img` uses only a validated URL; a hostile URL renders no `img`/`a`;
  - the PDF icon plus size; the "Open receipt" link has no `target`;
  - replace and delete through `ConfirmDeleteButton`;
  - every message in the §2 mapping table;
  - the new form shows the hint and no input;
  - the view grows by ≤ ~15 lines.
- **Review:** vue-reviewer; **security-reviewer**. **Commit:** `feat: add receipt upload, status and preview to the expense form`.

#### WP13: Whole-feature security pass, docs, walkthrough (S)
- **Security pass:** `ecc:security-reviewer` (opus/high) over `backend/src/Receipts`, `Infrastructure/S3`, the touched handlers and `frontend/src/{api,composables,components}` receipt files, against the §4 threat list. Fixes go back to tdd-guide; round 2 on the fix hunks.
- **Docs (doc-updater haiku/low):**
  - "Implemented in Phase 5" notes: ADR 0006 (C4, C8, C10, C11), ADR 0007 (worker env, the API → S3 path, runner timing), ADR 0008 (C17, C20 CSP target with S3 hosts);
  - `docs/local-dev.md` receipt walkthrough (§6 G3 list), repeating that the app must be opened on `http://localhost:5173` (sign-in cookie, and the S3 CORS origin; contradiction 6);
  - Phase 7 carry list: the API Lambda env must set `RECEIPTS_BUCKET` and `THUMBNAILS_BUCKET` (the API refuses to start without them, WP3) and no `S3_ENDPOINT`/`S3_PUBLIC_ENDPOINT`; IAM per C13; `s3:ListBucket` with a prefix condition for the API; the API role needs `s3:PutObject` on `receipts/*` to sign policies, and no wider (a presigned POST is only as narrow as its signing role; on Lambda `fields` carries `X-Amz-Security-Token`, fine because `no-store` and never logged); an API Gateway per-route throttle on `createReceiptUpload` (WP5 review L2); an API Lambda timeout above the request worst case and below API Gateway's 30 s. The worst case (WP6 round-2 security review) is: the DynamoDB calls before cleanup (session lookup plus the write, each up to about 15 s when degraded) + the 8 s cleanup budget + one in-flight cleanup S3 call (about 12 s). The cleanup budget runs from the start of the cleanup, not the start of the request. When DynamoDB and S3 are degraded together, the request can still pass 30 s. Accepted for now. Phase 7 should take the deadline from the Lambda context's remaining time minus a margin, or tighten the DynamoDB client timeouts; SSE; CORS origin; the CSP hosts; the `receipt cleanup failed` metric filter; worker memory ≥ R2 peak × 2; timeout; visibility ≥ 6×;
  - PLAN §12 row 5 → Done.
- **Before ready:** `/update-docs`, `/code-review`, `/verification-loop`, `/ecc:cost-report` (compare with Phase 4, about $87).
- **Commit:** `docs: phase 5 receipts walkthrough and adr notes`.

**Dependencies:** WP2 → WP3 → WP4 → WP5 → WP6 → (WP7 → WP8) → WP9; WP5 (contract types) → WP10 → WP11 → WP12; everything → WP13. Run strictly one after another.

## 4. Threats and how each is handled

| Threat | Control | Test |
|---|---|---|
| Content-type spoofing | Policy fixes `Content-Type`; worker magic bytes must equal the declared type; view URL forces the verified type | WP3 policy refusal; WP7; WP9 HTML-as-PNG |
| Oversized file | Client pre-check; `content-length-range`; worker refuses `ContentLength > MAX` | WP3 (10 MiB + 1 refused by silo); WP8 |
| Decompression bomb / huge dimensions | `getimagesizefromstring` header check (≤ 12,000 px a side, ≤ 40 MP) **before** any decode; GD warnings → `unreadable`; memory per record freed | WP7 header-only fixtures with memory bound |
| SVG/HTML disguised as image | Not in the allow-list; magic mismatch → failed and deleted; originals served from the S3 origin with a forced type | WP7, WP9 |
| Key / path injection | Server-built keys from session + UUIDs; no filename; strict `parse()` in the worker; `eq $key` in the policy | WP2, WP3 |
| Cross-user key guessing / access | `PK` from session; 404 for foreign; v4 receipt ids; one-key policy; signed GETs only | WP4, WP6, WP9 isolation rows |
| Signed URL leakage / lifetime | Policy 5 min; view 5 min; `no-store`; URLs only for `ready`; never logged; `rel="noreferrer"`; no third-party resources | WP3, WP6, WP9 log markers |
| Replace / delete vs worker race | Record first, then files; worker condition on key + status + expiry; re-read and clean up on condition failure | WP4, WP8, WP9 |
| Expense delete vs upload policy race | ConditionCheck on the expense in the policy transaction; prefix cleanup after delete | WP4, WP6 |
| Redelivery / partial batches | Idempotent outcomes; deletes idempotent; per-record failures only for transient errors | WP8, WP9 |
| Worker writing to originals (loop) | Narrow ports + architecture test; IAM in Phase 7; thumbnails bucket has no events | WP8 |
| CSRF on new routes | Existing middleware (`X-Requested-With` + `Origin`); the S3 POST carries no credentials | WP5 |
| Hostile URL from a compromised API reaching `src`/`href`/XHR | `receiptUrl` shape check; CSP `connect-src`/`img-src` hosts in Phase 7 | WP10, WP12 |

## 5. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| PHP unit | Values, signer, cleaner, repository logic with fakes, inspector, thumbnailer, processor, config, architecture split | `backend/tests/Unit/{Receipts,Infrastructure/S3,Worker}` | `make test-unit` |
| Contract | Route parity, protected routes, documented errors, cross-user coverage | `backend/tests/Contract` | `make test-contract` |
| Integration (storage, S3) | Repository on `TemporaryTable`; S3 policy/URL/prefix delete against silo | `tests/Integration/{Receipts,S3}` | `make test-integration` |
| Integration (pipeline) | Real relay + runner + worker: ready/failed/cleanup/races/redelivery/DLQ; isolation; log hygiene | `tests/Integration/{Pipeline,Receipts,Isolation}` + Makefile markers | `make test-integration` (one run at a time) |
| Front end | API, upload, libs, composable, components; 100% coverage kept | `frontend/src/**/__tests__` | `make frontend-test` |
| Static | Lint (with the owner's override), `vue-tsc`, CSP bundle check, secrets | — | `make frontend-lint check-bundle-csp check-bundle-secrets` |
| Manual | G3 walkthrough | `docs/local-dev.md` | owner |

## 6. Branch, PR, CI and gates
- **One PR**, draft after WP6, ready after WP13, squash-merged. **Ask before every push**; then watch CI by run id and check `headSha`. The 7 required CI jobs stay unchanged; the worker env and the S3 path live in Compose, which CI already starts.
- **G1:** the owner approves this plan, including section 10.
- **G2:** the Step 0 results are recorded in this file. Any decision that changes an ADR (decision 15 option b/c is an ADR 0007 amendment; decision 21 if timings change) gets its note accepted before the WP that needs it.
- **G3:** owner browser walkthrough on `make up`, in Chrome and Firefox:
  - upload a JPEG (progress, then Processing, then thumbnail);
  - upload a PDF (icon; opens in the viewer);
  - an 11 MB file (refused before sending);
  - a `.txt` renamed `.png` (failed message);
  - replace (confirmation, old preview gone);
  - delete (confirmation, "Already removed" in a second tab);
  - two tabs replacing at once (409 "Reload");
  - DevTools "Slow 3G" (progress moves, leaving the page aborts silently, polling cap shows "Check again");
  - keyboard only and screen-reader milestones.
- **G4:** only if a CI job is added or renamed.
- **G5:** deletions: `backend/src/Receipts/ReceivedReceiptLogger.php` and `tests/Unit/Receipts/ReceivedReceiptLoggerTest.php`.
- **G6:** the owner merges.
- **AWS:** none.

## 7. Carried items and review notes
- **Carried in:** Phase 4 L6 (WP11), L16 (C22); Phase 4 §7.2 "connect-src/img-src for S3 hosts" (C20); ADR 0007 Host-header note (WP3, WP9); `CrossUserCoverageTest` note (WP5).
- **Carried out (Phase 7):** the list in WP13; the real CORS origin on the receipts bucket (contradiction 6: locally only `localhost` can sign in, so the single-origin bootstrap rule is enough).
- **§7.3 Review notes:** filled per WP (red run, findings, fixes, round 2).

## 8. Risks

| # | Risk | Mitigation | WP |
|---|---|---|---|
| R1 | A large legitimate image takes > 8 s under RIE, so local retries reach the DLQ | Step 0 R2 timing; decision 21 | WP7, WP9 |
| R2 | GD memory per 40 MP decode (~160 MB+) exceeds the local limit or Lambda memory | Header check first; R2 peak measured; Phase 7 memory sizing | WP7 |
| R3 | The php tools image lacks GD, so unit tests cannot run | Step 0 R3; decision 19 | WP7 |
| R4 | silo differs from AWS on response overrides or prefix listing | Step 0 R5; conformance-style integration tests | WP3 |
| R5 | The lint override is too broad, or edited by an agent | Owner-only step; fixture proof | WP10 |
| R6 | An orphaned object after an S3 failure | ERROR log; Phase 7 alarm; prefix cleanup on expense delete | WP6 |
| R7 | Pipe test flakiness (timing, shared queue) | Existing marker approach; generous bounds; never concurrent runs | WP9 |
| R8 | `ExpenseFormView` keeps growing | Composable + panel; ≤ ~15 lines in the view | WP11–12 |
| R9 | Scope creep (list indicator, PDF thumbnails, OCR) | Decisions 11 and the not-in-scope list | all |

## 9. Lessons applied
Tests first, with the red run recorded; fail closed (an invalid file is an outcome, not a crash; the client never throws); security round 2 at opus/high; make-only, one run at a time; hand-written fixtures only; protected config edited only by the owner; haiku docs checked line by line; `/ecc:cost-report` at the end.

## 10. Owner decisions (recommended defaults)

**Approved at G1 on 2026-10-29: every recommended default below, unchanged.** Decision 15 is option (a); decisions 19 and 21 still depend on the Step 0 results (R3, R2).

1. **Max size.** 10 MiB (10,485,760 bytes), shown as "10 MB"? **Recommended: yes.** Alternative: 10,000,000 bytes.
2. **Accepted types.** JPEG, PNG, WebP, PDF only (no HEIC, GIF, AVIF, SVG)? **Recommended: yes** (ADR 0006, ADR 0012 GD results).
3. **Pixel limits.** ≤ 40 MP and ≤ 12,000 px on each side? **Recommended: yes.** Alternative: 25 MP (less memory, rejects some phone panoramas).
4. **Thumbnail.** JPEG, fit 480×480, quality 80, white background, no upscaling; EXIF rotation only if `exif` is in the image? **Recommended: yes.** Alternatives: 320 px; WebP thumbnails (ADR 0006 says JPEG).
5. **EXIF / GPS in originals.** Keep the original exactly as uploaded (only its owner can view it); the thumbnail carries no metadata? **Recommended: yes.** Alternative: re-encode originals to strip metadata; that needs the worker to write to `receipts`, against ADR 0006.
6. **View URL lifetime.** 5 minutes for originals and thumbnails, refreshed on click when within 60 s of expiry? **Recommended: yes.** Alternatives: 1 min; 15 min.
7. **Upload policy lifetime.** 5 minutes (ADR 0006)? **Recommended: yes.**
8. **Polling.** 1 s, then ×1.5 up to 5 s, stop after 60 s with "Check again", pause when the tab is hidden? **Recommended: yes.** Alternative: fixed 2 s for 30 s.
9. **Replace while pending.** Allowed, with confirmation whenever a receipt exists? **Recommended: yes.** Alternative: block replace while pending (needs an extra state and message).
10. **Where uploads happen.** Edit page only; the new form shows "attach after saving"? **Recommended: yes.** Alternative: pick a file on the new form and upload after the create succeeds (more states, progress across a navigation).
11. **Receipt marker in the expense list.** None in Phase 5 (no change to `Expense`)? **Recommended: yes.** Alternative: add `hasReceipt` to `Expense` (a contract change plus one extra read per page).
12. **Opening the original.** A same-tab link with `inline` disposition (PDFs open in the browser viewer)? **Recommended: yes.** Alternative: `attachment` (always download).
13. **Upload transport.** `XMLHttpRequest` in `src/api/upload.ts` for real progress, enabled by an ESLint override **you** add to `frontend/eslint.config.ts`? **Recommended: yes.** Alternative: `fetch` with an indeterminate spinner and no lint change.
14. **Upload URL check in the client.** A shape check plus the CSP host pin on AWS? **Recommended: yes.** Alternative: a build-time pinned upload host (a new `VITE_` variable; it must avoid the `*ORIGIN*` name rule in `vite/devEnv.ts`).
15. **How `api-php` reaches S3 locally.**
   - (a) **Recommended:** a second, unpublished `listen` on `api` (nginx) that forwards to `s3:9000` with the `Host` passed through, so no non-AWS image joins `api-internal` (ADR 0007 R13 stays as it is).
   - (b) `s3` joins `api-internal`, accepting the R13 revisit (silo is hardened but single-maintainer).
   - (c) A new internal network with `api-php` + `s3` (same exposure as b).
16. **Expense-delete cleanup.** Prefix listing of both buckets after the transaction? **Recommended: yes.** Alternative: read the receipt first and add a condition on it to the transaction (no list permission; retry on conflict).
17. **Failure reasons shown.** Coarse codes `unsupported-type`, `too-large`, `unreadable`? **Recommended: yes.** Alternative: one generic "invalid file".
18. **Policy endpoint status.** 201 with `Location` (like the other creates)? **Recommended: yes.** Alternative: 200.
19. **GD for unit tests.** If Step 0 R3 finds GD missing from the php dev image, add the `gd` extension to `docker/php/Dockerfile` (dev stage only; no Composer change)? **Recommended: yes.** Alternative: run the GD tests only inside the worker image, as integration tests.
20. **New dependencies.** None: `aws/aws-sdk-php` already has `PostObjectV4` and the presigners; GD comes from the Bref layer; no npm package. **Recommended: yes.** Any need found later comes back to you.
21. **Local runner timing.** Keep 8 s / 10 s unless Step 0 R2 shows a 40 MP thumbnail taking more than ~4 s; then raise them to 25 s / 30 s (an ADR 0007 note)? **Recommended: yes.**
22. **Security review scope.** opus/high on WP2–WP8, WP10, WP12 and the WP13 pass; WP9 and WP11 use the language reviewer? **Recommended: yes.** Alternative: also WP9 and WP11 (about two more opus runs).
23. **PR shape.** One PR, draft after WP6, squash-merged? **Recommended: yes.**
24. **Deletions.** Delete `ReceivedReceiptLogger` and its test once the processor replaces it (G5)? **Recommended: yes.**
25. **DELETE with no receipt.** 404, shown as "Already removed" (the Phase 3 pattern)? **Recommended: yes.** Alternative: always 204.
26. **"Worker cannot write originals" locally.** Enforced by the code split plus an architecture test; IAM enforces it on AWS (Phase 7)? **Recommended: yes.** Alternative: a restricted silo user and policy for the worker (more bootstrap code).
27. **PDF first-page render.** No: icon only (PLAN §7)? **Recommended: yes.**
