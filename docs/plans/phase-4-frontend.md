# Phase 4: Front-end. Implementation plan

| | |
|---|---|
| Status | **Approved 2026-10-26** (gate G1: the owner approved the plan and all seventeen section 10 decisions with the recommended defaults) |
| Phase | 4 (PLAN §12, essential track), size M |
| Branch | `feat/phase-4-frontend`, from `main` at 19552c0 (Phase 3 merged in PR #6) |
| Inputs | BRIEF; PLAN §5, §8, §9, §10, §12, §13; ADR 0002 (pagination), 0004 (incl. "Implemented in Phase 2"), 0005, 0008, 0011, 0012 §4.1; `contract/openapi.yaml` 0.11.0; [Phase 2 plan](phase-2-authentication.md) §7.2 and the WP12 notes; [Phase 3 plan](phase-3-categories-expenses.md) §7.2, and the notes from WP2, WP8, WP10, WP11 and WP13; the Phase 0 front end (`frontend/**`), `Makefile`, `docker-compose.yml`, `.github/workflows/ci.yml` |
| Drafted by | `ecc:planner` (opus/high). The main session still has to check it against the code |
| AWS | **None.** Phase 4 makes no AWS calls, uses no credentials and costs $0. The UI runs against the local Compose stack. |
| Back end | **Unchanged.** No PHP or contract change is planned. Decision 9 in section 10 is the only point that could change that. |

**Scope (PLAN §12, row 4):** the app shell, the sign-in and register pages, route guards, the expenses and categories UI, API types taken only from the generated `schema.gen.ts`, and Vitest tests.

**Not in scope:** receipts (Phase 5), the monthly summary page and its link (Phase 6), Playwright (L1), category colours and the chart (L5). See section 7.

**What exists today (the Phase 0 scaffold, read from the code):**
- **Runtime dependencies, all pinned exactly:** `vue` 3.5.43, `vue-router` 5.4.0, `pinia` 4.0.3.
- **Dev dependencies:** `vitest` 4.1.11, `@vitest/coverage-v8`, `@vue/test-utils` 2.5.1, `jsdom` 30.1.2, `vue-tsc` 3.3.12, `typescript` 6.0.3, ESLint 10 with `eslint-plugin-vue`, `typescript-eslint` and `@vitest/eslint-plugin`, Prettier, Vite 8.3.4.
- **Not installed:** an HTTP client, openapi-fetch, a date library, zod, `@pinia/testing`, axe.
- **`src/`:**
  - `main.ts` sets up Pinia and the router with `createWebHistory`.
  - `router/index.ts` exports `createAppRouter(history)` with one route, `/` → `HomeView` (an `<h1>`).
  - `App.vue` holds `<RouterView/>` and `<ApiStatus/>`.
  - `api/health.ts` is a fetch wrapper that never throws (timeouts through `AbortSignal`), with types from `paths`. **This is the pattern to follow.**
  - `api/schema.gen.ts` is generated and committed.
  - Specs: `__tests__/App.spec.ts` and `components/__tests__/ApiStatus.spec.ts`.
- **`vite/`:**
  - `devEnv.ts`: only `VITE_` variables reach the bundle, and a `VITE_*ORIGIN*` variable is refused.
  - `proxyHeaders.ts`: the `/api` proxy adds `X-Origin-Verify`, overwrites `CloudFront-Viewer-Address`, and answers 403 to a foreign `Origin`.
- **`vite.config.ts`:** Vitest with jsdom, `src/**/*.spec.ts`, and v8 coverage with an 80% bar on lines, branches, functions and statements.
- **Make targets (pinned `node:24.21.0-alpine` through `RUN_NODE`):** `frontend-install`, `frontend-lint` (ESLint, Prettier check, `vue-tsc --build`), `frontend-test`, `frontend-build`, `check-bundle-secrets`, `frontend-audit` (high and above), and `contract-gen` / `contract-check-types` for the types.
- **CI job `frontend`:** install, lint, test, build, check-bundle-secrets, audit. Job `contract`: fails if `schema.gen.ts` drifts.
- **Locally**, Vite on http://localhost:5173 proxies `/api` to nginx, so app and API share one origin, as on CloudFront.

**How CSRF works for the client (contract lines 14–18, 782–792; ADR 0004):**
- There is **no token**.
- Every state-changing request (any method except GET, HEAD and OPTIONS) must carry the fixed header `X-Requested-With: XMLHttpRequest`.
- The browser sets `Origin` itself, and the server compares it byte for byte with `APP_ORIGINS`.
- The session cookie `__Host-session` is `HttpOnly`, so JavaScript never sees it. Same-origin `fetch` sends it automatically.
- CloudFront forwards only `Origin`, `X-Requested-With`, `Content-Type`, `Accept`, `CloudFront-Viewer-Address` and cookies (ADR 0012 §4.1). **The client must not rely on any other request header**, such as `If-Match` or a request id.

## 1. Goal and definition of done

**Goal:** a person can use the app in a browser to register, sign in and out, manage their categories, and record, list, filter, edit and delete their expenses, against the real API on the local stack. The client keeps every guarantee the API gives: CSRF header, no cookie access, no open redirect, no HTML injection of user text, and versioned edits.

**Done means (each item can be shown):**
- **Browser walkthrough** (a checklist in `docs/local-dev.md`, run by the owner in Chrome and Firefox on http://localhost:5173, results pasted into the PR, gate G3):
  - register, sign out, sign in;
  - a wrong password gives a generic message, and the 11th failure shows "try again in about N minutes";
  - opening `/expenses` signed out goes to sign-in and comes back to `/expenses` after signing in;
  - create, rename and delete categories, including the empty state and a duplicate name;
  - create, edit and delete expenses;
  - filter by date range, by category (including Uncategorized) and by text; "Load more";
  - edit the same expense in two tabs: the second save gets a 409 with "Reload";
  - delete in two tabs: the second shows "already deleted";
  - sign out in one tab: the next action in the other tab goes to sign-in;
  - keyboard only; zoom at 200%.
- **Security properties, each pinned by a test or a lint rule:**
  - `X-Requested-With` on every unsafe request;
  - `credentials: 'same-origin'`;
  - no `document.cookie`, `localStorage` or `sessionStorage`;
  - no `v-html`, and no `console.*`;
  - the post-sign-in redirect accepts only internal routes;
  - a sign-out that fails with 5xx never shows "signed out";
  - nothing in memory survives a sign-out or a session expiry;
  - the built `index.html` has no inline script or style, and the bundle has no `eval` or `new Function`.
- **API types** come only from `schema.gen.ts`, through named aliases. There is no hand-written copy of a contract shape.
- **These make targets pass:** `make frontend-lint`, `make frontend-test` (≥ 80% on every metric; target ≥ 95% on `src/lib` and `src/api`), `make frontend-build`, `make check-bundle-secrets`, `make check-bundle-csp` (new), `make frontend-audit`.
- **Back end untouched:** `git diff main -- backend contract` is empty, unless decision 9 changes that.
- **CI:** the same 7 required checks are green. No job is added or renamed (decision 15 adds one *step* to `frontend`).
- **Docs:**
  - ADR 0008 gets an "Implemented in Phase 4" note;
  - PLAN §12 row 4 → Done, and the L5 row no longer lists the `q` UI;
  - the README plan list is brought up to date (it stops at Phase 1, `README.md:64`).

## 2. Design decisions (recommended defaults)

None of these changes an accepted ADR. If decision 1 (openapi-fetch) or decision 9 (a contract change) goes the other way, that point gets ADR 0013 first (gate G2).

| # | Topic | Recommendation | ADR impact |
|---|---|---|---|
| C1 | Dependencies | **No new runtime or dev dependency.** Use the installed vue, vue-router 5, pinia 4, Vitest and Test Utils. A store is reset with `setActivePinia(createPinia())`, not `@pinia/testing`. | ADR 0008 as written. **Decision 1** |
| C2 | API client | `src/api/http.ts`, a thin wrapper that extends the `health.ts` pattern:<br>• base path `/api/v1`;<br>• `Accept: application/json`;<br>• a JSON body with `Content-Type: application/json`;<br>• `credentials: 'same-origin'` set explicitly;<br>• a timeout (`REQUEST_TIMEOUT_MS = 10000`, to cover the Argon2id hash plus a cold start) combined with the caller's `AbortSignal`;<br>• **it never throws**: it returns `ApiResult<T> = {ok: true, status, data} \| {ok: false, error: ApiError}`.<br>Request and response types are indexed from `paths` / `components` (`src/api/types.ts` holds aliases only, such as `type Expense = components['schemas']['Expense']`). Success bodies are cast to the generated types: the server validates its responses against the contract in every integration test (ADR 0011). Problem bodies are narrowed from `unknown`. | ADR 0008 ("thin fetch wrapper"), ADR 0011 |
| C3 | CSRF and the cookie | `X-Requested-With: XMLHttpRequest` goes on **every** request. The API needs it on unsafe methods, and on GET it does no harm, so it can never be forgotten. No token. No other custom header (ADR 0012 §4.1). The client never reads the cookie: an ESLint `no-restricted-properties` rule bans `document.cookie`, and `no-restricted-globals` bans `localStorage` and `sessionStorage`. | ADR 0004, 0012 |
| C4 | Error mapping | `src/api/problem.ts` turns each status into an `ApiError` kind; the table below says what the UI shows. Field errors come from `errors[].pointer`: JSON-Pointer unescaping (`~1`, `~0`) gives the field name. The empty pointer `''`, and any pointer the form has no field for, become a **form-level** message. The server's `detail` texts (409, and 422 `errors[].detail`) are shown as they are: the server writes them for people and they never repeat a value. Every other status gets the client's own fixed text. `Retry-After` is read as a whole number of seconds ≥ 1. Missing or invalid means "try again later". | — |
| C5 | Session | Pinia `useSessionStore` (setup store):<br>• `status`: `unknown`, `signed-in`, `signed-out` or `unreachable`;<br>• `user`: `{id, email}`;<br>• `ensureLoaded()` calls `GET /auth/me` **once** (the promise is memoised).<br>200 means signed in. 401 means signed out. Network failure or 5xx means `unreachable`: a full-page "Can't reach the server" with a Retry button, **never** a misleading redirect to sign-in.<br>**Sign-out:** `POST /auth/logout`. On 204, a full page load of `/login` (`window.location.assign`), so nothing in memory survives. On 5xx or a network failure the user stays signed in and sees "Sign-out failed, try again" (Phase 2 WP12 note).<br>**A 401 in the middle of a session**, from any secured call (never from login or `me`): one global handler, which runs only once, does a full page load of `/login?redirect=<current>&reason=expired`. `reason` is a fixed value; the page shows "Your session ended. Sign in again." | ADR 0004, 0008. **Decision 7** |
| C6 | Routes | History mode, as `main.ts` already uses. The router is created with `sensitive: true` (Step 0 R1). `meta`: `title`, `access` (`'private'` by default, `'guest'`, or `'public'`), typed in `src/router/meta.d.ts`.<br>• `/` → redirect to `/expenses`<br>• `/login`, `/register` (guest only: a signed-in user is sent to `/expenses`)<br>• `/expenses`<br>• `/expenses/new`<br>• `/expenses/:id(<lower-case v4 uuid regex>)` (edit)<br>• `/categories`<br>• `/:pathMatch(.*)*` → NotFound (public)<br>A malformed id is a client-side 404 with no request. The guard in `router/guards.ts` returns a value; it does not call `next()` (Step 0 R1). | ADR 0008 |
| C7 | Safe redirect | `safeRedirect(raw, router)`:<br>• the value must be a single string, at most 2,048 characters;<br>• it must start with `/` and not with `//` or `/\`;<br>• it must contain no control characters and no whitespace;<br>• `router.resolve()` must match a route that is `private` and is not NotFound.<br>It returns the resolved `fullPath`, never the raw value. Anything else gives `/expenses`. | — |
| C8 | Money | **Input:** `<input type="text" inputmode="decimal">`. `parseAmount()` works on the string, never through a float: digits with an optional single `.` or `,` and 1–2 decimals, no sign, no thousands separators, no exponent, 0.01 to 1,000,000.00 (that is, 1 to 100,000,000 cents, `MIN_CENTS` / `MAX_CENTS` in `src/config.ts`, as in the contract).<br>**Display:** `Intl.NumberFormat(APP_LOCALE, {style: 'currency', currency: APP_CURRENCY})`, fed the exact decimal string `"1234.56"` built from the integer.<br>**Edit:** the field is pre-filled with `"12.50"`. | Phase 3 B1/B2. **Decisions 2, 3** |
| C9 | Dates | `<input type="date" min="2000-01-01" max="2099-12-31">`, default "today" in the **local** calendar (`getFullYear/getMonth/getDate`, never `toISOString()`, which is UTC). A date-only string is never parsed into a local `Date`. Display goes through `Intl.DateTimeFormat(APP_LOCALE, {dateStyle: 'medium', timeZone: 'UTC'})` on `Date.UTC(y, m-1, d)`, inside a `<time datetime>`. No date library. | Phase 3 B3. **Decision 4** |
| C10 | Text rules | Client checks are a convenience: the server's 422 decides. **The client may be looser than the server, never stricter.** Length is counted in code points after `trim()`: `[...s.trim()].length`, against names 1–50, descriptions 1–200, `q` ≤ 100, passwords 12–128 on register and ≤ 128 on sign-in. The 50/200 rule is never put in a `maxlength` attribute: it counts UTF-16 units, and the schema's `maxLength` is the server's byte pre-check (Phase 3 WP8 notes). User text is rendered only by interpolation, inside `<bdi>` (bidi isolation), with CSS `overflow: hidden; overflow-wrap: anywhere` and a bounded `line-height`, so combining-mark floods are clipped (Phase 3 WP2 notes). | — |
| C11 | Expense list | **Filters live in the page URL:** `from`, `to`, `category` (a uuid or `none`) and `q`. They are validated on the way in; an invalid value is dropped, never sent on. They are applied with an **Apply** button (a form submit), not search-as-you-type.<br>**API query:** built with `URLSearchParams`. Empty values are left out, because the API answers 400 to an unknown or empty parameter. `limit=50` is always sent. `from ≤ to` is checked before sending.<br>**Paging:** "Load more" sends the **same filters** plus `cursor` and appends the items. The button shows while `nextCursor` is not null, whatever the length of the page. An **empty** page with a non-null cursor is followed automatically, up to `MAX_AUTO_FOLLOW = 3` extra requests per click; after that a "Keep searching" button appears (each request can read up to 1,000 items). The cursor never goes into the page URL.<br>**Stale responses:** a filter change aborts the request in flight and bumps a counter, so an older response is dropped. A 400 on a cursor request restarts from page one once. | ADR 0002 ("Load more"). **Decisions 5, 6** |
| C12 | Editing and 409 | A `PATCH` sends `version` plus **only the members that changed** (`buildExpensePatch(original, form)`). With nothing changed, no request is sent and the page says "Nothing to save". `categoryId: null` means "make it Uncategorized". **409:** the server's `detail` plus a **Reload** button, which loads the latest version and resets the form ("your unsaved changes will be lost"). A rename 409 can be a taken name or a stale version; the `detail` says which, and "Reload" is harmless in both cases. **404** on edit or save: "This expense no longer exists", with a link to the list. Double submits are blocked (button disabled while pending), because creates have no idempotency key. | Phase 3 B12. **Decision 9** |
| C13 | Delete | Two steps inline: "Delete" becomes "Confirm delete" + "Cancel", with focus moved to "Cancel". **A 404 on delete counts as success** ("Already deleted") and the row is removed. A category delete explains that its expenses become Uncategorized. | Phase 3 WP10 notes. **Decision 8** |
| C14 | State | Pinia holds only the session (C5) and the categories (`useCategoriesStore`: `items`, `load()` once per session, `create`, `rename`, `remove`, `refresh()`). The expense list is a composable local to the view (`useExpenseList`). A 422 at `/categoryId` (the category was deleted elsewhere) triggers `refresh()` and a field message. An expense whose `categoryId` is not in the cached list triggers one `refresh()`; if it is still missing, the expense shows as "Uncategorized". **No categories yet:** the expense form offers "Uncategorized" plus a link "Add a category" (Phase 3 B7). | ADR 0008. **Decision 10** |
| C15 | Accessibility (WCAG 2.2 AA, scoped) | Inside the realistic scope:<br>• every input has a `<label>`; errors are linked through `aria-describedby` and `aria-invalid`;<br>• on a failed submit, focus goes to the first invalid field, or to the form-level `role="alert"` message;<br>• `<form novalidate>`, so the app's own messages are announced;<br>• a skip link and `<main id="main">`;<br>• on every route change, focus moves to the view's `<h1 tabindex="-1">` and `document.title` = `"<meta.title> · Expense tracker"` (never user text);<br>• real `<button>`s, visible focus, contrast ≥ 4.5:1, targets ≥ 24 px (2.5.8);<br>• `autocomplete` `username` / `current-password` / `new-password`, paste never blocked (3.3.8);<br>• a "Show password" toggle;<br>• the list is a `<table>` with a `<caption>` and `th scope`;<br>• "N more loaded" is announced in a polite live region.<br>Not in scope: automated axe checks (L1), screen-reader matrix testing. | — . **Decision 12** |
| C16 | Security hygiene | ESLint as errors: `vue/no-v-html`, `vue/no-v-text-v-html-on-component`, `no-console`, `no-eval`, `no-implied-eval`, `no-new-func`, plus the C3 restrictions. No `target="_blank"`, no third-party resource or link. No `VITE_` variable is needed. Unexpected errors go to `app.config.errorHandler`, which shows a generic banner and logs nothing. **CSP compatibility:** the Vue runtime-only build; styles extracted to files; `scripts/check-bundle-csp.sh` fails on any inline `<script>` without `src`, any `<style>`, `style=` or `on*=` attribute or `javascript:` in `dist/index.html`, and on `eval(` or `new Function` in `dist/assets/*.js`. The target CSP for Phase 7 is written in ADR 0008's note: `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`; Phase 5 adds the S3 hosts. | ADR 0005 (CSP comes from CloudFront). **Decision 15** |
| C17 | Styling | Plain CSS: `src/assets/main.css` with custom properties (colours, spacing, focus ring), plus `<style scoped>` in components. A single-column layout that reflows at 320 px; light theme only. No CSS framework. | ADR 0008. **Decision 11** |
| C18 | Leftovers from Phase 0 | `ApiStatus` stays, moved into the footer. `HomeView.vue` is **deleted** (`/` now redirects); this needs the owner's yes (G5). `health.ts` is unchanged. | — . **Decision 14** |
| C19 | Contract | **No change.** 409 causes are not parsed from `detail` (C12). | ADR 0011. **Decision 9** |

**Error mapping (C4).** The `kind` is from `src/api/problem.ts`; the UI column says what the user sees.

| Status / failure | `kind` | UI |
|---|---|---|
| network, timeout | `network`, `timeout` | "Can't reach the server. Check your connection and try again." After a **write** that timed out, add: "It may have been saved; check the list before trying again." Form data is kept. |
| 400 | `bad-request` | A cursor request restarts once (C11). Otherwise: "The request was not accepted. Reload the page and try again." |
| 401 from login | `invalid-credentials` | "Email or password is incorrect." (one message, ADR 0004) |
| 401 from a secured call | `unauthorized` | Global handler (C5) |
| 403 | `forbidden` | "The request was blocked. Reload the page and try again." (CSRF or origin; never seen in normal use) |
| 404 | `not-found` | Depends on where: edit means "no longer exists"; delete means success, "Already deleted" |
| 409 | `conflict` | Register: "An account with this email already exists. Sign in instead." with a link. PATCH: `detail` plus "Reload". Category create: `detail` at form level. |
| 413, 415 | `client-bug` | Generic message |
| 422 | `validation` | Field errors by pointer; `''` and unknown pointers at form level; focus on the first error |
| 429 | `rate-limited` | "Too many attempts. Try again in about N minutes." (under 60 s: "N seconds"). Without `Retry-After`: "later". |
| 503 | `unavailable` | "The service is busy. Try again in N seconds." Register adds "If you tried before, your account may exist: try signing in." (contract `Conflict` text) |
| 500, anything else | `server` | "Something went wrong on our side. Try again." |

## 3. Work packages

**One commit per WP. Tests are written first, and the red run is recorded in the WP review notes. Every WP is S or S–M.**

**Order:** step 0 → guardrails (WP2) → pure helpers (WP3) → API core (WP4) → session and guards (WP5) → shell (WP6) → auth pages (WP7) → categories (WP8) → expense form (WP9) → expense list (WP10) → security pass, docs and walkthrough (WP11). Everything runs on one branch, one WP after another.

**Where commands run:**
- Only through make targets, in the pinned Node container (`RUN_NODE`). **Never `npm` or `node` on the host**, and never `npm install <package>`: no dependency is added without an owner decision.
- One spec at a time: `make -n frontend-test`, then swap the npm script, as `docs/local-dev.md` "Running one test" describes.
- No background jobs or hand-written polling loops in agent briefs.
- WPs 2–10 need **no stack**. WP11 uses `make up` for the walkthrough.

**"Docker" in the WPs:**
- "container" = `docker run --rm` of the pinned Node image through make;
- "stack" = Compose through `make up`.

### Shared Docker host rule (every WP)
The Docker host is shared with other projects. An agent may only:
- use the repo's make targets;
- use `docker compose -p expense-tracker …` (`up`, `stop`, `start`, `run --rm`, `logs`, `ps`, `down` **without** `-v`);
- use `docker run --rm` for containers it starts itself.

It may remove **only** containers, networks or volumes that it created in this session, by name. It must **never** run any `docker … prune`, `docker rm $(docker ps -aq)`, `docker compose down -v`, or `docker volume rm` on a volume it did not create. If a cleanup seems to need any of these, it stops and asks the owner.

### Endpoint-security rule (every WP)
The owner's machine runs company endpoint security. An agent must **never** download, vendor, generate or run:
- password lists, wordlists or leaked-credential dumps;
- XSS, payload or fuzzing collections, or malware samples;
- offensive tools, or any port or network scan (localhost included);
- curl-pipe-to-shell installers.

XSS and redirect tests use a few **hand-written** strings in the spec files, such as `<img src=x onerror=…>` as plain text, `//evil.example`, `/\evil.example`, `javascript:…`, a bidi override and a combining-mark run. If a step plausibly needs anything else, the agent stops and the owner decides.

### Model routing summary (`.claude/rules/project/model-routing.md`)
- **Implementation:** `ecc:tdd-guide`, sonnet/medium, one brief per WP: file paths, the named tests, the make targets; no host npm.
- **Review, one reviewer per language per diff:**
  - `ecc:vue-reviewer` (sonnet/medium) for any diff with `.vue` files;
  - `ecc:typescript-reviewer` (sonnet/medium) **instead** of it for pure-TS diffs (WP3, WP4). Never both on one diff.
- **Security:** `ecc:security-reviewer`, **opus/high**, on WP4 (CSRF, credentials, error mapping), WP5 (session, guards, redirect), WP7 (auth forms, throttle and error messages), WP10 (URL → API query, cursor, rendering of user text) and the whole-front-end pass in WP11. Round 2 (fix hunks only) also runs at opus/high. WP3, WP6, WP8 and WP9 rely on the vue/ts reviewer's security checklist plus the C16 lint rules (**decision 16**).
- **ESLint config, Makefile, CI, shell script (WP2):** inline by the main session if under about 30 lines; otherwise `ecc:code-reviewer` (sonnet/medium).
- **Docs:** `ecc:doc-updater` (haiku/low), every line checked by the main session.
- **Test runs and summaries:** `general-purpose` haiku/low.
- **Build, lint or type errors:** `ecc:build-error-resolver` sonnet/low first, then opus/high.
- **Pipeline per WP:** tdd-guide builds test-first → the main session re-runs `make frontend-lint frontend-test` (plus `frontend-build check-bundle-csp check-bundle-secrets` from WP2 on) → reviewer(s) → one fix list back to tdd-guide → security round 2 on the fix hunks → notes in §7.3 → commit.

### Step 0: Research & Reuse (before WP2), run by the main session
`ecc:docs-lookup` needs Context7, which has been missing since Phase 1. So: `general-purpose` sonnet/medium with WebFetch (every claim with a URL), plus checks inside the pinned container via `docker run --rm` by the main session. GitHub search is read-only (`gh search code`).

| # | Question | Needed by |
|---|---|---|
| R1 | vue-router **5.4** navigation guards: return-value guards versus `next()`; how to read `to.meta`; `router.resolve()` on `//host`, `/\host` and encoded paths; custom regex params; `createMemoryHistory` in tests; any breaking change from v4 that the v4 docs would hide | WP5 |
| R2 | Pinia **4** setup stores: resetting a store without `$reset`; testing with `setActivePinia(createPinia())` | WP5, WP8 |
| R3 | **openapi-fetch** versus a thin wrapper: bundle size, how it types header parameters (`X-Requested-With` is a declared param), middleware for 401, and the cost of amending ADR 0008. Also read `gh search code` for a Vue 3 + Pinia session-guard pattern worth copying. | WP4 (decision 1) |
| R4 | `Intl.NumberFormat` with a **decimal-string** argument and `currency` in the pinned Node 24 alpine image (full ICU?) and in current Chrome, Firefox and Safari; the output for `en-IE` / `EUR` | WP3 |
| R5 | Is a runtime `process.env.TZ` change in a Vitest worker honoured? This lets the date tests run under `Pacific/Kiritimati` and `Pacific/Pago_Pago`. `<input type="date">` value format and min/max in jsdom 30 | WP3 |
| R6 | jsdom 30: `AbortSignal.any` and `timeout`, `Response`/`Headers`, `form.requestSubmit`, `HTMLDialogElement.showModal` (only if decision 8 picks a modal) | WP4, WP9 |
| R7 | `vite build` (Vite 8): does `dist/index.html` hold any inline script or style (the modulepreload polyfill)? Does any chunk contain `new Function` / `eval(` (Vue runtime-only build)? | WP2 |
| R8 | Do the ESLint 10 flat config rules named in C16 exist under those names in the installed plugins? | WP2 |

### Step 0 results (2026-10-26)

Documentation answers came from a `general-purpose` agent (sonnet/medium), with a source URL for each. The main
session ran the checks marked **ran** in the pinned `node:24.21.0-alpine` image against the installed packages.

| # | Result | Effect on the plan |
|---|---|---|
| R1 | vue-router 5 has no breaking change for v4-style apps ([v5.0.0 release](https://github.com/vuejs/router/releases/tag/v5.0.0), [migration](https://router.vuejs.org/guide/migration/v4-to-v5)). Guards that return a value are the documented form; `next()` still works but is discouraged ([guards](https://router.vuejs.org/guide/advanced/navigation-guards.html)). `RouteMeta` augmentation and regex params are unchanged. **Ran** `router.resolve()` (5.4.0, production mode): `//evil.example`, `/\evil.example`, `https://evil.example`, `/%2F%2Fevil.example` and `javascript:alert(1)` all resolve to the catch-all (NotFound), but `//evil.example` and `/\evil.example` keep that `href`, which a browser reads as another site. `fullPath` is not normalised. **Ran:** route matching is **case-insensitive by default**, so an upper-case UUID matched the id route and `/EXPENSES` matched `/expenses`; `createRouter({sensitive: true})` makes both NotFound. | C7 unchanged: the string checks (start with `/`, not `//` or `/\`, no control characters) come first, then the matched route must be private and not NotFound. **C6: create the router with `sensitive: true`** (WP5); test the upper-case id and `/EXPENSES`. |
| R2 | Pinia 4 setup stores still have no automatic `$reset`; tests use `setActivePinia(createPinia())` in `beforeEach` ([state](https://pinia.vuejs.org/core-concepts/state.html), [testing](https://pinia.vuejs.org/cookbook/testing.html)). Call `useXStore()` inside a guard, never at module top level ([outside components](https://pinia.vuejs.org/core-concepts/outside-component-usage.html)). The Pinia 4 changes are ESM only and the `@vue/devtools-api` v8 peer, which is installed (8.2.1, **ran**). | None. Decision 7 (a full page load on sign-out) means no store needs `$reset`. |
| R3 | No small, well-known example repo was found. The official pattern is a `beforeEach` that calls the store inside the guard (link above), plus a memoised "load the current user" promise. | C5 as written. |
| R4 | `format(string)` uses the string as an exact decimal (ECMA-402 NumberFormat v3; [MDN](https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Intl/NumberFormat/format)). It needs Chrome 106, Firefox 116 and Safari 15.4 or later (MDN browser-compat-data). **Ran** (Node ICU 78.3, full data): `'1234.56'` → `€1,234.56`, `'0.01'` → `€0.01`, `'1000000.00'` → `€1,000,000.00`, `'10.05'` → `€10.05`; the date `Date.UTC(2026, 9, 26)` with `timeZone: 'UTC'` → `26 Oct 2026`. | C8 and C9 as written. The browser floor goes in the WP11 docs. |
| R5 | **Ran:** changing `process.env.TZ` at runtime is honoured. The same instant gives 27 Oct in `Pacific/Kiritimati` and 25 Oct in `Pacific/Pago_Pago`. | WP3 date tests switch the time zone inside the spec and restore it afterwards. |
| R6 | **Ran** (jsdom 30.1.2): `AbortSignal.any`, `AbortSignal.timeout` and `requestSubmit` exist; **`HTMLDialogElement.showModal` does not**. In Vitest's jsdom environment, `fetch`, `Response`, `Headers`, `AbortController` and `AbortSignal` are Node's own (Vitest source `integrations/env/jsdom.ts`). | Decision 8 (no modal) avoids the gap. API tests stub `fetch` with `vi.stubGlobal`. |
| R7 | **Ran** `make frontend-build`: `dist/index.html` has one external `<script type="module" src>` and no inline script or style; the bundle has no `new Function` or `eval(`. The modulepreload polyfill is bundled into the entry chunk, not inline (Vite source `plugins/html.ts`). Assets smaller than `assetsInlineLimit` become `data:` URLs. | WP2: set `build.assetsInlineLimit: 0`, so `img-src 'self'` holds, and make `check-bundle-csp` fail on `data:` in `index.html`. |
| R8 | **Ran:** every rule in C16 exists in the installed ESLint 10, eslint-plugin-vue 10.11 and typescript-eslint 8.71. **`vue/no-v-html` is only `warn`** in `flat/recommended`, and `npm run lint` (`eslint .`) does not fail on warnings. `@typescript-eslint/no-implied-eval` needs type-aware linting, which the config does not enable. | WP2: set `vue/no-v-html` to `error` explicitly and keep the core `no-implied-eval`. The WP2 fixture check must show a `v-html` failing lint. |

### Part A: Foundations

#### WP1: Plan (S)
- **Files:** this plan; PLAN §12 row 4 links it.
- **Agent:** the main session. **Commit:** `docs: add phase 4 implementation plan`.

#### WP2: Guardrails: lint rules, config constants, CSP bundle check (S)
- **Goal:** the security lint rules are in force before any UI code exists, and CSP compatibility becomes a build check.
- **Files:**
  - `frontend/eslint.config.ts` (the C3 and C16 rules for `src/**`; `vite/**` keeps `console` and Node globals);
  - `frontend/src/config.ts`: `APP_CURRENCY`, `APP_LOCALE`, `PAGE_SIZE = 50`, `MAX_AUTO_FOLLOW = 3`, `MIN_CENTS`, `MAX_CENTS`, `DATE_MIN`, `DATE_MAX`, `NAME_MAX = 50`, `DESCRIPTION_MAX = 200`, `Q_MAX = 100`, `PASSWORD_MIN = 12`, `PASSWORD_MAX = 128`, `REQUEST_TIMEOUT_MS`, each with a comment pointing to its contract line;
  - `scripts/check-bundle-csp.sh` and `scripts/tests/check-bundle-csp.test.sh` (run by `make test-shell`, in CI job `guards`);
  - `Makefile`: target `check-bundle-csp`;
  - `.github/workflows/ci.yml`: one step in job `frontend` after "Production build" (decision 15).
- **Tests first (RED):**
  - `check-bundle-csp.test.sh` with fixture `dist` folders: a clean one passes; it fails on an inline `<script>`, `<style>`, `style=`, `onclick=`, `javascript:`, `new Function(` or `eval(`.
  - An ESLint fixture check: a temporary file with `v-html`, `console.log` and `document.cookie` fails `make frontend-lint`. Shown, then removed.
- **Done:** `make frontend-lint frontend-build check-bundle-csp check-bundle-secrets test-shell` green; the current bundle passes.
- **Docker:** container. **Review:** inline if under ~30 lines, else `ecc:code-reviewer` (sonnet/medium).
- **Commit:** `chore: add front-end security lint rules, config constants and the bundle csp check`.

#### WP3: Pure helpers: text, money, dates (S)
- **Files:** `frontend/src/lib/{text,money,dates}.ts` and `src/lib/__tests__/*.spec.ts`.
- **Tests first (RED):**
  - `text.spec.ts`:
    - `codePointLength('😀')` is 1, and a ZWJ family emoji counts its code points (as the server does);
    - `trimmedLength('  Food ')` is 4;
    - 50 emoji pass the 50 rule, 51 fail;
    - the client is never stricter than the server for an NBSP-padded name.
  - `money.spec.ts`:
    - `parseAmount` accepts `12`, `12.5`, `12,50`, `0.01` and `1000000.00` (giving 1200, 1250, 1250, 1, 100000000);
    - it refuses `0`, `0.00`, `-1`, `1.234`, `1,234.56`, `1e2`, `12.`, `.5`, `1000000.01`, an empty string and `１２` (full-width digits), each with its own reason;
    - `formatCents(1)` is "€0.01" and `formatCents(100000000)` is "€1,000,000.00", with no float rounding (`formatCents(1005)`);
    - `centsToInput(1250)` is `"12.50"`.
  - `dates.spec.ts`:
    - `todayLocal()` uses the local date at 23:30 in UTC−11 and at 00:30 in UTC+14 (R5);
    - `isIsoDate` accepts `2024-02-29` and refuses `2023-02-29`, `2026-1-05`, `1999-12-31` and `2100-01-01`;
    - `formatDate('2026-10-26')` gives "26 Oct 2026" in every time zone tried.
- **Done:** 100% line and branch coverage of `src/lib`.
- **Docker:** container. **Review:** `ecc:typescript-reviewer`.
- **Commit:** `feat: add text, money and date helpers for the front end`.

#### WP4: API client core (S–M), security-critical
- **Files:** `frontend/src/api/{http,problem,retryAfter,types}.ts` and specs. `setUnauthorizedHandler()` lives in `http.ts`; it fires only for calls flagged `secured`.
- **Tests first (RED),** with `fetch` stubbed (`vi.stubGlobal`):
  - POST, PATCH and DELETE carry `X-Requested-With: XMLHttpRequest`, and so does GET;
  - every call has `credentials: 'same-origin'`, `Accept: application/json` and the `/api/v1` prefix;
  - a JSON body sets `Content-Type: application/json`;
  - no other custom header is ever sent;
  - a 204 gives `{ok: true, data: undefined}`;
  - a 422 problem maps `/description` to the `description` field, `''` to the form level, `/a~1b` to the field `a/b`, and an unknown pointer to the form level;
  - a problem body with no `title`, invalid JSON or `text/html` gives `server`, never a crash;
  - `Retry-After`: `"120"` gives 120; `"0"`, `"-5"`, `"abc"` and an HTTP-date give `null`;
  - 429 and 503 carry `retryAfterSeconds`;
  - a timeout gives `timeout`, and a caller abort gives `aborted` (not shown);
  - a 401 on a `secured` call calls the handler once, even for three parallel 401s; a 401 on a non-secured call does not;
  - a type-level test (`expectTypeOf`) pins that `types.ts` aliases equal `components['schemas'][…]`.
- **Done:** 100% coverage of `src/api` core.
- **Docker:** container. **Review:** `ecc:typescript-reviewer`; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add the typed api client with csrf header and problem-details mapping`.

### Part B: Session, shell and auth pages

#### WP5: Auth API, session store, route guards, safe redirect (S–M), security-critical
- **Files:**
  - `frontend/src/api/auth.ts`;
  - `src/stores/session.ts`;
  - `src/router/{index,guards,redirect}.ts`, `src/router/meta.d.ts`;
  - placeholder views (one `<h1>` each, replaced in WP7–WP10), `NotFoundView.vue`, `ServiceUnavailableView.vue`;
  - `main.ts` wiring: the unauthorized handler does `location.assign` to `/login?redirect=…&reason=expired`.
- **Tests first (RED):**
  - **Store:**
    - `ensureLoaded` calls `/auth/me` once for many callers;
    - 200 gives signed in, 401 gives signed out, 503 or a network failure gives `unreachable`;
    - logout 204 calls `location.assign('/login')`;
    - logout 503 or a network failure keeps `signed-in` and returns an error;
    - login and register store the user.
  - **Guards** (memory history):
    - signed out on `/expenses?from=2026-01-01` goes to `/login?redirect=%2Fexpenses%3Ffrom%3D2026-01-01`;
    - signed in on `/login` or `/register` goes to `/expenses`;
    - `unreachable` shows the service view, not `/login`;
    - NotFound is reachable signed out;
    - `/expenses/NOT-A-UUID` and an upper-case uuid resolve to NotFound;
    - `/` redirects to `/expenses`.
  - **`safeRedirect`:**
    - accepts `/expenses?q=a`;
    - refuses `//evil.example`, `/\evil.example`, `https://evil.example`, `javascript:alert(1)`, `/%2F%2Fevil.example` (resolves to NotFound), ` /expenses`, `/expenses\n`, an array value, `/login`, an unknown path and a 2,049-character path;
    - always returns the resolved `fullPath`.
- **Docker:** container. **Review:** `ecc:vue-reviewer`; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add session store, route guards and safe post-sign-in redirect`.
- **Open the PR as a draft after this WP** (decision 17). Ask before the push.

#### WP6: App shell, shared form components, base CSS, focus management (S–M)
- **Files:**
  - `frontend/src/App.vue`: skip link, `AppHeader`, `<main id="main">`, footer `ApiStatus`, global error banner;
  - `src/components/{AppHeader,FormField,FormAlert,ConfirmDeleteButton}.vue`;
  - `src/router/focus.ts` (`afterEach`: title and focus);
  - `src/assets/main.css`;
  - `src/main.ts` (`errorHandler`);
  - `src/__tests__/App.spec.ts` updated;
  - `src/views/HomeView.vue` deleted (G5).
- **Tests first (RED):**
  - the header shows the user's email (escaped: an email-like string with `<b>` renders as text) and links to Expenses and Categories only when signed in;
  - "Sign out" calls the store; on failure an alert shows "Sign-out failed" and the user stays signed in;
  - after navigation, `document.title` is "Categories · Expense tracker" and focus is on the `h1`;
  - `FormField` links the label, `aria-describedby` and `aria-invalid`;
  - `ConfirmDeleteButton` asks first, Cancel restores focus, and Confirm emits once;
  - the error handler shows the banner and calls no `console` method.
- **Docker:** container. **Review:** `ecc:vue-reviewer`.
- **Commit:** `feat: add the app shell, shared form components and focus management`.

#### WP7: Sign-in and register pages (S–M), security-critical
- **Files:** `frontend/src/views/{LoginView,RegisterView}.vue`, `src/composables/useFormErrors.ts`, and specs.
- **Tests first (RED):**
  - **Login:**
    - success replaces the route with `safeRedirect(query.redirect)`; a hostile `redirect` goes to `/expenses`;
    - `reason=expired` shows "Your session ended", and any other `reason` shows nothing;
    - 401 shows the one generic message;
    - 429 with `Retry-After: 897` shows "about 15 minutes";
    - 422 at `/password` sits on the password field;
    - the submit button is disabled while pending (one request on a double click);
    - a password over 128 code points is caught client-side, and 12 emoji are accepted;
    - `autocomplete` is `username` / `current-password`;
    - the show/hide toggle switches `type`;
    - paste is not prevented.
  - **Register:**
    - 201 goes to `/expenses`;
    - 409 shows "already exists" with a link to sign-in;
    - 11 code points is refused client-side and 12 accepted;
    - `new-password`;
    - 503 shows the "may exist, try signing in" hint.
  - **Both:** focus moves to the first error, or to the form alert.
- **Docker:** container. **Review:** `ecc:vue-reviewer`; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add sign-in and register pages`.

### Part C: Categories and expenses

#### WP8: Categories (S–M)
- **Files:** `frontend/src/api/categories.ts`, `src/stores/categories.ts`, `src/views/CategoriesView.vue`, `src/components/CategoryRow.vue`, and specs.
- **Tests first (RED):**
  - the empty state says "No categories yet";
  - create adds the item in the server's order (the list is re-sorted by `items` from the response or a refresh);
  - a name of 51 code points is refused client-side and `"  Food  "` is accepted;
  - a 409 on create shows the `detail` at form level;
  - rename sends `{name, version}`; a 409 shows the `detail` plus "Reload", and "Reload" refreshes the row's version;
  - Escape cancels a rename;
  - delete warns "its expenses become Uncategorized"; 204 or 404 removes the row (the 404 says "Already deleted");
  - a name like `<img src=x onerror=alert(1)>` is rendered as text inside `<bdi>`;
  - the store `refresh()` replaces the items, and `reset` empties them.
- **Docker:** container. **Review:** `ecc:vue-reviewer`.
- **Commit:** `feat: add the categories page`.

#### WP9: Expense API and expense form, create and edit (S–M)
- **Files:** `frontend/src/api/expenses.ts` (list, get, create, update, delete), `src/lib/expensePatch.ts`, `src/views/ExpenseFormView.vue`, and specs.
- **Tests first (RED):**
  - **New:**
    - the date defaults to local today;
    - the category select lists "Uncategorized" plus the categories; with none, it shows the "Add a category" link;
    - the amount `12,50` sends `amountCents: 1250` (an integer in the JSON);
    - Uncategorized leaves `categoryId` out;
    - 201 goes to `/expenses` with "Expense saved" announced;
    - a 422 at `/categoryId` refreshes the categories and shows the field message;
    - a double click sends one request;
    - a timeout shows the "may have been saved" hint.
  - **`buildExpensePatch`:**
    - only changed members plus `version`;
    - a category changed to Uncategorized gives `categoryId: null`;
    - no change gives `null` (no request, "Nothing to save");
    - an unchanged trimmed description is not sent.
  - **Edit:**
    - loads by id;
    - 404 shows "no longer exists";
    - 409 shows "Reload", which re-fetches and resets the form;
    - a `''`-pointer 422 shows at form level;
    - "Delete" on the edit page treats 404 as "Already deleted" and goes back to the list.
- **Docker:** container. **Review:** `ecc:vue-reviewer`.
- **Commit:** `feat: add the expense form for creating and editing`.

#### WP10: Expense list, filters, Load more (S–M), security-critical
- **Files:** `frontend/src/lib/expenseQuery.ts` (URL query ↔ filters ↔ API params), `src/composables/useExpenseList.ts`, `src/components/{ExpenseFilters,ExpenseTable}.vue`, `src/views/ExpensesView.vue`, and specs.
- **Tests first (RED):**
  - **`expenseQuery`:**
    - `?from=2026-10-01&category=none&q=lunch` round-trips;
    - an invalid `from`, an upper-case uuid, a repeated `category`, an unknown `foo=1` and an empty `q` are dropped and never sent to the API;
    - `from > to` is refused before any request;
    - `q` over 100 code points is refused;
    - the API params always include `limit=50` and never an empty value.
  - **`useExpenseList`:**
    - the first page renders;
    - "Load more" sends the same filters plus `cursor` and appends;
    - the button is hidden only when `nextCursor` is null;
    - a short page with a non-null cursor keeps the button;
    - an empty page with a cursor auto-follows at most 3 times, then shows "Keep searching";
    - a filter change aborts the request in flight, and a late response is ignored;
    - a 400 on a cursor request restarts once.
  - **View:**
    - empty state, with or without filters;
    - amounts formatted, dates in `<time>`;
    - `categoryId: null` shows "Uncategorized";
    - an unknown id triggers one category refresh;
    - a description with markup and a bidi override is rendered as text inside `<bdi>`;
    - inline delete: 204 or 404 removes the row;
    - "Apply" pushes the filters to the URL and focus stays on the results heading;
    - the live region announces "N more loaded".
- **Docker:** container. **Review:** `ecc:vue-reviewer`; **security-reviewer (opus/high)**; round 2 at opus/high.
- **Commit:** `feat: add the expense list with filters and load more`.

### Part D: Wrap-up

#### WP11: Whole-front-end security pass, docs, browser walkthrough (S)
- **Security pass:** `ecc:security-reviewer` (opus/high) over `frontend/src` as a whole, asked to find:
  - any path where user text reaches HTML, an attribute, a URL or `document.title`;
  - any state that survives sign-out;
  - any unsafe call without the header;
  - any redirect sink.
  Fixes go back to tdd-guide; round 2 on the fix hunks.
- **Docs** (doc-updater haiku/low, checked line by line by the main session):
  - `docs/local-dev.md`: "Using the app in a browser", with the section 1 walkthrough as a checklist, the Chrome/Firefox-only note (Safari, WebKit bug 281149), and how to make "Load more" appear (a short curl loop that creates 60 expenses with the existing `H` headers);
  - `README.md`: the plans list and a quick start line;
  - ADR 0008 "Implemented in Phase 4": C1–C19 in short, plus the target CSP (C16);
  - PLAN §12 row 4 → Done, and the L5 row without `q`;
  - this plan's status → Done.
- **Gate G3:** the owner runs the checklist on `make up` (the main session cannot drive a browser). Results go in the PR body.
- **Before ready:** `/update-docs`, `/code-review`, `/verification-loop`, `/ecc:cost-report` (compare with Phase 3).
- **Docker:** stack (`make up`; `make down` without `-v` afterwards only if the owner wants).
- **Commit:** `docs: phase 4 browser walkthrough, readme and adr 0008 notes`.

## 4. Dependencies

```
WP1 ─► Step 0 ─► WP2 ─► WP3 ─► WP4 ─► WP5 ─► WP6 ─┬─► WP7 ───────────────┐
                                                  └─► WP8 ─► WP9 ─► WP10 ─┴─► WP11
```
- WP4 needs WP3 (`config.ts` from WP2, and none of the helpers). WP5 needs WP4's unauthorized hook.
- WP7–WP10 need the shell components (WP6).
- WP9 needs the categories store (WP8). WP10 reuses the delete button and `expenses.ts` (WP9).
- They run one after another: WP5–WP10 all touch `router/index.ts`.

## 5. Test plan

| Layer | What | Where | Run by |
|---|---|---|---|
| Unit | Text, money, dates, `expensePatch`, `expenseQuery`, `safeRedirect`, problem mapping, `Retry-After` | `frontend/src/{lib,api,router}/__tests__/*.spec.ts` | `make frontend-test` (CI `frontend`) |
| Store / composable | Session, categories, `useExpenseList` (fake `fetch` or mocked api modules) | `src/{stores,composables}/__tests__` | same |
| Component | Shell, forms, rows, table, views (`@vue/test-utils`, memory router, `vi.mock` of the api modules as in `ApiStatus.spec.ts`) | `src/{components,views}/__tests__`, `src/__tests__/App.spec.ts` | same |
| Type level | Aliases equal the generated schema types; `vue-tsc` catches contract drift | specs + `make frontend-lint` | CI `frontend`, `contract` |
| Static | Lint rules C3/C16; CSP bundle check; secrets bundle check | `eslint.config.ts`, `scripts/check-bundle-csp.sh` (+ shell test) | `make frontend-lint`, `check-bundle-csp`, `test-shell` |
| End to end | **Manual** walkthrough on the Compose stack, written in `docs/local-dev.md` (G3). Playwright is L1 (deviation approved 2026-10-09, PLAN §12) | — | the owner |

- **Coverage:** ≥ 80% on every metric (already enforced by `vite.config.ts`). Target ≥ 95% on `src/lib`, `src/api` and `src/router`.
- **Test style:** AAA; names describe the behaviour; strings are invented by hand (endpoint-security rule); no test depends on layout (jsdom has none).
- **The back-end suites are not run per WP** (nothing changes there). `/verification-loop` in WP11 runs everything once. Never two integration runs at once.

## 6. Branch, PR, CI and owner gates

- **Branch** `feat/phase-4-frontend`. **One PR**: draft after WP5, ready after WP11, squash-merged. The body holds the definition of done, the Step 0 answers and the walkthrough results.
- **Ask before every push.** After a push, watch CI by run id and check `headSha`.
- **CI:** 7 required checks, unchanged. Decision 15 adds one **step** to job `frontend`; no job is added or renamed (G4 does not fire).
- **Deletions:** `frontend/src/views/HomeView.vue` (WP6). Asked separately (G5).
- **Gates:**
  - **G1:** approve this plan, including section 10.
  - **G2:** only if decision 1 or 9 goes against the recommendation (ADR 0013, accepted before the WP that needs it).
  - **G3:** the owner runs the browser walkthrough.
  - **G4:** only if a CI job is added or renamed.
  - **G5:** deletions.
  - **G6:** PR merge.
  - **AWS:** no gate ($0, nothing created).

## 7. Not in Phase 4, and carried items

### 7.1 Carried items → where they land

| Carried item | Source | Phase 4 |
|---|---|---|
| Every non-GET call sends `X-Requested-With` | Phase 2 §7.2 | C3; WP4 (on every call) |
| `fetch` keeps `credentials: 'same-origin'` | Phase 2 §7.2 | C2; WP4 (explicit) |
| 401 → Login | Phase 2 §7.2 | C5; WP4 hook, WP5 wiring |
| 429 → "try again in N" from `Retry-After` | Phase 2 §7.2 | C4; WP4, WP7 |
| Safari and `__Host-` on localhost | Phase 2 R1 | WP11 docs (Chrome/Firefox) |
| Never show "signed out" when logout answers 5xx | Phase 2 WP12 notes | C5; WP5, WP6 |
| Emails rendered only through Vue escaping | Phase 2 §7 (line 680) | C10/C16; WP6 test |
| Unicode normalisation of passwords: decide before real accounts exist | Phase 2 (line 681) | **Decision 12**: none (byte for byte, as the server) |
| Format `amountCents` with the app currency | Phase 3 B2, §7.2 | C8; WP3 |
| Send `version` on PATCH; on 409 offer "reload" | Phase 3 §7.2, WP10 notes | C12; WP8, WP9 |
| "Load more" follows `nextCursor`, not page length; same filters with a cursor; `limit` 1–100 | Phase 3 §7.2, WP11 notes | C11; WP10 |
| Empty-categories state | Phase 3 B7 | C14; WP8, WP9 |
| Count characters by code point; clip overflow | Phase 3 WP2 notes | C10; WP3, WP8, WP10 |
| `maxLength` on `name` is the byte pre-check, not the rule | Phase 3 WP8 notes | C10 (no `maxlength` attribute at the rule) |
| 404 on delete = "already gone"; `''` pointer → form level | Phase 3 WP10 notes | C4, C13; WP4, WP8–WP10 |
| The cursor is URL-safe | Phase 3 WP13 | Sent through `URLSearchParams`; never put in the page URL (C11) |

### 7.2 Not in Phase 4 (with the reason)
- **Phase 5:** receipt upload, progress and preview in `ExpenseFormView`; `connect-src` / `img-src` for the S3 hosts added to the target CSP.
- **Phase 6:** the summary page and a "Summary" nav item (decision 13); it reuses `formatCents`.
- **Phase 7 (carried, important):**
  - **SPA deep links on AWS.** S3 answers 403/404 to `/expenses/<id>`. Use a **CloudFront Function on the default (`/*`) behaviour only**, rewriting extension-less paths to `/index.html`. **Not** distribution-wide custom error responses: those would also turn the API's 401/403/404 problem responses into a `200 index.html`, which breaks C4, C5 and C13.
  - The CSP from C16 in the response-headers policy.
  - `Cache-Control`: `no-cache` on `index.html`, immutable on hashed assets.
  - `Referrer-Policy: same-origin` or stricter (the page URL can hold `q`).
  - Correct content types on upload.
- **L1:** Playwright journeys from the WP11 checklist, plus axe checks.
- **L5:** category colours, the chart.
- **Out of scope (BRIEF, or not asked):** totals or page numbers, CSV export, i18n, multi-currency, dark theme, offline/PWA, password change or reset, account deletion, "sign out everywhere", idempotency keys for creates.

### 7.3 Review notes
As in Phases 1–3, each WP's findings (and anything carried) are added here as "Notes from the WPn reviews" before the next WP starts. That includes the red runs observed, and tests written after the code (mutation-checked).

### Notes from the WP2 reviews (2026-10-26)

- **Red runs:**
  - The shell test failed on every case before the script existed (exit 127).
  - After the review, 11 new cases failed before their fixes.
  - Before the config change, the lint probe files produced no lint problems at all.
- **Review:** `ecc:code-reviewer` (sonnet/medium) found no critical or high issues. All fixes were applied test-first.
  - The check now also scans CSS for `url(data:…)`.
  - It looks for `.js`, `.mjs` and `.css` files anywhere under `dist`, and exits 2 when there is no script at all.
  - It no longer runs a `grep -q` inside a pipe, which `pipefail` could hide.
  - `style=`, `on*=` and `<style>` are checked with quoted attribute values emptied, so a `meta content` no longer causes a false positive.
  - The URL rules (`javascript:`, `data:`, another origin) look only at `src`, `href`, `action`, `formaction`, `poster` and `srcset` values.
  - A `/` before `on*=` or `style=` is caught, for example `<img/onerror=…>`.
  - New rule: no third-party `src` or `href` (`http:`, `https:` or `//`).
- **Lint (C3, C16):**
  - Errors: `window.*` and `globalThis.*` access to `document`, `localStorage` and `sessionStorage`, so app code uses `document` directly. `document.write` and `writeln`. Assignments to `innerHTML` and `outerHTML`, and `insertAdjacentHTML`. A static `target="_blank"`, through `vue/no-restricted-syntax`: `vue/no-template-target-blank` alone missed internal links, so it stays on only as a backstop for dynamic ones.
  - `no-implied-eval` needed `window`, `setTimeout` and `setInterval` declared as globals before it fired.
  - The final probe gave 11 errors and no warnings. The probe files were deleted.
- **Accepted gaps:**
  - Indirect `eval` (`(0,eval)(x)`) and a bare `Function(` are not searched for in the bundle: the false-positive risk is high, and the lint rules cover our own source.
  - A dynamic `:target="'_blank'"` is only partly covered.
- **Not covered by a test:** `src/config.ts`, which stays at 0% coverage until WP3 and WP4 import it. Overall coverage is 83.3%.

### Notes from the WP3 reviews (2026-10-26)

- **Red runs:**
  - The three specs failed on import before any helper existed.
  - After the review, 10 tests failed against the old result-returning display helpers.
  - The time-zone mutation proof: with `timeZone: 'UTC'` removed, `formatDate` gave "25 Oct 2026" under `Pacific/Pago_Pago` and `America/Los_Angeles`. `Pacific/Kiritimati` (UTC+14) cannot catch that mutation, so the negative-offset zones are the ones that matter.
- **Deviation from C10, approved by the reviewer:**
  - Lengths are counted after trimming with the **server's own list**: `PlainText::TRIM_CHARACTERS`, `backend/src/Http/Input/PlainText.php:36-38`.
  - The plan said `String.prototype.trim()`. That trims the same set plus U+FEFF, which the server refuses as invisible anyway. So `trim()` could only make the client looser, and the explicit list makes the client match the server exactly.
  - NUL, U+0085 and U+FEFF are not trimmed.
- **Review:** `ecc:typescript-reviewer` (sonnet/medium) approved, with no critical or high findings. Applied:
  - **Time-zone test:**
    - The formatters are module-level constants.
    - The `formatDate` test sets `TZ`, then calls `vi.resetModules()` and re-imports, so it can fail. Before, a cached formatter created under UTC hid the mutation.
  - **Display helpers:** `formatCents`, `formatDate` and `centsToInput` return strings. They fall back to `UNKNOWN_VALUE` ('—'), or `''` for an input field, because they only format server data the contract already validated. `parseAmount`, the `check*` helpers and `isIsoDate` keep result types with reasons.
  - **Typing:**
    - `tsconfig.app.json` and `tsconfig.vitest.json` add `ES2023.Intl`. `vitest` had `lib: []`.
    - `decimalString` is typed `` `${number}` `` with one narrow cast; `as unknown as number` is gone.
    - It changes types only: the browser floor from Step 0 R4 is unchanged.
  - **Other fixes and cases:**
    - No cast on the regex match.
    - The constant is renamed `MAX_WHOLE_DIGITS`.
    - An explicit month range check.
    - New amount cases: `00.50`, many leading zeros, the 9-digit `too-large` boundary, and `1,5`.
    - A test name that claimed a float check was renamed.
- **Accepted:**
  - The sign-in password check refuses an empty password: it can never authenticate, and refusing it saves a throttled attempt.
  - `trimmedLength` is kept for the WP8 and WP9 counters.
- **Result:** 165 front-end tests; `src/lib` at 100% on every metric.

## 8. Risks and decision points

| # | Risk / trigger | Mitigation / fallback | WP |
|---|---|---|---|
| R1 | vue-router 5 behaves differently from the v4 docs | Step 0 R1; guards and redirect fully unit-tested on a memory router | WP5 |
| R2 | Client and server disagree on lengths or trimming | Code points, client looser never stricter, the server's 422 is shown; emoji and NBSP cases | WP3, WP7–WP10 |
| R3 | Float errors in money | String parsing, integers only, exact decimal string to `Intl` | WP3 |
| R4 | Date off by one in some time zones | No `Date` parsing of date-only strings; tests under UTC−11 and UTC+14 (R5) | WP3 |
| R5 | Open redirect after sign-in | C7, with hostile cases; only the resolved `fullPath` is used | WP5 |
| R6 | A previous user's data seen after sign-out or expiry on a shared machine | A full page load on sign-out and on a mid-session 401 (C5); nothing in web storage (lint) | WP5 |
| R7 | Stale list responses after a filter change; expensive empty-page loops | Abort plus a generation counter; `MAX_AUTO_FOLLOW` | WP10 |
| R8 | Duplicate expense after a timeout or 503 retry | Button disabled while pending; "may have been saved" hint; idempotency keys out of scope (would need a contract change) | WP9 |
| R9 | The bundle breaks the Phase 7 CSP | `check-bundle-csp` in CI from WP2 (R7) | WP2 |
| R10 | Deep links 403 on AWS, or API errors masked by custom error pages | Carried to Phase 7 as a CloudFront Function on `/*` only (§7.2) | Phase 7 |
| R11 | XSS through names, descriptions or email | Interpolation only, `vue/no-v-html`, `<bdi>`, hostile-text tests, WP11 pass | all |
| R12 | Coverage drops as views arrive | Test first; the 80% bar fails `make frontend-test` | all |
| R13 | jsdom gaps (dialog, layout) | No modal by default (decision 8); no layout-dependent assertions | WP6–WP10 |
| R14 | A new dependency fails `frontend-audit` at high | No new dependencies (decision 1) | all |
| R15 | Scope creep (receipts, summary, totals, colours) | §7.2 | all |
| R16 | Shared Docker host damaged; flagged tools; host npm | §3 rules repeated in every brief | all |

**Inconsistencies found in the docs** (none blocks Phase 4; WP11 fixes the ones in our own docs):
1. `docs/PLAN.md:283` lists "Free-text search (`q`)" under L5. But `q` is in the API (`contract/openapi.yaml:539-548`; Phase 3 owner decision 6, `docs/plans/phase-3-categories-expenses.md:756`), and Phase 3 B24 (`…:85`) says the `q` UI moves to L5 only "if not built in Phase 4". This plan builds it (decision 6). WP11 updates the L5 row.
2. `docs/PLAN.md:184` puts "Expense form (with receipt upload, progress and preview)" under one page, while receipts are Phase 5 (`docs/PLAN.md:269`). Phase 4 builds the form without receipts; Phase 5 extends it.
3. `.claude/rules/ecc/typescript/coding-style.md:180` says "Use Zod for schema-based validation", but ADR 0008 (`docs/adr/0008-frontend-stack.md:17-18`) and ADR 0011 (`docs/adr/0011-openapi-contract.md:21-22`) want a thin wrapper and types only from the generated schema. The project ADRs win: no Zod (decision 1).
4. `.claude/rules/ecc/common/patterns.md:27-31` wants a response envelope with `total/page/limit`. The API deliberately has neither (`docs/adr/0002-dynamodb-single-table-data-model.md:67-68`, Phase 3 B20). The client follows the contract.
5. `.claude/rules/ecc/typescript/testing.md:14` and `ecc/common/testing.md` require Playwright E2E. Deferred to L1 by the owner-approved deviation (`docs/PLAN.md:291-293`).
6. `docs/plans/phase-3-categories-expenses.md:388` (WP11 plan) says `q` `maxLength: 100`. The contract has 1424 as an outer bound (`contract/openapi.yaml:548`; notes at `…phase-3…md:671`). The client uses the 100-code-point rule, never `maxLength`.
7. `docs/plans/phase-2-authentication.md:578` says "every non-GET call", while the contract (`contract/openapi.yaml:14-16`) exempts GET, HEAD and OPTIONS. Moot: C3 sends the header on every call.
8. Wording only: `docs/adr/0011-openapi-contract.md:44` speaks of "two front-end dev tools", but the generator lives in `contract/package.json:14-18`, not in `frontend/`.
9. `README.md:64` lists only the Phase 0 and 1 plans (fixed in WP11).
10. Possibly confusing, not contradictory: `docs/local-dev.md:112` says not to use `127.0.0.1`, while `APP_ORIGINS` (`docs/local-dev.md:224`) and `frontend/vite.config.ts:41` allow it. The WP11 docs say why (the cookie).

## 9. Lessons from Phases 2–3, applied

1. **Test first, red recorded per WP.** Tests written after the code are mutation-checked and say so in §7.3.
2. **Fail closed:** the API client never throws, unknown problems become a generic error, and an unreachable session is never shown as "signed out".
3. **Security round 2 at opus/high** on the fix hunks of every security WP. Reviewers confirm that each new test fails without its fix.
4. **Make-only:** reviewers and agents never run host `npm`/`node` (the Phase 3 WP7 lesson about host PHP).
5. **Briefs:** no background jobs or polling loops; containers removed only by name; invented test strings only.
6. **Haiku docs are checked** by the main session, which runs every command in them. The walkthrough is run by the owner, not assumed.
7. **Small refactors in separate commits**, re-reviewing only the changed hunks.
8. `/ecc:cost-report` at the end, compared with Phase 3 (about $78).

## 10. Owner decisions (recommended defaults)

1. **Dependencies.** No new ones: a thin `fetch` wrapper per ADR 0008; no openapi-fetch, zod, date library, axe or `@pinia/testing`? **Recommended: yes.** Alternative: openapi-fetch (small, typed from the same schema, 401 middleware), which amends ADR 0008 through ADR 0013 (G2).
2. **Currency and display locale.** `EUR` with `en-IE` (shows "€1,234.56" and "26 Oct 2026"), as constants in `src/config.ts`? **Recommended: yes.** Alternatives: another currency or locale; a build-time `VITE_APP_CURRENCY`.
3. **Amount input.** A text field (`inputmode="decimal"`) that accepts `.` or `,` as the decimal separator, at most 2 decimals, no thousands separators? **Recommended: yes.** Alternative: `type="number"` (locale quirks, floats).
4. **Dates.** Native `<input type="date">`, default today (local); the list shows "26 Oct 2026"? **Recommended: yes.** Alternative: show ISO `2026-10-26`.
5. **Pagination.** "Load more", 50 per page, an empty page followed automatically up to 3 times per click, then "Keep searching"? **Recommended: yes.** Alternatives: never auto-follow; follow until something is found (unbounded reads).
6. **Filters.** Kept in the page URL (including `q`), applied with an "Apply" button; the text search UI built now? **Recommended: yes.** Alternatives: search-as-you-type (more requests, each up to 1,000 reads); `q` out of the URL; the `q` UI left to L5.
7. **Sign-out and session expiry.** A full page load to `/login`, so nothing in memory survives? **Recommended: yes.** Alternative: reset each store in place (smoother, but every new store must remember to join the reset).
8. **Delete confirmation.** A two-step inline button, no modal? **Recommended: yes.** Alternatives: a `<dialog>` modal (ADR 0008 lists "modal"); `window.confirm`.
9. **409 causes.** No contract change: show the server's `detail` and always offer "Reload" on a PATCH 409? **Recommended: yes.** Alternative: additive problem `type` URIs (`stale-version`, `name-taken`, `category-limit`); a back-end, contract and test change with php and security review, placed before WP8.
10. **State.** Pinia for the session and categories only; the expense list in a view composable? **Recommended: yes.**
11. **Styling.** Plain CSS with custom properties and scoped styles, a single-column responsive layout, light theme, no framework? **Recommended: yes** (ADR 0008).
12. **Register form.** One password field with "Show password", no confirm field; no Unicode normalisation of passwords in the client? **Recommended: yes.** Alternative: NFC in the client; that has to be decided now, before real accounts exist, or it later needs a migration (Phase 2 note).
13. **Monthly summary link.** No placeholder in Phase 4; Phase 6 adds a "Summary" nav item? **Recommended: yes.**
14. **Phase 0 leftovers.** Keep `ApiStatus` in the footer; delete `HomeView.vue`? **Recommended: yes** (the deletion is still asked at G5).
15. **CSP check in CI.** `make check-bundle-csp` as a step in the existing `frontend` job (no new job)? **Recommended: yes.** Alternative: the make target only, run locally.
16. **Security review scope.** opus/high on WP4, WP5, WP7, WP10 plus the WP11 whole-front-end pass (the other WPs rely on the language reviewer and the lint rules)? **Recommended: yes.** Alternative: security review on every UI WP (about four more opus runs).
17. **PR shape.** One PR, draft after WP5, squash-merged? **Recommended: yes.**
