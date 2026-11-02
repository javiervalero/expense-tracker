# Expense Tracker

A personal expense tracker, built as a realistic proof of concept: small in scope, but built and deployed the way a
production application would be. Users sign in, record expenses with categories, attach receipts and see monthly
summaries. The front end is a Vue single-page app, the API is a JSON API in vanilla PHP (no framework), and the
target is a fully serverless AWS deployment. The whole system also runs locally with Docker and no AWS account.

Phases 0 to 6 are done: the repository, the local stack, the checks and CI; the API core (router, middleware,
problem-details errors, the DynamoDB access layer and the OpenAPI contract tests); sign-up, sign-in and cookie sessions;
categories and expenses; the Vue front end; receipts (a direct-to-S3 upload, a worker that checks the file and makes
a thumbnail, and a preview on the expense page); and the monthly summary (a report endpoint, a summary page, and a curl
smoke test of every feature). The Terraform infrastructure and the first AWS deployment are still to come; see [PLAN.md](docs/PLAN.md) for the phases and their status.

## Prerequisites

- Docker with Compose v2 (the `docker compose` command)
- `make`
- `git`

You do not need PHP, Composer or Node on the host: the PHP and Node tools run in pinned containers.

On macOS, `make lint` also needs `brew install bash coreutils`: two guard scripts (`make check-image-pins` and
`make lint-workflows`) need bash 4 or later and GNU `realpath` and `base64`, while macOS ships bash 3.2 and BSD tools.
The Makefile finds Homebrew's coreutils by itself and adds nothing to your `PATH`. Linux needs nothing extra.

## Quick start

```bash
make frontend-install   # once: front-end dependencies, in a Node container
make php-install        # once: back-end dependencies, in a PHP container
make contract-install   # once: OpenAPI contract tooling (lint, bundle, types), needed by the tests
make local-aws-install  # once: dependencies of the local AWS stand-ins, needed by the tests
make up                 # creates .env, starts the stack and waits until it is healthy
make smoke              # after make up: curl smoke test of every feature (see docs/local-dev.md)
```

To use the app in a browser, open http://localhost:5173 in Chrome or Firefox (not Safari); see [Using the app in a browser](docs/local-dev.md#using-the-app-in-a-browser).

Then open:

- the app: http://localhost:5173 (Vite, which proxies `/api` to the API)
- the API health check: http://localhost:5173/api/v1/health (through the proxy, which adds the origin header; a direct call to port 8080 needs it too, see [docs/local-dev.md](docs/local-dev.md#calling-the-api-directly))

The API also has register, login, me and logout, with a cookie session. For curl examples, see
[Signing in from the command line](docs/local-dev.md#signing-in-from-the-command-line).
For categories and expenses, see [Categories and expenses from the command line](docs/local-dev.md#categories-and-expenses-from-the-command-line).

Stop the stack with `make down`. It removes the containers and never removes volumes; see
[docs/local-dev.md](docs/local-dev.md) before deleting anything.

The full local guide, with the service table, the make targets, how to run one test and troubleshooting, is
[docs/local-dev.md](docs/local-dev.md).

## Repository map

| Path | What it holds |
|------|---------------|
| `backend/` | The PHP API: `public/` (entry point), `src/`, `tests/`, Composer project |
| `frontend/` | The Vue 3 and TypeScript single-page app (Vite, Vitest, ESLint) |
| `contract/` | The OpenAPI contract (`openapi.yaml`) and the tooling that lints it and generates the front-end types |
| `docker/` | Dockerfiles and config for the local stack: PHP, nginx, ElasticMQ, the worker, and `local-aws/` (bootstrap, S3 event relay, worker runner) |
| `docker-compose.yml` | The local stack: emulators, the API, the worker and the front-end dev server |
| `infra/` | Terraform: the state bootstrap and the `dev` environment (no AWS resources applied yet), and `dynamodb/table.json`, the one table definition used locally and by Terraform |
| `scripts/` | Guards and checks run by `make` and CI (image pins, workflow audit, fixtures, Terraform wrapper) and their tests in `scripts/tests/` |
| `docs/` | Requirements (`BRIEF.md`), the plan (`PLAN.md`), ADRs, the local dev guide and the phase plans |
| `.github/` | GitHub Actions workflows (CI) and the zizmor configuration |
| `Makefile` | The developer entry points: run `make help` for the list |

## Documentation

- [PLAN.md](docs/PLAN.md): the approved plan: summary, decisions, architecture, phases and status
- [docs/adr/](docs/adr/): architecture decision records (start with the [index](docs/adr/README.md))
- [docs/local-dev.md](docs/local-dev.md): local development: services and ports, the make targets, tests, troubleshooting
- [docs/plans/](docs/plans/): the work-package plans for each phase (Phase 0: [phase-0-foundations.md](docs/plans/phase-0-foundations.md), Phase 1: [phase-1-backend-core.md](docs/plans/phase-1-backend-core.md), Phase 2: [phase-2-authentication.md](docs/plans/phase-2-authentication.md), Phase 3: [phase-3-categories-expenses.md](docs/plans/phase-3-categories-expenses.md), Phase 4: [phase-4-frontend.md](docs/plans/phase-4-frontend.md), Phase 5: [phase-5-receipts.md](docs/plans/phase-5-receipts.md), Phase 6: [phase-6-monthly-summary.md](docs/plans/phase-6-monthly-summary.md))
