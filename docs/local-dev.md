# Local development

The whole system runs on your machine with Docker and no AWS account. Each AWS service has a local stand-in in
`docker-compose.yml` (the decision is in [ADR 0007](adr/0007-local-development-with-docker-compose.md)). The stand-ins
are emulators, so some behaviour differs from AWS; the differences that matter are listed below and in the ADRs.

## Prerequisites and first run

You need Docker with Compose v2, `make` and `git`. PHP and Node are not needed on the host: every PHP and Node tool
runs in a pinned container as your user, so no file ends up owned by root.

```bash
make frontend-install   # once: front-end dependencies (`make up` refuses to start without them)
make php-install        # once: back-end dependencies (the API mounts backend/vendor)
make up                 # creates .env from .env.example, starts everything, waits until healthy
make down               # stop and remove the containers (see "Resetting state")
```

`make up` is idempotent: running it again changes nothing.

## Services: AWS and their local stand-ins

Host ports are bound to `127.0.0.1` only, so nothing else on your network can reach the stack. A "no port" entry is
reachable only from other containers on the Compose network.

| AWS service | Local stand-in (compose service) | Image | Host port (container port) | Notes |
|---|---|---|---|---|
| DynamoDB | DynamoDB Local (`dynamodb`) | `amazon/dynamodb-local:3.3.1` | `8000` (8000) | In memory. `make up` creates the app table `expense-tracker-local` (from `infra/dynamodb/table.json`, with `LSI1` and TTL on `expiresAt`) |
| SQS and its DLQ | ElasticMQ (`sqs`) | `softwaremill/elasticmq-native:1.7.1` | `9324` (9324) | Queues `receipts` and `receipts-dlq` from `docker/elasticmq/elasticmq.conf`; redrive after 3 receives |
| S3 | pgsty/silo (`s3`) | `pgsty/silo:RELEASE.2026-09-16T00-00-00Z` | `9000` (9000) | Buckets, CORS and notification rules are created by `local-aws-init`. Presigned URLs need `localhost:9000`, hence the fixed port |
| S3 event notifications to SQS | Emulator webhook to the relay (`s3-events-relay`) | `expense-tracker-local-aws:dev` (built here) | none (8080 internal) | Bearer token required |
| Lambda (receipt worker) | Bref PHP 8.5 image under the Runtime Interface Emulator (`worker`) | `expense-tracker-worker:dev` (built here) | none (8080 internal) | No AWS access or credentials; Phase 0 only logs what it receives |
| SQS event source mapping | Local runner (`worker-runner`) | `expense-tracker-local-aws:dev` | none | Polls `receipts`, invokes the worker, deletes only the messages that succeeded |
| API Gateway + Lambda (API) | nginx (`api`) in front of php-fpm (`api-php`) | `nginxinc/nginx-unprivileged:1.30.5-alpine`, `expense-tracker-php:fpm` | `8080` (8080) for nginx; php-fpm on 9000, internal only | Same PHP code as Lambda. php-fpm is on the internal network `api-internal` only: FastCGI has no authentication. Every request needs the `X-Origin-Verify` header (see "Calling the API directly") |
| CloudFront (one origin for app and API) | Vite dev server (`frontend`) | `node:24.21.0-alpine` | `5173` (5173) | Proxies `/api` to nginx, adds `X-Origin-Verify` and `CloudFront-Viewer-Address`, as CloudFront will |
| S3 static site (front-end) | Vite dev server (`frontend`) | same | `5173` | Hot reload from the mounted `frontend/` |

