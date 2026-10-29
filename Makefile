# Developer entry points. Every PHP tool runs inside the pinned `dev` image of docker/php/Dockerfile
# (the host PHP is older than the project's PHP 8.5), as the host user so files are not owned by root.
#
# In CI the tools are installed on the runner, so the workflow calls the same targets with `RUN_PHP=`
# (empty): the commands are identical, only the wrapper disappears. Node tools (contract/) work the same way with
# `RUN_NODE`, a pinned node image run as the host user (contract/ and frontend/).

PHP_IMAGE ?= expense-tracker-php:dev
# `-e CI` (no value) passes CI through from make's environment only when it is set, so the contract tests fail (instead
# of skipping) when the bundle is missing in CI.
RUN_PHP ?= docker run --rm -u $(shell id -u):$(shell id -g) -e CI -v "$(CURDIR)":/app -w /app $(PHP_IMAGE)
COMPOSER = $(RUN_PHP) composer --working-dir=backend
# docker/local-aws is a separate Composer project (the local-only tooling of the stack, namespace LocalAws\).
LOCAL_AWS_COMPOSER = $(RUN_PHP) composer --working-dir=docker/local-aws

# The Compose project name (`expense-tracker`) is also set in docker-compose.yml; repeated here so that a
# stray COMPOSE_PROJECT_NAME in the environment can never point these commands at another project.
# Node tools (contract/): the pinned node:24.21.0-alpine (index digest, multi-arch), as the host user. HOME and the npm
# cache live in /tmp inside the container, so nothing is written outside the repository. `-e ORIGIN_VERIFY_LOCAL` (no
# value) passes the variable through from make's environment only when it is set (see frontend-build).
# `override`: see the pinned guard images below.
override NODE_IMAGE := node:24.21.0-alpine@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1
RUN_NODE ?= docker run --rm -u $(shell id -u):$(shell id -g) -v "$(CURDIR)":/app -w /app -e HOME=/tmp -e npm_config_cache=/tmp/.npm -e ORIGIN_VERIFY_LOCAL $(NODE_IMAGE)
NPM_CONTRACT = $(RUN_NODE) npm --prefix contract
NPM_FRONTEND = $(RUN_NODE) npm --prefix frontend
GENERATED_TYPES = frontend/src/api/schema.gen.ts

# Compose takes AWS_ACCESS_KEY_ID and the other AWS_* variables of the CALLER'S SHELL before it reads .env. api-php runs
# request code that can read its environment, so a real key exported in the shell must never reach it: `env -u`
# removes them from every docker compose call, and only the (fake) values of .env remain. `make check-env` refuses an
# .env that holds anything else. (RUN_PHP and RUN_NODE pass no AWS variable: `-e CI` and `-e ORIGIN_VERIFY_LOCAL` only.)
COMPOSE = env -u AWS_ACCESS_KEY_ID -u AWS_SECRET_ACCESS_KEY -u AWS_SESSION_TOKEN -u AWS_PROFILE docker compose -p expense-tracker

# Building the image is only needed when the tools run in a container.
PHP_IMAGE_DEP = $(if $(strip $(RUN_PHP)),php-image)

.DEFAULT_GOAL := help
.PHONY: help lint-workflows php-image php-install test-unit test-slow-unit test-contract lint-php audit fmt local-aws-install lint-local-aws test-local-aws audit-local-aws test-shell check-platform check-php-ini check-env up down ps logs test-conformance test-integration test-api-s3-path check-fixtures test-fast test lint secrets-scan secrets-scan-worktree check-image-pins contract-install contract-lint contract-bundle contract-ready contract-gen contract-check-types contract-audit contract frontend-install frontend-lint frontend-test frontend-build frontend-audit check-bundle-secrets check-bundle-csp tf-fmt tf-check tf-test tf-lock

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z0-9_-]+:.*## / {printf "  %-22s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

php-image: ## Build the PHP tools image (pinned base images, pcov, Composer)
	docker build --target dev -t $(PHP_IMAGE) -f docker/php/Dockerfile .

php-install: $(PHP_IMAGE_DEP) ## Install backend Composer dependencies from composer.lock
	$(COMPOSER) install --no-progress --prefer-dist

test-unit: $(PHP_IMAGE_DEP) ## Backend unit + architecture tests with coverage, 80% bar on src/
	$(COMPOSER) run test:coverage
	$(COMPOSER) run coverage:check

test-slow-unit: $(PHP_IMAGE_DEP) ## Backend unit tests marked `slow` (one production Argon2id hash, timed into build/tmp/argon2-timing.txt)
	$(COMPOSER) run test:slow

# The contract suite: the validator against the real bundle, route parity and the documented errors. It runs in the php
# tools container and needs no stack, but it does need contract/dist/openapi.json (`make contract-bundle`). CI sets CI,
# so a missing bundle fails there instead of skipping.
test-contract: $(PHP_IMAGE_DEP) ## Backend contract tests: route parity, documented errors, validator on the real bundle (needs contract-bundle)
	$(COMPOSER) run test:contract

lint-php: $(PHP_IMAGE_DEP) ## Backend: composer validate, PHP-CS-Fixer (dry run), PHPStan
	$(COMPOSER) validate --strict
	$(COMPOSER) run cs
	$(COMPOSER) run stan

