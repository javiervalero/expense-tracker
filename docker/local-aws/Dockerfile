# Local-only tooling image (never deployed): runs bin/bootstrap.php for the `local-aws-init` service and bin/relay.php
# (`php -S`) for the `s3-events-relay` service and bin/runner.php for the `worker-runner` service. Base images are pinned by digest, the same ones as
# docker/php/Dockerfile. Build context: the repository root (see docker-compose.yml).

# Resolves the production dependencies from composer.lock. Composer and its cache stay in this stage.
FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS vendor
COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
ENV COMPOSER_NO_INTERACTION=1
WORKDIR /app
COPY docker/local-aws/composer.json docker/local-aws/composer.lock ./
RUN composer install --no-dev --no-progress --prefer-dist --no-scripts --no-autoloader
COPY docker/local-aws/src ./src
RUN composer dump-autoload --no-dev --classmap-authoritative

# The pcntl extension, which the worker runner needs to react to SIGTERM/SIGINT (the official image does not ship it).
# Only pcntl.so and its ini file are copied on (the directory name is the PHP 8.5 extension API of the pinned base image); the compilers stay in this stage.
FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS pcntl
RUN apk add --no-cache $PHPIZE_DEPS && docker-php-ext-install pcntl

# What actually runs: PHP, the code and vendor/. No Composer, and a non-root user.
FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS runtime
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY --from=pcntl /usr/local/lib/php/extensions/no-debug-non-zts-20250925/pcntl.so /usr/local/lib/php/extensions/no-debug-non-zts-20250925/pcntl.so
COPY --from=pcntl /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini /usr/local/etc/php/conf.d/docker-php-ext-pcntl.ini
RUN addgroup -S -g 10001 localaws && adduser -S -u 10001 -G localaws -H -s /sbin/nologin localaws
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/src ./src
COPY docker/local-aws/bin ./bin
# The one table definition (ADR 0002); Phase 7's Terraform reads the same file.
COPY infra/dynamodb/table.json ./config/dynamodb-table.json
USER 10001:10001
ENTRYPOINT ["php"]
CMD ["bin/bootstrap.php"]