Open the app at http://localhost:5173. The API also answers directly on http://localhost:8080, but see
[Calling the API directly](#calling-the-api-directly). The Phase 0 page shows "API: ok" when the chain Vite, nginx and
php-fpm works.

## Calling the API directly

The API rejects any request without the origin header, as on AWS (ADR 0012). The Vite proxy adds it for you, so
http://localhost:5173/api/v1/health works with no header. Direct calls to port 8080 must send it. The local value is
`ORIGIN_VERIFY_LOCAL` in `.env` (default `local-origin-verify-not-a-secret`, not a secret).

```bash
curl -i http://localhost:8080/api/v1/health
# 403, body {"type":"about:blank","title":"Forbidden","status":403}

curl -i -H "X-Origin-Verify: local-origin-verify-not-a-secret" http://localhost:8080/api/v1/health
# 200, body {"status":"ok"}
```

### Reading the request log

`make logs` follows all services. For the API only, use `docker compose -p expense-tracker logs -f api-php`. Each
request writes one JSON line, for example:

```text
{"time":"2026-10-16T12:45:01+00:00","level":"info","message":"request","requestId":"695216dd6b56745c","method":"GET","path":"/api/v1/health","clientIp":"172.19.0.1","status":200,"durationMs":0.079}
```

The non-JSON lines in between are php-fpm's own access log.

`clientIp` comes only from the `CloudFront-Viewer-Address` header. On AWS, CloudFront sets it. Locally, the Vite proxy
sets it from the browser's address, and nginx fills it from the caller's socket address for direct calls.
`"clientIp":"unknown"` means the header was missing or invalid, or the request was rejected before the address was read
(for example a 403). An invalid header also writes an error line `client address unavailable` with a reason.

A rejected origin check writes a warning `origin verification failed` with the reason `missing` or `mismatch`. The
header value is never logged.

## Receipt pipeline (local)

On AWS a receipt upload goes S3 → event notification → SQS → worker Lambda. Locally the same path is:

1. The browser uploads a file directly to the S3 emulator (`s3`), as it would to S3.
2. The emulator sends an "object created" event to the relay (`s3-events-relay`, `http://s3-events-relay:8080/`),
   with the bearer token from `.env`.
3. The relay turns the event into an AWS-shaped SQS message and puts it on the `receipts` queue in ElasticMQ (`sqs`).
4. `worker-runner` long-polls `receipts`, and posts each batch to the worker as an SQS event
   (`http://worker:8080/2015-03-31/functions/function/invocations`, the Runtime Interface Emulator).
5. The worker processes the event. It logs what it receives in Phase 0. A message whose batch fails stays on the
   queue; after 3 receives it moves to `receipts-dlq`.

The emulator keeps events in its own queue while the relay does not answer, and replays them when it does. Its health
check only shows that the emulator itself is up.

Phase 0 has no upload endpoint yet, so the pipeline is tested with the integration tests (see "Tests"), not by hand.

## Make targets

Run `make help` for the full list with one-line descriptions. Grouped:

**Setup (once, and after a lockfile change)**

| Target | What it does |
|---|---|
| `make php-image` | Builds the PHP tools image (`expense-tracker-php:dev`) |
| `make php-install` | Backend Composer dependencies from `composer.lock` |
| `make local-aws-install` | Dependencies of `docker/local-aws` (needed for `make test-local-aws` and its lint) |
| `make frontend-install` | Front-end npm dependencies (`npm ci --ignore-scripts`) |
| `make contract-install` | Contract npm dependencies (`npm ci --ignore-scripts`) |

**Run the stack**

| Target | What it does |
|---|---|
| `make up` | Creates `.env` if missing, starts the stack, runs the bootstrap, waits until healthy |
| `make down` | Stops and removes the containers. Keeps volumes; never uses `-v` |
| `make ps` | Shows the services |
| `make logs` | Follows the logs (Ctrl+C to stop) |

**Test**

| Target | What it does |
|---|---|
| `make test` | All tests: backend unit and architecture, `docker/local-aws` unit, front-end (Vitest), then starts the stack for conformance and integration. Leaves the stack running |
| `make test-fast` | Like `make test`, without the tests marked `slow` (the dead-letter test, about 35 s) |
| `make test-unit` | Backend unit and architecture tests, 80% coverage bar on `src/` |
| `make test-local-aws` | `docker/local-aws` unit tests, 80% coverage bar |
| `make frontend-test` | Front-end Vitest with coverage, 80% bar |
| `make test-conformance` | Conformance tests against DynamoDB Local, ElasticMQ and the S3 emulator (needs `make up`) |
| `make test-contract` | Contract tests: route parity, every operation documents its errors, and the validator against the real bundled spec (run `make contract-bundle` first) |
| `make test-integration` | Integration tests (needs `make up`): the receipt pipeline S3 → relay → ElasticMQ → runner → worker, the API through nginx (contract-validated), and the DynamoDB item store against DynamoDB Local |
| `make test-shell` | Tests of the shell guards (`scripts/tests/*.test.sh`) |
| `make tf-test` | Tests of the Terraform check (needs Docker, about 50 s) |

**Lint and static checks (no stack needed)**

| Target | What it does |
|---|---|
| `make lint` | All static checks and audits: runs every target in the list below, in order |
| `make lint-php` | Backend: `composer validate`, PHP-CS-Fixer (dry run), PHPStan |
| `make lint-local-aws` | The same for `docker/local-aws` |
| `make frontend-lint` | Front-end: ESLint, Prettier check, `vue-tsc` |
| `make contract` | OpenAPI lint, bundle, and check that the committed front-end types match the spec |
| `make check-platform` | `config.platform.php` equals the PHP of the pinned `bref/php-85` layer |
| `make check-fixtures` | The two copies of the S3 event fixtures are byte-identical |
| `make audit`, `make audit-local-aws`, `make contract-audit`, `make frontend-audit` | Dependency audits, high severity and above |
| `make fmt` | Rewrites backend and `docker/local-aws` PHP with PHP-CS-Fixer (a formatter, it changes files) |
| `make frontend-build` | Front-end type check and production build into `frontend/dist` |
| `make check-bundle-secrets` | The origin-verify value is not in `frontend/dist` (run after `frontend-build`) |

**Guards (refuse unsafe changes; run in CI)**

| Target | What it does |
|---|---|
| `make check-image-pins` | Every image used to build or run the stack is pinned by digest |
| `make lint-workflows` | zizmor over the GitHub workflows and actions: hash-pinned `uses:`, pinned images, security audits |
| `make secrets-scan` | gitleaks over the full git history. Needs a full clone, not a shallow one |
| `make check-php-ini` | The Lambda PHP ini (`backend/php/conf.d`) is loaded by the pinned `bref/php-85` image with the hardened values |

**Terraform (Phase 0 has no AWS resources; these need no credentials)**

| Target | What it does |
|---|---|
| `make tf-fmt` | Rewrites the `.tf` files to the canonical format |
| `make tf-check` | Format check, then `init -backend=false` (read-only lock files) and `validate` of each root |
| `make tf-test` | Tests of `tf-check` (needs Docker) |
| `make tf-lock` | Regenerates `.terraform.lock.hcl` hashes for linux and darwin, amd64 and arm64 |

**Other:** `make contract-lint`, `contract-bundle`, `contract-gen` (rewrites the committed front-end types after a
spec change), `contract-check-types`, `frontend-audit`.

## Running one test

The Makefile has no filter target, so these are the underlying commands.

**PHP unit test** (no stack needed; `make php-image` built the tools image):

```bash
docker run --rm -u $(id -u):$(id -g) -v "$PWD":/app -w /app/backend expense-tracker-php:dev \
  vendor/bin/phpunit --testsuite unit --filter ExampleTestName
```

**PHP integration or conformance test** (needs `make up`): the same command as the Makefile uses, with a filter:

```bash
docker compose -p expense-tracker --profile tools run --rm --user $(id -u):$(id -g) php \
  vendor/bin/phpunit --testsuite integration --filter ExampleTestName
```

Use `--testsuite conformance` for the conformance suite. Two integration tests (`S3ToQueueTest` and `RelayHttpTest`,
group `queue-exclusive`) read the `receipts` queue themselves, so `worker-runner` must be stopped while they run. The
pipe test (`ReceiptPipeTest`, group `needs-runner`) needs it running. `make test-integration` runs both in the right
order, so use it for the integration suite rather than the command above.

**Front-end test** (Vitest): `make` runs npm inside the pinned Node container. Run `make -n frontend-test` to print the
exact `docker run` line, copy it, and replace the final `npm --prefix frontend run test:coverage` with
`npm --prefix frontend run test:unit -- <path-to-spec>`. Without a path, `test:unit` runs every spec.

**Shell guard test**: one file, run with bash, for example:

```bash
bash scripts/tests/check-fixtures.test.sh
```

## Resetting state

- `make down` removes this project's containers and network. The stack defines no named volumes: the emulators keep
  their data in memory (DynamoDB Local runs with `-inMemory`; the S3 emulator and the relay queue use tmpfs), so a
  stack that was stopped starts empty. The DynamoDB table is recreated by the next `make up`.
- If the table definition in `infra/dynamodb/table.json` changes, `make up` stops with "Table ... already exists but
  differs from infra/dynamodb/table.json". The bootstrap never deletes a table, so run `make down && make up` (the data
  is in memory anyway). A second `make up` on an unchanged table logs "no changes".