audit: $(PHP_IMAGE_DEP) ## Backend: composer audit (known vulnerabilities in locked dependencies)
	$(COMPOSER) audit

fmt: $(PHP_IMAGE_DEP) ## Rewrite backend and docker/local-aws PHP files with PHP-CS-Fixer
	$(COMPOSER) run cs:fix
	$(LOCAL_AWS_COMPOSER) run cs:fix

local-aws-install: $(PHP_IMAGE_DEP) ## Install docker/local-aws Composer dependencies from composer.lock
	$(LOCAL_AWS_COMPOSER) install --no-progress --prefer-dist

lint-local-aws: $(PHP_IMAGE_DEP) ## docker/local-aws: composer validate, PHP-CS-Fixer (dry run), PHPStan
	$(LOCAL_AWS_COMPOSER) validate --strict
	$(LOCAL_AWS_COMPOSER) run cs
	$(LOCAL_AWS_COMPOSER) run stan

test-local-aws: $(PHP_IMAGE_DEP) ## docker/local-aws unit tests with coverage, 80% bar on src/ (never an empty report)
	$(LOCAL_AWS_COMPOSER) run test:coverage
	$(LOCAL_AWS_COMPOSER) run coverage:check

audit-local-aws: $(PHP_IMAGE_DEP) ## docker/local-aws: composer audit (known vulnerabilities in locked dependencies)
	$(LOCAL_AWS_COMPOSER) audit

