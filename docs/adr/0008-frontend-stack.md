# 0008. Front-end stack

- Status: Proposed
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
- **Chart.js** (via `vue-chartjs`) for the monthly summary.
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