- `.env` is kept; it is git-ignored.
- **Do not add `-v`.** `make down` never passes it. `docker compose -p expense-tracker down -v` also deletes volumes;
  today that affects only this project's anonymous volumes, but it is destructive by habit, so ask the owner first.
- **Never use a machine-wide prune or `rm` command** (for example `docker system prune`, `docker volume prune`, or
  removing volumes or containers by a pattern). Other projects share this Docker host, and those commands delete
  their data too.

## Troubleshooting

- **`make up` stops with "DYNAMODB_TABLE is missing from .env".** Clones made before Phase 1 have an older `.env`. Add
  the line `DYNAMODB_TABLE=expense-tracker-local` (from `.env.example`) to `.env`.
- **A direct API call returns 403.** The origin header is missing or wrong; see "Calling the API directly".
- **`DYNAMODB_ENDPOINT` must stay unset outside local development.** The back-end's DynamoDB settings accept it only
  for a local stand-in (`localhost`, loopback, `host.docker.internal` or a Compose service name such as `dynamodb`) and
  fail with a configuration error for anything else, real AWS hosts included. On AWS it is not set, and the SDK uses
  the regional endpoint.
- **`make up` stops with "frontend/node_modules is missing".** Run `make frontend-install`. Vite runs from that folder.
- **`api-php` is unhealthy, or the API returns nothing.** Run `make php-install`. The API mounts `backend/vendor`, which
  is empty until then.