test-shell: ## Tests of the shell guards (scripts/tests/*.test.sh, except terraform-check: see tf-test)
	@set -e; for t in scripts/tests/*.test.sh; do \
	  case "$$t" in scripts/tests/terraform-check.test.sh) continue;; esac; \
	  echo "== $$t"; bash "$$t"; \
	done

# Builds the tools image, then checks the real worker Dockerfile: the PHP in its pinned bref/php-85 layer must equal
# composer.json config.platform.php, and the --no-dev vendor/ must load on that PHP. A missing Dockerfile is an error
# (the check used to skip until WP10 added it). The guard itself is covered by `make test-shell`, against fixtures.
WORKER_DOCKERFILE = docker/worker/Dockerfile

check-platform: ## composer.json config.platform.php equals the PHP in the pinned bref/php-85 layer
	$(MAKE) --no-print-directory php-image
	PHP_IMAGE=$(PHP_IMAGE) scripts/check-php-platform.sh --with-vendor $(WORKER_DOCKERFILE)

# The ini file that Lambda gets (backend/php/conf.d/app.ini, copied to /var/task/php/conf.d by the worker Dockerfile)
# is loaded by the pinned bref/php-85 image itself and the three hardening values are asserted there.
BREF_IMAGE = $(shell sed -nE 's/^[[:space:]]*FROM[[:space:]]+(bref\/php-85:[^@[:space:]]+@sha256:[0-9a-f]{64}).*$$/\1/Ip' $(WORKER_DOCKERFILE) | head -n 1)

check-php-ini: ## The Lambda ini (backend/php/conf.d) is loaded by the pinned bref/php-85 image with the hardened values
	@test -n "$(BREF_IMAGE)" || { echo "check-php-ini: no pinned bref/php-85 FROM line in $(WORKER_DOCKERFILE)" >&2; exit 1; }
	docker run --rm --entrypoint php --mount "type=bind,src=$(CURDIR)/backend/php/conf.d,dst=/var/task/php/conf.d,readonly" $(BREF_IMAGE) -r '$$want = ["zend.exception_ignore_args" => "1", "expose_php" => "", "enable_post_data_reading" => ""]; $$bad = []; foreach ($$want as $$key => $$value) { if ((string) ini_get($$key) !== $$value) { $$bad[] = $$key . "=" . var_export(ini_get($$key), true); } } if ($$bad) { fwrite(STDERR, "check-php-ini: unexpected values: " . implode(", ", $$bad) . "\n"); exit(1); } echo "check-php-ini: ok\n";'

.env:
	cp .env.example .env
	@echo "Created .env from .env.example (local, non-secret values)."

# `check-env` fails fast, before anything starts: a key that docker-compose.yml reads WITHOUT a default is missing
# (it names the line to add), or the AWS credentials in .env are not the fake pair of .env.example, or .env sets a
# session token. It never prints a value. The logic is scripts/check-env.sh (tested by scripts/tests/check-env.test.sh).
check-env: .env
	@scripts/check-env.sh .env .env.example

# `make up` starts the stack in three steps, so that a service that needs the bootstrap to have finished is never
# started (or waited for) before it:
#   1. everything except the two lists below: `up -d --wait` (returns when all are healthy);
#   2. ONE_SHOT_SERVICES: run in the foreground. They exit on purpose, and `up --wait` counts "exited (0)" as a
#      failure, so they cannot be in step 1. Their log is shown and a non-zero exit code fails `make up`;
#   3. LATE_SERVICES: services that depend on a one-shot finishing, started with `up -d --wait` after it.
# LATE_SERVICES = api frontend: the API uses the DynamoDB table that `local-aws-init` creates, so the entry point `api`
# (nginx) and `frontend` (Vite, which needs `api` healthy) start after it. `api-php` is in step 1 (it does not touch
# DynamoDB until it serves a request; only `api` and `dynamodb` can reach it, see docker-compose.yml). `worker-runner` stays in step 1: it needs
# only sqs and worker (queues come from elasticmq.conf).
# `make frontend-install` must have run (like `make php-install` for the API): Vite runs from frontend/node_modules.
ONE_SHOT_SERVICES = local-aws-init
LATE_SERVICES = api frontend

# The step-1 list is computed once and checked: if `docker compose config --services` fails (broken compose file,
# a missing required variable) or yields nothing to start, `make up` stops with an error instead of running
# `up` with an empty list, which would silently start EVERY service (including the one-shot ones, out of order).
# --build: the local-aws-init image is built from this repository, so a code change must reach it (an unchanged
# build is instant, from the layer cache). Running the bootstrap again is safe: it logs "no changes".
up: .env check-env frontend-ready ## Start the local stack, run the bootstrap, and wait until all is healthy (idempotent)
	@set -e; \
	all=$$($(COMPOSE) config --services) || { echo "make up: 'docker compose config --services' failed (see above); not starting anything." >&2; exit 1; }; \
	first=$$(printf '%s\n' "$$all" | awk -v skip="$(ONE_SHOT_SERVICES) $(LATE_SERVICES)" 'BEGIN { n = split(skip, a, " "); for (i = 1; i <= n; i++) s[a[i]] = 1 } NF && !($$0 in s)'); \
	[ -n "$$first" ] || { echo "make up: no services to start in step 1 (config --services returned: '$$all')." >&2; exit 1; }; \
	echo "make up, step 1: start and wait for: $$(echo $$first)"; \
	$(COMPOSE) up -d --build --wait $$first; \
	echo "make up, step 2: run the one-shot: $(ONE_SHOT_SERVICES)"; \
	$(COMPOSE) up --build --no-deps --exit-code-from $(ONE_SHOT_SERVICES) $(ONE_SHOT_SERVICES); \
	if [ -n "$(strip $(LATE_SERVICES))" ]; then \
		echo "make up, step 3: start and wait for: $(LATE_SERVICES)"; \
		$(COMPOSE) up -d --build --wait $(LATE_SERVICES); \
	fi

down: ## Stop the local stack and remove its containers (data volumes are kept; never uses -v)
	$(COMPOSE) down

ps: ## Show the local stack's services
	$(COMPOSE) ps

logs: ## Follow the local stack's logs (Ctrl+C to stop)
	$(COMPOSE) logs -f --tail=100

test-conformance: .env check-env php-image ## Conformance tests (DynamoDB Local, ElasticMQ, S3 emulator) in the php container; run `make up` first
	$(COMPOSE) --profile tools run --rm --user $(shell id -u):$(shell id -g) php vendor/bin/phpunit --testsuite conformance

# The `worker-runner` service consumes the real `receipts` queue, and two suites (S3ToQueueTest, RelayHttpTest) read and
# purge that same queue themselves, so they cannot run while the runner is up. The recipe therefore:
#   1. stops `worker-runner` (project-scoped: only this Compose project's service), then runs every suite except the
#      group `needs-runner` (so S3ToQueueTest, RelayHttpTest and RieInvocationTest);
#   2. starts it again and runs the group `needs-runner` (ReceiptPipeTest: the whole pipe, runner included). That test
#      writes the run-unique upload key and poison message id under build/pipe-test/, and the recipe then greps the
#      service logs for exactly those values (an empty queue alone could mean "no event"): the worker must have logged
#      "receipt received" for the key, and (unless FAST) the runner must have logged "messages failed, left on the
#      queue" for the poison id (the worker reported it in batchItemFailures, so this is the partial-failure path, not an
#      outage and not a whole-batch failure);
#   3. restarts the runner afterwards ALSO when a test fails or the recipe is interrupted. dash (the shell make uses)
#      does not run an EXIT trap on a signal, so INT, TERM and HUP are trapped too and turned into `exit`, which fires
#      the EXIT trap; the trap keeps the original exit code (130 for INT, 143 for TERM/HUP).
# FAST=1 leaves out the tests marked `slow` (the ~35 s dead-letter test and `make test-slow-unit`); `make test-fast` sets it for the whole suite.
PHPUNIT_INTEGRATION = $(COMPOSE) --profile tools run --rm --user $(shell id -u):$(shell id -g) php vendor/bin/phpunit --testsuite integration
# One --exclude-group per group: PHPUnit 13 does not split a comma-separated list.
EXCLUDE_SLOW = $(if $(FAST),--exclude-group slow)
PIPE_MARKERS = build/pipe-test
# ClientIpOverNginxTest writes markers.json here (one "key": "value" per line); the recipe reads it back.
CLIENT_IP_MARKERS = build/client-ip-test
# AuthFlowApiTest and LoginThrottleRaceTest write markers.json here (password, wrongPassword, sessionToken,
# revokedSessionToken, userId, csrfOrigin), merged; the checks below need the whole suite to have run (not --filter).
# ExpenseFlowApiTest adds contentDescription(2), contentCategoryName(2), contentQuery and contentCursor: user content, never
# logged by api-php; descriptions and names are also checked in the nginx log, `q` and cursors are not (local nginx logs query strings, B23).
AUTH_MARKERS = build/auth-test

# Decision 15(a), proved from INSIDE the api-php service (a throwaway container of the same service, same networks and
# hardening; `--no-deps` starts nothing else): S3_ENDPOINT is the nginx listener http://api:9100, a signed request through
# it is answered by S3, and s3:9000 is not reachable. The script is piped in because the container mounts no scripts.
# `make test-integration` runs it too. Needs `make up`.
test-api-s3-path: .env check-env ## Check from inside api-php that S3 is reachable only through the nginx listener api:9100; run `make up` first
	$(COMPOSE) run --rm --no-deps -T api-php php < scripts/api-s3-path-check.php

test-integration: .env check-env php-image ## Integration tests: receipt pipeline (S3 -> relay -> ElasticMQ -> worker-runner -> worker), API through nginx, DynamoDB item store; run `make up` first
	@set -e; \
	trap 'code=$$?; trap - EXIT; $(COMPOSE) start worker-runner >/dev/null || echo "make test-integration: could not start worker-runner again; run make up" >&2; exit $$code' EXIT; \
	trap 'exit 130' INT; trap 'exit 143' TERM HUP; \
	echo "make test-integration, part 1: worker-runner stopped (the suites below read the receipts queue themselves)"; \
	$(COMPOSE) stop worker-runner; \
	rm -rf $(CLIENT_IP_MARKERS) $(AUTH_MARKERS); \
	ip_since=$$(date -u +%Y-%m-%dT%H:%M:%SZ); \
	$(PHPUNIT_INTEGRATION) --exclude-group needs-runner $(EXCLUDE_SLOW); \
	echo "make test-integration: api-php to S3 path check"; \
	$(MAKE) --no-print-directory test-api-s3-path; \
	fail() { echo "make test-integration: $$1" >&2; exit 1; }; \
	[ -f $(CLIENT_IP_MARKERS)/markers.json ] || fail "the client IP test left no $(CLIENT_IP_MARKERS)/markers.json"; \
	marker() { v=$$(sed -n "s/^ *\"$$1\": \"\(.*\)\",\{0,1\}$$/\1/p" $(CLIENT_IP_MARKERS)/markers.json); [ -n "$$v" ] || fail "markers.json has no '$$1' marker (did its test run?)"; printf '%s' "$$v"; }; \
	valid=$$(marker validIp); duplicate=$$(marker duplicateValue); socketpath=$$(marker socketPath); \
	apilogs=$$($(COMPOSE) logs --no-log-prefix --since "$$ip_since" api-php) || fail "could not read the api-php logs"; \
	[ -n "$$apilogs" ] || fail "no api-php log output since $$ip_since (is the stack up?)"; \
	requests=$$(printf '%s\n' "$$apilogs" | grep -F '"message":"request"' || true); \
	[ -n "$$requests" ] || fail "no request line in the api-php logs since $$ip_since"; \
	printf '%s\n' "$$requests" | grep -qF "\"clientIp\":\"$$valid\"" || fail "no request line with clientIp $$valid (the valid CloudFront-Viewer-Address)"; \
	errorline=$$(printf '%s\n' "$$apilogs" | grep -F '"message":"client address unavailable"' | grep -F '"reason":"multiple-values"' | grep -F "\"value\":\"$$duplicate\"" | head -n 1 || true); \
	[ -n "$$errorline" ] || fail "no 'client address unavailable' line (multiple-values) for the duplicated header $$duplicate"; \
	requestid=$$(printf '%s' "$$errorline" | sed -n 's/.*"requestId":"\([0-9a-f]*\)".*/\1/p'); \
	[ -n "$$requestid" ] || fail "the 'client address unavailable' line has no request id"; \
	printf '%s\n' "$$requests" | grep -F "\"requestId\":\"$$requestid\"" | grep -qF '"clientIp":"unknown"' || fail "the request $$requestid (duplicated header) was not logged with clientIp unknown"; \
	printf '%s\n' "$$requests" | grep -F "\"path\":\"$$socketpath\"" | grep -qE '"clientIp":"(172|10)\.[0-9]+\.[0-9]+\.[0-9]+"' || fail "the request for $$socketpath (no header) was not logged with a 172.x or 10.x clientIp (the socket address nginx fills in)"; \
	echo "make test-integration: client IP log checks passed"; \
	[ -f $(AUTH_MARKERS)/markers.json ] || fail "the auth tests left no $(AUTH_MARKERS)/markers.json"; \
	amarker() { v=$$(sed -n "s/^ *\"$$1\": \"\(.*\)\",\{0,1\}$$/\1/p" $(AUTH_MARKERS)/markers.json); [ $${#v} -ge 16 ] || fail "auth markers.json has no usable '$$1' marker (empty or shorter than 16 characters; did its test run? it needs the whole suite, not --filter)"; printf '%s' "$$v"; }; \
	authpw=$$(amarker password); authwrong=$$(amarker wrongPassword); authtoken=$$(amarker sessionToken); authrevoked=$$(amarker revokedSessionToken); \
	authuser=$$(amarker userId); authorigin=$$(amarker csrfOrigin); \
	nginxlogs=$$($(COMPOSE) logs --no-log-prefix --since "$$ip_since" api) || fail "could not read the api (nginx) logs"; \
	[ -n "$$nginxlogs" ] || fail "no api (nginx) log output since $$ip_since"; \
	leak() { [ $${#2} -ge 10 ] || fail "the leak check for $$1 has a value shorter than 10 characters (vacuous)"; \
		if printf '%s\n' "$$apilogs" | grep -qF -e "$$2"; then fail "$$1 appears in the api-php logs (a secret leaked into a log)"; fi; \
		if printf '%s\n' "$$nginxlogs" | grep -qF -e "$$2"; then fail "$$1 appears in the api (nginx) logs (a secret leaked into a log)"; fi; }; \
	leak "the test PASSWORD" "$$authpw"; leak "the password prefix pw-marker-" "pw-marker-"; \
	leak "the wrong PASSWORD" "$$authwrong"; leak "the wrong-password prefix wrong-marker-" "wrong-marker-"; \
	leak "the SESSION TOKEN" "$$authtoken"; leak "the first 16 characters of the SESSION TOKEN" "$$(printf '%s' "$$authtoken" | cut -c1-16)"; \
	leak "the REVOKED session token" "$$authrevoked"; leak "the first 16 characters of the REVOKED session token" "$$(printf '%s' "$$authrevoked" | cut -c1-16)"; \
	printf '%s\n' "$$requests" | grep -F '"path":"/api/v1/auth/me"' | grep -qF -e "\"userId\":\"$$authuser\"" || fail "no request line for /api/v1/auth/me carries \"userId\":\"$$authuser\" (the session user is not logged)"; \
	printf '%s\n' "$$apilogs" | grep -F 'csrf check failed' | grep -qF -e "\"origin\":\"$$authorigin\"" || fail "no 'csrf check failed' line with \"origin\":\"$$authorigin\" in the api-php logs (the CSRF refusal of the auth flow test was not logged)"; \
	echo "make test-integration: auth log checks passed"; \
	content() { v=$$(amarker "$$2") || exit 1; if printf '%s\n' "$$apilogs" | grep -qF -e "$$v"; then fail "$$1 appears in the api-php logs (user content leaked into a log)"; fi; \
		if [ "$$3" = nginx ] && printf '%s\n' "$$nginxlogs" | grep -qF -e "$$v"; then fail "$$1 appears in the api (nginx) logs (user content leaked into a log)"; fi; }; \
	content "an expense DESCRIPTION" contentDescription nginx; content "the patched DESCRIPTION" contentDescription2 nginx; \
	content "a category NAME" contentCategoryName nginx; content "the renamed category NAME" contentCategoryName2 nginx; \
	content "a search QUERY (q)" contentQuery; content "a pagination CURSOR" contentCursor; \
	echo "make test-integration: user content log checks passed"; \
	echo "make test-integration, part 2: worker-runner running (the whole pipe)"; \
	rm -rf $(PIPE_MARKERS); \
	since=$$(date -u +%Y-%m-%dT%H:%M:%SZ); \
	$(COMPOSE) start worker-runner; \
	$(PHPUNIT_INTEGRATION) --group needs-runner $(EXCLUDE_SLOW); \
	key=$$(cat $(PIPE_MARKERS)/upload-key.txt) || { echo "make test-integration: the pipe test left no upload key marker" >&2; exit 1; }; \
	$(COMPOSE) logs --no-log-prefix --since "$$since" worker | grep -F '"message":"receipt received"' | grep -qF "\"key\":\"$$key\"" \
		|| { echo "make test-integration: the worker logged no 'receipt received' line for the key $$key" >&2; exit 1; }; \
	if [ -z "$(FAST)" ]; then \
		id=$$(cat $(PIPE_MARKERS)/poison-message-id.txt) || { echo "make test-integration: the dead-letter test left no message id marker" >&2; exit 1; }; \
		$(COMPOSE) logs --no-log-prefix --since "$$since" worker-runner | grep -F '"message":"messages failed, left on the queue"' | grep -qF "\"failedMessageIds\":[\"$$id\"]" \
			|| { echo "make test-integration: the runner logged no 'messages failed' line (batchItemFailures) for the poison message $$id" >&2; exit 1; }; \
	fi

