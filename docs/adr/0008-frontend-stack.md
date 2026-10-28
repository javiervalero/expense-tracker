# 0008. Front-end stack

- Status: Accepted (2026-10-09)
- Date: 2026-10-07

## Context

The front-end must be Vue. It's a small single-page app: a few forms, a filtered list, a file upload with preview,
and one chart.

## Decision

- **Vue 3** (Composition API, `<script setup>`), written in **TypeScript**.
- **Vite** for development and builds.
- **Vue Router** for navigation, with guards that send signed-out users to Login.
- **Pinia** for shared state: the current user, categories.
- **A thin `fetch` wrapper** that adds the CSRF header and turns problem-details errors into form errors. No HTTP
  library.
- **Plain CSS** (custom properties) and a handful of shared components. No UI kit.
- **Chart.js** (via `vue-chartjs`) for the monthly summary chart, added in phase L5. Until then the summary is a
  table ([`../PLAN.md`](../PLAN.md) §12).
- **Quality tools:**
  - Vitest + Vue Test Utils for unit and component tests;
  - ESLint + Prettier;
  - `vue-tsc` for type checks.

## Alternatives considered

- **Nuxt.** Server-side rendering isn't needed for a signed-in app, and it would require a Node server.
- **A UI kit (Vuetify, PrimeVue).** Faster to build with, but adds a large dependency and hides the basics that this
  project aims to show.
- **Plain JavaScript.** TypeScript catches mismatches with the API contract early.

## Consequences

- Small bundle and simple tooling; the code stays easy to follow.
- We build a few basic components ourselves (form field, modal, table).
- Builds are static files, deployed to S3 behind CloudFront ([ADR 0005](0005-single-origin-through-cloudfront.md)).

## Implemented in Phase 4 (2026-10-28)

Decisions C1–C19 and the review notes are in [the Phase 4 plan](../plans/phase-4-frontend.md). None changes this ADR; the points below make it precise.

- **Dependencies (C1):** no new runtime or dev dependency. Pinia stores are reset with `setActivePinia(createPinia())`, not `@pinia/testing`.
- **API client (C2–C4, C19):** `src/api/http.ts` extends the `health.ts` pattern and never throws; it returns `ApiResult<T>`. Types come only from `schema.gen.ts`, through named aliases in `src/api/types.ts`. Problem bodies are mapped to an `ApiError` kind in `src/api/problem.ts`. The contract is unchanged.
- **CSRF and cookie (C3):** `X-Requested-With: XMLHttpRequest` on every request, `credentials: 'same-origin'`, no token and no other custom header. The client never reads the cookie: lint bans `document.cookie`, `localStorage` and `sessionStorage`.
- **Session and routes (C5–C7):** `useSessionStore` calls `GET /auth/me` once. 401 means signed out; a network failure or 5xx means "unreachable", never a redirect to sign-in. A 401 in the middle of a session sends the user once to `/login` with `reason=expired`. Routes use history mode with `sensitive: true`, and the post-sign-in redirect accepts only internal private routes.
- **Money and dates (C8, C9):** amounts are parsed from the string, never through a float, and displayed from the exact decimal string. Dates are never parsed into a local `Date` from a date-only string; the default date is the local calendar day.
- **User text (C10):** the client is never stricter than the server, and length is counted in code points. User text is rendered only by interpolation inside `<bdi>`; there is no `v-html`.
- **Expense list (C11–C13):** filters live in the page URL and are applied with Apply. "Load more" appends pages, and stale responses are dropped. A PATCH sends `version` plus only the changed members; a 409 offers Reload. A 404 on delete counts as "Already deleted".
- **State (C14):** Pinia holds only the session and the categories. The expense list is a composable local to its view.
- **Accessibility (C15, WCAG 2.2 AA, scoped):** labels, `aria-describedby`, focus moved on each route change, a skip link, a captioned table, and a polite live region for "N more loaded". Automated axe checks (L1) and screen-reader matrix testing are out of scope.
- **Security hygiene and CSP (C16):** ESLint errors for `v-html`, `console`, `eval` and `new Function`; the Vue runtime-only build; styles in files; `scripts/check-bundle-csp.sh` checks the built `index.html` and bundle. The target CSP for Phase 7 is below. Phase 7 applies it at CloudFront, and Phase 5 adds the S3 hosts.

  `default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'`
- **Styling (C17):** plain CSS with custom properties, `<style scoped>` in components, a single column that reflows at 320 px, light theme only.
- **Phase 0 leftovers (C18):** `ApiStatus` moved into the footer. `HomeView.vue` is deleted, and `/` redirects to `/expenses`.
- **Cross-tab session (found in the WP11 security review):** a sign-out in one tab sends the other tabs to `/login`, and a sign-in as a different user reloads them, through `BroadcastChannel('expense-tracker-session')` (`src/lib/sessionChannel.ts`). It is a signal only: nothing is stored (C3), messages are parsed strictly, and a tab with no user ignores a sign-out. Pages that show categories make their own request on mount and draw names only after it answers, so a session that expired on the server reaches the 401 handler before any cached data is shown.
- **Monotonic `Date.now` in tests:** on the WSL2 development host the wall clock steps back by about 600 ms. Vue's event invoker then drops a handler for an event that a test triggers, so a click or submit silently does nothing. The test setup uses a monotonic `Date.now` (`frontend/vite/monotonicClock.ts`, applied through `test.setupFiles`). It is a test-only fix; no assertion was weakened.
- **Always-rendered live regions:** the polite live regions are always in the DOM and are never `display:none` while empty, so screen readers can announce the first message. They use `aria-live="polite"` without `role="status"`, so they do not collide with the `[role="status"]` queries.
