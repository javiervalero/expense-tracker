# Local-only tooling image (never deployed): runs bin/bootstrap.php for the `local-aws-init` service and bin/relay.php
# (`php -S`) for the `s3-events-relay` service; the worker runner joins in WP11. Base images are pinned by digest, the same ones as
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

# What actually runs: PHP, the code and vendor/. No Composer, and a non-root user.
FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS runtime
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
RUN addgroup -S -g 10001 localaws && adduser -S -u 10001 -G localaws -H -s /sbin/nologin localaws
WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY --from=vendor /app/src ./src
COPY docker/local-aws/bin ./bin
USER 10001:10001
ENTRYPOINT ["php"]
CMD ["bin/bootstrap.php"]