# The whole test suite (Phase 0 definition of done). Run as a sequence (not as prerequisites) so that the order holds
# with `make -j` too: the cheap suites first, then the stack (`make up` is idempotent) for the tests that need it.
# Needs `make php-install`, `make contract-install`, `make local-aws-install` and `make frontend-install` once. Leaves the stack running: `make down`.
test: ## ALL tests: backend unit + architecture, local-aws unit, frontend, then (starts the stack) conformance + integration
	$(MAKE) --no-print-directory test-unit
	$(if $(FAST),,$(MAKE) --no-print-directory test-slow-unit)
	$(MAKE) --no-print-directory contract-bundle
	$(MAKE) --no-print-directory test-contract
	$(MAKE) --no-print-directory test-local-aws
	$(MAKE) --no-print-directory frontend-test
	$(MAKE) --no-print-directory up
	$(MAKE) --no-print-directory test-conformance
	$(MAKE) --no-print-directory test-integration

test-fast: ## Like `make test`, without the tests marked `slow` (the dead-letter test, ~35 s, and the production Argon2id hash)
	$(MAKE) --no-print-directory test FAST=1

# Everything that checks without running the stack: style, static analysis, type checks, the contract, Terraform, the
# guards, and the dependency audits. (The Bref boundary is an architecture test, so it runs in `make test-unit`.)
# Needs the three `*-install` targets once. Each part is a target of its own, so a failure names the check.
LINT_TARGETS = lint-php lint-local-aws frontend-lint contract tf-check check-platform check-php-ini check-image-pins lint-workflows check-fixtures audit audit-local-aws contract-audit frontend-audit

