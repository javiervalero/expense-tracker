# Receipt worker image: the Bref PHP 8.5 runtime for AWS Lambda plus the GD layer, running in "function" mode
# (ADR 0012). Locally Compose runs it with the Runtime Interface Emulator that ships in the Bref image
# (listening on container port 8080); `worker-runner` (WP11) plays the part of the SQS event source mapping.
# The same code is later deployed to Lambda from a zip (Terraform), not from this image.
#
# Base images are pinned by digest (docs/spikes/bref-php85.md says how they were taken); refresh deliberately.
# Build context: the repository root, limited to what is COPYed below by Dockerfile.dockerignore.

# Production dependencies only (no dev tools), resolved from composer.lock against the PHP of the layer
# (config.platform.php = 8.5.11, checked against this very image by `make check-platform`).
FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS vendor
ENV COMPOSER_NO_INTERACTION=1
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-progress --prefer-dist --no-scripts --no-autoloader
COPY backend/src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM bref/php-85:3@sha256:1ea1cbb20612cdb6620b1e073600d74eb64bd70450d03c4b47e3dfbc1f1fd740 AS runtime
# The same copy a Lambda layer gives: the GD extension and its ini file under /opt.
COPY --from=bref/extra-gd-php-85:3@sha256:f2ef519e9e5b6fe61008e2290f9fa3ba4e7378e6594be2616eeb402a7452803f /opt /opt
# BREF_RUNTIME has no default: without it the container crash-loops (HTTP 502, empty body).
# BREF_PING_DISABLE=1 (the literal 1) stops the runtime's anonymous usage ping to a Bref-owned address.
ENV BREF_RUNTIME=function \
    BREF_PING_DISABLE=1
WORKDIR /var/task
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/src ./src
COPY backend/entrypoints/lambda/worker.php ./entrypoints/lambda/worker.php
# Bref scans /var/task/php/conf.d for extra ini files (checked by `make check-php-ini`).
COPY backend/php/conf.d/app.ini ./php/conf.d/app.ini
# Not root: the files above are world-readable and the handler writes nothing.
USER 10001:10001
# The handler: a file that returns the closure called for each invocation.
CMD ["entrypoints/lambda/worker.php"]
