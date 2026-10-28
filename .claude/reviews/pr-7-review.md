# PR Review: #7 — Phase 4: front end

**Reviewed**: 2026-10-28
**Author**: javiervalero
**Branch**: feat/phase-4-frontend → main (head `d0cb8e1`)
**Decision**: COMMENT (draft PR). No CRITICAL or HIGH findings; would be APPROVE with comments.

## Summary

Whole-PR pass (130 files, +16,546 / −142) after every work package (WP1–WP11) and the gate G3 fixes were reviewed
individually (notes in `docs/plans/phase-4-frontend.md`). This pass looked for what per-WP reviews miss: consistency
across work packages, user-switch state, dead code, security boundaries and type safety. Reviewers: `ecc:vue-reviewer`
(front end) and `ecc:code-reviewer` (scripts, Makefile, configs, lock files), both sonnet/medium. Findings reconciled by
the main session.

## Findings

### CRITICAL
None.

### HIGH
None.

### MEDIUM
None. The reviewer's MEDIUM ("live-region convention broken in 5 places") was downgraded: `role="status"` regions in
CategoriesView, ExpenseFormView, CategoryRow and ServiceUnavailableView are the WP7–WP8 pattern (an implicit polite live
region). WP9/WP10 avoided it only to keep `[role="status"]` test queries unambiguous. Two valid patterns, not a defect.
The one real point is listed under LOW (L1).

### LOW

Behaviour:
- L1 `frontend/src/views/LoginView.vue:32`: "Your session ended" is a `v-if` region that appears with its text. It is
  shown on a fresh page load, where it is read in page order, so the impact is small.
- L2 `frontend/src/composables/useExpenseList.ts` `remove()` (~l.176): no generation guard; a delete answered after a
  new search can write `deletedIds`/`rowErrors` into the new list.
- L3 `frontend/src/lib/flash.ts:7`: the flash message is not cleared on user change (only on path change). No cross-user
  leak is reachable today: sign-out does a full page load.
- L4 `frontend/src/stores/categories.ts:98,112`: `rename`/`remove` lack the epoch check `create` has (harmless today).
- L5 `frontend/src/stores/session.ts:38-60`: `load()` has no generation guard (Retry only shows after a load settles).
- L6 `frontend/src/views/ExpenseFormView.vue`: requests without an AbortSignal (the generation counter makes answers harmless).

Security boundaries (defence in depth):
- L7 `frontend/src/lib/bfcache.ts:3,16`: `location.reload()` on an injected object, outside `navigation.ts`; the lint
  selectors only match `window.location`.
- L8 `frontend/eslint.config.ts`: `BroadcastChannel` is not a restricted global; only `sessionChannel.ts` needs it.
- L9 `frontend/src/api/http.ts:155`: `parsed.value as T` trusts response shape (documented, ADR 0011).
- L10 `scripts/check-bundle-csp.sh:~88`: string-form `setTimeout`/`setInterval` are not grepped (app code is covered by
  `no-implied-eval`).

Maintainability:
- L11 duplicated `describeExpenseError` / `describeCategoryError` switch; shared strings imported across feature files.
- L12 unused exports `NO_FILTERS` (`lib/expenseQuery.ts:19`, literal repeated twice), `trimmedLength` and `checkSearch`
  (`lib/text.ts`).
- L13 the v4-UUID pattern is defined three times (router, sessionChannel, expenseQuery).
- L14 `api/health.ts:5` `REQUEST_TIMEOUT_MS` shadows the one in `config.ts`; `api/health.spec.ts` is not in `__tests__`.
- L15 `ConfirmDeleteButton`'s `disabled` prop renders `aria-disabled`; the name suggests the native attribute.
- L16 `views/ExpenseFormView.vue` is 420 lines; `run()` in `useExpenseList.ts` is ~55 lines.

PR description:
- The branch also bumps phpunit to 13.4.5 (dev-only, GHSA-9vw2-f4vp-x7gf, commit `24655f7`); say so in the PR body.

## Outcome (owner decision, 2026-10-28)

- Fixed before ready: L2 (stale delete guard in `useExpenseList.remove()`), L3 (flash cleared on user change in
  `App.vue`), L7 (`bfcache.ts` reloads through `lib/navigation.ts`). Re-reviewed by `ecc:vue-reviewer`: no blocking
  findings. 1132 front-end tests, 100% coverage.
- L8 not fixed: ECC's config-protection hook blocks edits to `frontend/eslint.config.ts`; the owner chose a follow-up.
- All other LOWs are follow-ups, listed in `docs/plans/phase-4-frontend.md`.
- Not posted to GitHub (owner decision); linked from the PR body.

## Validation Results

| Check | Result |
|---|---|
| Type check | Pass (CI run 40739122009 on `d0cb8e1`, frontend job) |
| Lint | Pass (same run) |
| Tests | Pass (same run; locally 1130 front-end tests, 100% coverage) |
| Build | Pass (same run; check-bundle-csp passes) |

All 7 CI jobs passed on the head commit. A full local run follows in `/verification-loop`.

## Files Reviewed

130 files: 107 under `frontend/src` (added), front-end config (`vite.config.ts`, `vite/*`, `eslint.config.ts`,
`tsconfig.*.json`, `package.json`, `package-lock.json`: modified), `scripts/secrets-scan-worktree.sh` and
`scripts/check-bundle-csp.sh` with tests (added), `Makefile`, `.gitleaks.toml`, `backend/composer.lock`,
`docker/local-aws/composer.lock`, `README.md`, `docs/local-dev.md`, `docs/PLAN.md`, `docs/adr/0008`,
`docs/plans/phase-4-frontend.md` (modified).