lint: ## ALL static checks and audits (no stack needed): PHP, front-end, contract, Terraform, platform + image pins
	@set -e; for t in $(LINT_TARGETS); do \
	  echo "== make $$t"; $(MAKE) --no-print-directory $$t; \
	done

# Pinned images for the guards, by tag AND digest (multi-arch index digests). `override` on purpose: with a plain `:=`
# a command-line assignment (`make JQ_IMAGE=other ...`) would still win; `override` makes the Makefile's value win over
# both the command line and the environment, so nothing can swap a pinned image for another one. NODE_IMAGE and
# GITLEAKS_IMAGE are declared the same way. The two scripts read ZIZMOR_IMAGE and JQ_IMAGE from the environment (make
# exports them) or, when run without make, through `make print-<NAME>`; both scripts also refuse an unpinned value.
#   ZIZMOR_IMAGE  zizmor 1.30.1, audits workflows and actions (scripts/lint-workflows.sh)
#   JQ_IMAGE      jq 1.8.2, parses `docker compose config` output in check-image-pins.sh. A pinned jq image, not the
#                 PHP tools image: it is a fixed single-purpose binary that needs no build, so the guard works on a
#                 fresh clone and cannot be affected by edits to docker/php/Dockerfile (a file this guard checks).
#   YQ_IMAGE      yq 4.54.1 (mikefarah/yq), parses YAML before anything else reads it: Compose `include` / `extends.file`
#                 and docker actions in check-image-pins.sh, local `uses:` targets in lint-workflows.sh. A real parser,
#                 so quoting, escapes, complex keys and aliases cannot hide a key.
override ZIZMOR_IMAGE := ghcr.io/zizmorcore/zizmor:1.30.1@sha256:a2eb396d886c053073405c7a980f2139ba2248ec172243cfa3841e57196e8101
override JQ_IMAGE := ghcr.io/jqlang/jq:1.8.2@sha256:b9c68867e5766576263a222e91db3de422d802069c7af70440e667a95344e486
override YQ_IMAGE := mikefarah/yq:4.54.1@sha256:4b3d9475d65571d28cbb19544d3820ec2945e4c8b2f18279394282b8dc3a592e
export ZIZMOR_IMAGE JQ_IMAGE YQ_IMAGE

