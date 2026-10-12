# Model Routing (project rule)

> Project-owned. Supersedes the model guidance in `ecc/common/performance.md`
> (its "Haiku is 3x cheaper" figure is outdated). Do not edit the `ecc/` rules;
> ECC updates overwrite them.

## Why

Token spend is dominated by two things: the orchestrating main session (long
context, re-read every turn) and the fan-out of ECC agents per work package.
Prices per 1M tokens, input / output (checked 2026-10-12):

| Tier | Model | Input | Output | Relative to Sonnet |
|------|-------|-------|--------|--------------------|
| haiku | Haiku 5.5 | $0.10 | $0.50 | ~1/20 |
| sonnet | Sonnet 5.5 | $2 | $10 | 1x |
| opus | Opus 5.5 | $4 | $20 | 2x |
| fable | Fable 5.1 | $10 | $50 | 5x |

Rule of thumb: pay for reasoning only where a wrong answer is expensive to
undo (architecture, security, tricky debugging). Everything mechanical goes
to the cheapest tier that does it reliably.

## Routing table

Always pass `model` explicitly on every Agent call so routing is visible and
does not silently inherit the main session's model. Pass `effort` as listed
(this rule is the owner's explicit instruction to set it).

| Task | Agent | model | effort |
|------|-------|-------|--------|
| Phase/feature planning, ADRs, cross-cutting design | ecc:planner, ecc:architect | opus | high |
| Security review of auth, sessions, uploads, IAM, input handling | ecc:security-reviewer | opus | high |
| Debugging after one failed fix attempt | ecc:build-error-resolver / tdd-guide | opus | high |
| Test-first implementation of a work package | ecc:tdd-guide | sonnet | medium |
| Code review of a diff (PHP / Vue / TS / general) | ecc:php-reviewer, ecc:vue-reviewer, ecc:typescript-reviewer, ecc:code-reviewer | sonnet | medium |
| Terraform / CI review | ecc:code-reviewer | sonnet | medium |
| Build, lint, or type errors (first attempt) | ecc:build-error-resolver | sonnet | low |
| Docs, READMEs, codemaps, ADR formatting | ecc:doc-updater | haiku | low |
| Library/API doc lookups | ecc:docs-lookup | haiku | low |
| Codebase searches, "where is X", file inventories | Explore | haiku | low |
| Running test suites / linters and summarising output | general-purpose | haiku | low |
| Commit messages, PR descriptions, changelog text | general-purpose (or inline) | haiku | low |

`fable` is never used by default. It is reserved for a problem where opus at
high effort has demonstrably failed, and only after asking the owner.

## Fan-out discipline

- **One reviewer per language per diff.** Run the language reviewer
  (php/vue/typescript). Add ecc:code-reviewer only for cross-cutting diffs
  (infra, CI, multi-language). Do not run both on a single-language diff.
- **Security reviewer only when triggered.** Run it when the diff touches
  the triggers in `ecc/common/code-review.md` (auth, user input, DB queries,
  file/S3 ops, crypto, IAM policies). Otherwise the language reviewer's
  security checklist is enough.
- **Small diffs skip the agent.** For changes under ~30 lines that are
  docs/config/typo-level, the main session reviews inline instead of
  spawning a reviewer.
- **Do not fork for routine work.** `fork` always runs on the main
  session's model and inherits its whole context; use a fresh typed agent
  with an explicit cheaper `model` instead.
- **Brief agents tightly.** Give file paths and the exact question, not
  "read the plan and figure it out"; ask for concise reports (findings
  only, no restating the diff).
- **Re-review only the fix.** After addressing review findings, re-review
  just the changed hunks, not the whole work package.

## Main session

The main session orchestrates, reconciles agent output, and enforces
guardrails. Recommended: `/model opusplan` (Opus while in plan mode,
Sonnet while executing). Switch to `/model opus` for a whole session only
when the session is mostly design or incident work.

## Measuring

Run `/ecc:cost-report` at the end of each phase and compare with the
previous phase. If a downgraded tier causes rework (a reviewer misses a
real bug, or tdd-guide needs repeated retries), move that row up one tier
and note the change here with the date.
