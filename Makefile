# Developer entry points. Every PHP tool runs inside the pinned `dev` image of docker/php/Dockerfile
# (the host PHP is older than the project's PHP 8.5), as the host user so files are not owned by root.
#
# In CI the tools are installed on the runner, so the workflow calls the same targets with `RUN_PHP=`
# (empty): the commands are identical, only the wrapper disappears.

PHP_IMAGE ?= expense-tracker-php:dev
RUN_PHP ?= docker run --rm -u $(shell id -u):$(shell id -g) -v "$(CURDIR)":/app -w /app $(PHP_IMAGE)
COMPOSER = $(RUN_PHP) composer --working-dir=backend

# Building the image is only needed when the tools run in a container.
PHP_IMAGE_DEP = $(if $(strip $(RUN_PHP)),php-image)

.DEFAULT_GOAL := help
.PHONY: help php-image php-install test-unit lint-php audit fmt

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