# Only the variables the scripts read can be printed (no generic print-%, which would print any variable). The value is
# single-quoted for the shell, with any ' inside it escaped, and printed with printf, not echo.
shell_quote = '$(subst ','\'',$(1))'
.PHONY: print-ZIZMOR_IMAGE print-JQ_IMAGE print-YQ_IMAGE
print-ZIZMOR_IMAGE:
	@printf '%s\n' $(call shell_quote,$(ZIZMOR_IMAGE))
print-JQ_IMAGE:
	@printf '%s\n' $(call shell_quote,$(JQ_IMAGE))
print-YQ_IMAGE:
	@printf '%s\n' $(call shell_quote,$(YQ_IMAGE))

check-image-pins: ## Every Dockerfile FROM/COPY --from/--mount from=, and Compose image (as Compose resolves it), is pinned by digest
	@scripts/check-image-pins.sh

# zizmor over .github/ (workflows and actions): every `uses:` pinned by hash, pinned container/service images, plus the
# security audits. Offline unless ZIZMOR_ONLINE=1 (CI sets it with GH_TOKEN; then the online audits run too). Config:
# .github/zizmor.yml.
lint-workflows: ## zizmor (pinned image) over workflows and actions: hash-pinned uses, pinned images, security audits
	@scripts/lint-workflows.sh

# Full git history, not only the working tree. Findings are redacted. Config and the allowlist of known-fake values:
# .gitleaks.toml (the only way to allow a value: reviewed, by path AND rule AND value). Inline `gitleaks:allow` comments
# are NOT honoured (--ignore-gitleaks-allow), and a .gitleaksignore file (tracked anywhere, or at the root) is refused,
# because either would let a commit silence its own finding. The container runs as the host user (git refuses a
# repository owned by someone else), with the repository mounted read-only, a read-only root file system (HOME is a
# tmpfs), no network, no capabilities and no-new-privileges. A shallow clone would silently skip the older commits, so
# it is an error.
# Directory of this Makefile, captured here so a later `include` cannot repoint the script path.
SECRETS_SCAN_DIR := $(dir $(abspath $(firstword $(MAKEFILE_LIST))))
override GITLEAKS_IMAGE := ghcr.io/gitleaks/gitleaks:v8.30.1@sha256:c00b6bd0aeb3071cbcb79009cb16a60dd9e0a7c60e2be9ab65d25e6bc8abbb7f