- **Compose says "not set - run make up" (or a similar `:?` message).** A required variable is missing from `.env`. Run
  `make up`, which creates `.env` from `.env.example`, or copy the missing `S3_`, `SQS_` or `AWS_` lines from
  `.env.example`. `.env` is git-ignored, so it does not exist on a fresh clone until `make up` runs.
- **A worker error does not show as an error.** The Runtime Interface Emulator answers a handler exception with HTTP 200
  and a body that contains `errorType`, and no error header. The runner treats such a batch as failed and leaves the
  messages on the queue. Read the worker's logs (`make logs`, or `docker compose -p expense-tracker logs worker`)
  rather than the status code.
- **The emulator is healthy, but a receipt event never arrives.** The emulator reports healthy even while the relay is
  unreachable; events wait in its queue and are sent when the relay answers. Check that `s3-events-relay` is healthy
  (`make ps`).
- **Vite does not start on 5173.** The dev server runs with `--strictPort`, so it refuses to pick another port. Free
  port 5173 and run `make up` again.
- **`make test-integration` says it could not start `worker-runner` again.** The recipe restarts the runner even after
  a failure. Run `make up` to start it.
- **`make secrets-scan` refuses to run.** The clone is shallow, so the scan would skip history. Run `git fetch --unshallow`.
- **A guard refuses an image as "not pinned by digest".** The guards accept only `name:tag@sha256:<digest>`. Pin it:
  1. Find the digest of the tag, which is the multi-arch index digest:
     `docker buildx imagetools inspect <name>:<tag>` and copy the `Digest:` line (the `sha256:` value).
  2. Write the image as `<name>:<tag>@sha256:<digest>`. The tag stays, so a human can read it; the digest is what
     Docker pulls.
  3. Run `make check-image-pins`. It also checks the images that Compose resolves.
- **CI runs `docker compose config` and it fails on variables.** `.env` is absent in CI, so the guards pass
  `--env-file .env.example`. Use the same flag when you run `docker compose config` by hand.
- **Apple Silicon.** The worker image is x86-only, so it runs under emulation (decision point D12 in
  [the Phase 0 plan](plans/phase-0-foundations.md)).
