# PHP images for the expense tracker. Base images are pinned by digest (refresh deliberately; see
# docs/spikes/bref-php85.md for how the digests were taken).
#
#   fpm: runtime for the local API (php-fpm behind nginx), mirrors production PHP.
#   dev: tools image (CLI + Composer + pcov) used for tests, static analysis and formatting.

FROM php:8.5-fpm-alpine@sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7 AS fpm
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini

FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb AS dev
COPY --from=composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip \
 && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
 && pecl install pcov-1.0.12 \
 && docker-php-ext-enable pcov \
 && apk del --no-network .build-deps
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
# The container runs as the host user, who has no home directory: keep Composer and tool caches in /tmp.
ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_CACHE_DIR=/tmp/composer/cache \
    COMPOSER_NO_INTERACTION=1
WORKDIR /app