secrets-scan: ## gitleaks over the full git history AND uncommitted work (pinned image; needs a full clone, not a shallow one)
	@if [ "$$(git rev-parse --is-shallow-repository)" != false ]; then \
		echo "make secrets-scan: this is a shallow clone (or not a git repository); the scan would skip history. Fetch it all: git fetch --unshallow" >&2; exit 1; \
	fi
	@ignored=$$(git ls-files -- ':(glob)**/.gitleaksignore'); \
	if [ -n "$$ignored" ] || [ -e .gitleaksignore ]; then \
		echo "make secrets-scan: a .gitleaksignore file is not allowed ($$(echo $$ignored .gitleaksignore)); allow a known-fake value in .gitleaks.toml instead (by path AND rule AND value), or rotate a real secret" >&2; exit 1; \
	fi
	docker run --rm --network none --cap-drop ALL --security-opt no-new-privileges --read-only --tmpfs /tmp -u $(shell id -u):$(shell id -g) -v "$(CURDIR)":/repo:ro -e HOME=/tmp -w /repo $(GITLEAKS_IMAGE) git --redact --no-banner --ignore-gitleaks-allow --exit-code 1 .
	@$(SECRETS_SCAN_WORKTREE)

# `gitleaks git` sees committed history only. This scans what is not committed yet: staged and unstaged edits plus
# untracked files that are not ignored (copied with their repo-relative paths into a throwaway directory, so the path
# allowlists of .gitleaks.toml match as in the history scan). Same hardening and flags; `make secrets-scan` runs both.
SECRETS_SCAN_WORKTREE = GITLEAKS_IMAGE=$(call shell_quote,$(GITLEAKS_IMAGE)) $(call shell_quote,$(SECRETS_SCAN_DIR)scripts/secrets-scan-worktree.sh)

secrets-scan-worktree: ## gitleaks over uncommitted work: staged, unstaged and untracked files (pinned image)
	@$(SECRETS_SCAN_WORKTREE)

# The S3 event fixtures exist twice (backend/ and docker/local-aws/ are separate Composer projects). They must stay
# byte-identical, or the parser and the relay would be tested against different "real" events.
FIXTURES_A ?= backend/tests/Fixtures/s3-events
FIXTURES_B ?= docker/local-aws/tests/Fixtures/s3-events

check-fixtures: ## The two copies of the S3 event JSON fixtures are identical
	@scripts/check-fixtures.sh $(FIXTURES_A) $(FIXTURES_B)

# The OpenAPI contract (ADR 0011). `make contract-install` first (once, and after a lockfile change); `make contract`
# then lints the spec, bundles it to contract/dist/openapi.json and checks that the committed types equal the ones the
# spec generates. The check does not use git: it generates into build/contract/ and compares the two files, so it works
# the same on a dirty tree, before the first commit, and in CI. It never overwrites the committed file; after a spec
# change run `make contract-gen` and commit the result. Scripts of dependencies never run (--ignore-scripts).
CHECK_TYPES_FILE = build/contract/schema.gen.ts
COMMITTED_TYPES = $(GENERATED_TYPES)

contract-install: ## contract/: install npm dependencies from package-lock.json (no install scripts)
	$(NPM_CONTRACT) ci --ignore-scripts --no-audit --no-fund

# Fails fast with a clear message instead of an npm error about a missing binary.
contract-ready:
	@test -d contract/node_modules || { echo "make: contract/node_modules is missing; run 'make contract-install' first." >&2; exit 1; }

contract-lint: contract-ready ## contract/: lint openapi.yaml with Redocly (needs contract-install)
	$(NPM_CONTRACT) run lint

contract-bundle: contract-ready ## contract/: bundle openapi.yaml into contract/dist/openapi.json (needs contract-install)
	$(NPM_CONTRACT) run bundle

contract-gen: contract-ready ## contract/: rewrite frontend/src/api/schema.gen.ts from openapi.yaml (needs contract-install)
	$(NPM_CONTRACT) run gen

contract-check-types: contract-ready ## contract/: fail if the committed schema.gen.ts differs from the generated types
	$(NPM_CONTRACT) run gen -- $(CHECK_TYPES_FILE)
	@if [ ! -f "$(COMMITTED_TYPES)" ]; then echo "make: $(COMMITTED_TYPES) is missing; run make contract-gen" >&2; exit 1; fi
	@if ! cmp -s "$(COMMITTED_TYPES)" "$(CHECK_TYPES_FILE)"; then \
		diff -u "$(COMMITTED_TYPES)" "$(CHECK_TYPES_FILE)" || true; \
		echo "make: $(COMMITTED_TYPES) is stale or was edited by hand; run make contract-gen" >&2; exit 1; \
	fi
	@echo "$(COMMITTED_TYPES) is up to date"

contract-audit: ## contract/: npm audit, high severity and above (needs contract-install)
	$(NPM_CONTRACT) audit --audit-level=high

contract: contract-lint contract-bundle contract-check-types ## Lint + bundle + check the committed types (needs contract-install)

