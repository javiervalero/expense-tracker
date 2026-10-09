# Developer entry points. Every PHP tool runs inside the pinned `dev` image of docker/php/Dockerfile
# (the host PHP is older than the project's PHP 8.5), as the host user so files are not owned by root.
#
# In CI the tools are installed on the runner, so the workflow calls the same targets with `RUN_PHP=`
# (empty): the commands are identical, only the wrapper disappears.

PHP_IMAGE ?= expense-tracker-php:dev
RUN_PHP ?= docker run --rm -u $(shell id -u):$(shell id -g) -v "$(CURDIR)":/app -w /app $(PHP_IMAGE)
COMPOSER = $(RUN_PHP) composer --working-dir=backend

# The Compose project name (`expense-tracker`) is also set in docker-compose.yml; repeated here so that a
# stray COMPOSE_PROJECT_NAME in the environment can never point these commands at another project.
COMPOSE = docker compose -p expense-tracker

# Building the image is only needed when the tools run in a container.
PHP_IMAGE_DEP = $(if $(strip $(RUN_PHP)),php-image)

.DEFAULT_GOAL := help
.PHONY: help php-image php-install test-unit lint-php audit fmt test-shell check-platform up down ps logs test-conformance

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

fmt: $(PHP_IMAGE_DEP) ## Rewrite backend PHP files with PHP-CS-Fixer
	$(COMPOSER) run cs:fix

test-shell: ## Tests of the shell guards (scripts/tests/*.test.sh)
	@set -e; for t in scripts/tests/*.test.sh; do echo "== $$t"; bash "$$t"; done

# The worker Dockerfile arrives in WP10. Until it exists there is nothing real to check, so the target says so
# and succeeds; from the moment docker/worker/Dockerfile exists the check runs for real (nothing to edit).
# The guard itself is covered by `make test-shell`, against fixtures.
WORKER_DOCKERFILE = docker/worker/Dockerfile

check-platform: ## composer.json config.platform.php equals the PHP in the pinned bref/php-85 layer
	@if [ -f $(WORKER_DOCKERFILE) ]; then \
		$(MAKE) --no-print-directory php-image && \
		PHP_IMAGE=$(PHP_IMAGE) scripts/check-php-platform.sh --with-vendor $(WORKER_DOCKERFILE); \
	else \
		echo "check-platform: SKIPPED, $(WORKER_DOCKERFILE) does not exist yet (added in WP10); the guard is tested by 'make test-shell'"; \
	fi

.env:
	cp .env.example .env
	@echo "Created .env from .env.example (local, non-secret values)."

up: .env ## Start the local stack and wait until every service is healthy (idempotent)
	$(COMPOSE) up -d --wait

down: ## Stop the local stack and remove its containers (data volumes are kept; never uses -v)
	$(COMPOSE) down

ps: ## Show the local stack's services
	$(COMPOSE) ps

logs: ## Follow the local stack's logs (Ctrl+C to stop)
	$(COMPOSE) logs -f --tail=100

test-conformance: .env php-image ## Conformance tests (DynamoDB Local, ElasticMQ) in the php container; run `make up` first
	$(COMPOSE) --profile tools run --rm --user $(shell id -u):$(shell id -g) php vendor/bin/phpunit --testsuite conformance
