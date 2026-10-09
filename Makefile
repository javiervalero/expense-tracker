# Developer entry points. Every PHP tool runs inside the pinned `dev` image of docker/php/Dockerfile
# (the host PHP is older than the project's PHP 8.5), as the host user so files are not owned by root.
#
# In CI the tools are installed on the runner, so the workflow calls the same targets with `RUN_PHP=`
# (empty): the commands are identical, only the wrapper disappears.

PHP_IMAGE ?= expense-tracker-php:dev
RUN_PHP ?= docker run --rm -u $(shell id -u):$(shell id -g) -v "$(CURDIR)":/app -w /app $(PHP_IMAGE)
COMPOSER = $(RUN_PHP) composer --working-dir=backend
# docker/local-aws is a separate Composer project (the local-only tooling of the stack, namespace LocalAws\).
LOCAL_AWS_COMPOSER = $(RUN_PHP) composer --working-dir=docker/local-aws

# The Compose project name (`expense-tracker`) is also set in docker-compose.yml; repeated here so that a
# stray COMPOSE_PROJECT_NAME in the environment can never point these commands at another project.
COMPOSE = docker compose -p expense-tracker

# Building the image is only needed when the tools run in a container.
PHP_IMAGE_DEP = $(if $(strip $(RUN_PHP)),php-image)

.DEFAULT_GOAL := help
.PHONY: help php-image php-install test-unit lint-php audit fmt local-aws-install lint-local-aws test-local-aws audit-local-aws test-shell check-platform up down ps logs test-conformance test-integration check-fixtures test-fast

help: ## Show this help
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  %-14s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

php-image: ## Build the PHP tools image (pinned base images, pcov, Composer)
	docker build --target dev -t $(PHP_IMAGE) -f docker/php/Dockerfile .

php-install: $(PHP_IMAGE_DEP) ## Install backend Composer dependencies from composer.lock
	$(COMPOSER) install --no-progress --prefer-dist

test-unit: $(PHP_IMAGE_DEP) ## Backend unit + architecture tests with coverage, 80% bar on src/
	$(COMPOSER) run test:coverage
	$(COMPOSER) run coverage:check

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

test-shell: ## Tests of the shell guards (scripts/tests/*.test.sh)
	@set -e; for t in scripts/tests/*.test.sh; do echo "== $$t"; bash "$$t"; done

# Builds the tools image, then checks the real worker Dockerfile: the PHP in its pinned bref/php-85 layer must equal
# composer.json config.platform.php, and the --no-dev vendor/ must load on that PHP. A missing Dockerfile is an error
# (the check used to skip until WP10 added it). The guard itself is covered by `make test-shell`, against fixtures.
WORKER_DOCKERFILE = docker/worker/Dockerfile

check-platform: ## composer.json config.platform.php equals the PHP in the pinned bref/php-85 layer
	$(MAKE) --no-print-directory php-image
	PHP_IMAGE=$(PHP_IMAGE) scripts/check-php-platform.sh --with-vendor $(WORKER_DOCKERFILE)

.env:
	cp .env.example .env
	@echo "Created .env from .env.example (local, non-secret values)."

# `make up` starts the stack in three steps, so that a service that needs the bootstrap to have finished is never
# started (or waited for) before it:
#   1. everything except the two lists below: `up -d --wait` (returns when all are healthy);
#   2. ONE_SHOT_SERVICES: run in the foreground. They exit on purpose, and `up --wait` counts "exited (0)" as a
#      failure, so they cannot be in step 1. Their log is shown and a non-zero exit code fails `make up`;
#   3. LATE_SERVICES: services that depend on a one-shot finishing, started with `up -d --wait` after it.
# LATE_SERVICES is empty for now. WP12 adds `api` here (`LATE_SERVICES = api`) and nothing else needs to change. `worker-runner` is in step 1 on purpose: it needs only sqs and worker (queues come from elasticmq.conf), not the bootstrap.
ONE_SHOT_SERVICES = local-aws-init
LATE_SERVICES =

# The step-1 list is computed once and checked: if `docker compose config --services` fails (broken compose file,
# a missing required variable) or yields nothing to start, `make up` stops with an error instead of running
# `up` with an empty list, which would silently start EVERY service (including the one-shot ones, out of order).
# --build: the local-aws-init image is built from this repository, so a code change must reach it (an unchanged
# build is instant, from the layer cache). Running the bootstrap again is safe: it logs "no changes".
up: .env ## Start the local stack, run the bootstrap, and wait until all is healthy (idempotent)
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

test-conformance: .env php-image ## Conformance tests (DynamoDB Local, ElasticMQ, S3 emulator) in the php container; run `make up` first
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
# `make test-fast` is the same without the tests marked `slow` (the ~35 s dead-letter test).
PHPUNIT_INTEGRATION = $(COMPOSE) --profile tools run --rm --user $(shell id -u):$(shell id -g) php vendor/bin/phpunit --testsuite integration
# One --exclude-group per group: PHPUnit 13 does not split a comma-separated list.
EXCLUDE_SLOW = $(if $(FAST),--exclude-group slow)
PIPE_MARKERS = build/pipe-test

test-integration: .env php-image ## Pipeline integration tests (S3 -> relay -> ElasticMQ -> worker-runner -> worker) in the php container; run `make up` first
	@set -e; \
	trap 'code=$$?; trap - EXIT; $(COMPOSE) start worker-runner >/dev/null || echo "make test-integration: could not start worker-runner again; run make up" >&2; exit $$code' EXIT; \
	trap 'exit 130' INT; trap 'exit 143' TERM HUP; \
	echo "make test-integration, part 1: worker-runner stopped (the suites below read the receipts queue themselves)"; \
	$(COMPOSE) stop worker-runner; \
	$(PHPUNIT_INTEGRATION) --exclude-group needs-runner $(EXCLUDE_SLOW); \
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

test-fast: ## Like test-integration, without the tests marked `slow` (the dead-letter test, ~35 s)
	@$(MAKE) --no-print-directory test-integration FAST=1

# The S3 event fixtures exist twice (backend/ and docker/local-aws/ are separate Composer projects). They must stay
# byte-identical, or the parser and the relay would be tested against different "real" events.
FIXTURES_A ?= backend/tests/Fixtures/s3-events
FIXTURES_B ?= docker/local-aws/tests/Fixtures/s3-events

check-fixtures: ## The two copies of the S3 event JSON fixtures are identical
	@scripts/check-fixtures.sh $(FIXTURES_A) $(FIXTURES_B)