# The front-end (ADR 0008). `make frontend-install` first (once, and after a lockfile change). Lint = ESLint, Prettier
# --check and vue-tsc (type check of the app, the tests and the Node-side config). Test = Vitest with the 80% bar on
# frontend/src and frontend/vite (see vite.config.ts for what is excluded and why). Scripts of dependencies never run.
frontend-install: ## frontend/: install npm dependencies from package-lock.json (no install scripts)
	$(NPM_FRONTEND) ci --ignore-scripts --no-audit --no-fund

frontend-ready:
	@test -d frontend/node_modules || { echo "make: frontend/node_modules is missing; run 'make frontend-install' first." >&2; exit 1; }

frontend-lint: frontend-ready ## frontend/: ESLint, Prettier --check and vue-tsc (needs frontend-install)
	$(NPM_FRONTEND) run lint
	$(NPM_FRONTEND) run format:check
	$(NPM_FRONTEND) run type-check

frontend-test: frontend-ready ## frontend/: Vitest with coverage, 80% bar (needs frontend-install)
	$(NPM_FRONTEND) run test:coverage

# The build runs WITH the origin-verify value in its environment, so that `check-bundle-secrets` is a real check: if the
# configuration ever starts to copy it into the bundle, the value is there to be found. The value travels in the
# ENVIRONMENT only (`-e NAME` in RUN_NODE takes it from make's environment): never in an argument list, where `ps` and
# make's own echo of the recipe would show it. The recipe lines start with `@` for the same reason.
ORIGIN_VERIFY_VALUE = $(shell grep -h '^ORIGIN_VERIFY_LOCAL=' .env .env.example 2>/dev/null | head -n 1 | cut -d= -f2-)

frontend-build: export ORIGIN_VERIFY_LOCAL = $(ORIGIN_VERIFY_VALUE)
frontend-build: frontend-ready ## frontend/: type check and production build into frontend/dist (needs frontend-install)
	@echo "frontend-build: building with ORIGIN_VERIFY_LOCAL in the environment (value not shown)"
	@$(RUN_NODE) npm --prefix frontend run build

# The script normalises the value (quotes, CR), refuses one shorter than 8 characters, and also searches base64 and hex.
check-bundle-secrets: export BUNDLE_SECRET_VALUE = $(ORIGIN_VERIFY_VALUE)
check-bundle-secrets: ## The ORIGIN_VERIFY_LOCAL value (.env, else .env.example) is not in frontend/dist (needs frontend-build)
	@scripts/check-bundle-secrets.sh frontend/dist

# The built index.html has no inline script or style, no on*= attribute, no javascript: or data: URL, and the scripts
# have no eval( or new Function: the bundle runs under the strict CSP planned for Phase 7 (plan phase-4 C16).
check-bundle-csp: ## frontend/dist is compatible with a strict CSP: no inline script or style, no eval (needs frontend-build)
	@scripts/check-bundle-csp.sh frontend/dist

# Full gate: production and dev dependencies, high and above. (Until 2026-10-09 the dev tools pulled in `braces`
# through @vue/eslint-config-typescript -> fast-glob; ESLint now uses typescript-eslint directly and the tree is clean.)
frontend-audit: frontend-ready ## frontend/: npm audit of all packages (dev tools too), high and above (needs frontend-install)
	$(NPM_FRONTEND) audit --audit-level=high

# Terraform (ADR 0009): Phase 0 has no AWS resources and no credentials; the checks need only the provider download.
# scripts/terraform.sh runs the pinned image as the host user without any AWS_* variable. `init -backend=false` never
# contacts the (partial) S3 backend, and `-lockfile=readonly` makes a stale or missing .terraform.lock.hcl an error
# instead of rewriting it. TF_DIR can point at a copy of the tree (scripts/tests/terraform-check.test.sh does).
TERRAFORM := scripts/terraform.sh
TF_DIR ?= infra/terraform
TF_ROOTS = $(TF_DIR)/bootstrap $(TF_DIR)/envs/dev
TF_LOCK_PLATFORMS = -platform=linux_amd64 -platform=linux_arm64 -platform=darwin_amd64 -platform=darwin_arm64

tf-fmt: ## Terraform: rewrite all .tf files to the canonical format
	$(TERRAFORM) fmt -recursive $(TF_DIR)

tf-check: ## Terraform: fmt check, then init (no backend, read-only lock files) and validate of each root
	$(TERRAFORM) fmt -check -recursive -diff $(TF_DIR)
	@set -e; for root in $(TF_ROOTS); do \
	  echo "== $$root"; \
	  $(TERRAFORM) -chdir="$$root" init -backend=false -input=false -lockfile=readonly; \
	  $(TERRAFORM) -chdir="$$root" validate; \
	done

tf-test: ## Terraform: tests of tf-check (needs Docker, ~50s; excluded from test-shell)
	bash scripts/tests/terraform-check.test.sh

tf-lock: ## Terraform: regenerate the .terraform.lock.hcl hashes for linux and darwin, amd64 and arm64
	@set -e; for root in $(TF_ROOTS); do \
	  echo "== $$root"; \
	  $(TERRAFORM) -chdir="$$root" providers lock $(TF_LOCK_PLATFORMS); \
	done
