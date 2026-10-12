# Expense Tracker

A personal expense tracker, built as a realistic proof of concept: small in scope, but built and deployed the way a
production application would be. Users sign in, record expenses with categories, attach receipts and see monthly
summaries. The front end is a Vue single-page app, the API is a JSON API in vanilla PHP (no framework), and the
target is a fully serverless AWS deployment. The whole system also runs locally with Docker and no AWS account.

Phase 0 (foundations) is the current state: the repository, the local stack, the checks and CI. The API serves only
a health endpoint so far; the features arrive in later phases.

## Prerequisites

- Docker with Compose v2 (the `docker compose` command)
- `make`
- `git`

You do not need PHP, Composer or Node on the host: the PHP and Node tools run in pinned containers.

## Quick start

```bash
make frontend-install   # once: front-end dependencies, in a Node container
make php-install        # once: back-end dependencies, in a PHP container
make up                 # creates .env, starts the stack and waits until it is healthy
```

Then open:

- the app: http://localhost:5173 (Vite, which proxies `/api` to the API)
- the API health check: http://localhost:8080/api/v1/health

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
| `infra/` | Terraform: the state bootstrap and the `dev` environment (no AWS resources applied yet) |
| `scripts/` | Guards and checks run by `make` and CI (image pins, workflow audit, fixtures, Terraform wrapper) and their tests in `scripts/tests/` |
| `docs/` | Requirements (`BRIEF.md`), the plan (`PLAN.md`), ADRs, the local dev guide and the phase plans |
| `spikes/` | Phase 0 spike harnesses, kept until the owner approves their removal |
| `.github/` | GitHub Actions workflows (CI) and the zizmor configuration |
| `Makefile` | The developer entry points: run `make help` for the list |

## Documentation

- [PLAN.md](docs/PLAN.md): the approved plan: summary, decisions, architecture, phases and status
- [docs/adr/](docs/adr/): architecture decision records (start with the [index](docs/adr/README.md))
- [docs/local-dev.md](docs/local-dev.md): local development: services and ports, the make targets, tests, troubleshooting
- [docs/plans/](docs/plans/): the work-package plans for each phase (Phase 0: [phase-0-foundations.md](docs/plans/phase-0-foundations.md))
