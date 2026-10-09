#!/bin/sh
# Runs inside the spikea-tools image (PHP 8.5, E_ALL, deprecations as errors).
set -eu
cd /app
php -v | head -1
for p in phpunit/phpunit phpstan/phpstan phpstan/phpstan-strict-rules phpstan/phpstan-phpunit friendsofphp/php-cs-fixer opis/json-schema aws/aws-sdk-php; do
  composer show "$p" | grep -E '^(name|versions)' | paste -s -d" " -
done
# Make every PHP diagnostic loud for the tools themselves too.
PHP="php -d error_reporting=-1 -d display_errors=1 -d zend.assertions=1"
echo "--- phpunit"
$PHP vendor/bin/phpunit --no-coverage && echo PHPUNIT_OK
echo "--- phpstan (level max + strict-rules + phpunit)"
$PHP vendor/bin/phpstan analyse --no-progress --memory-limit=512M && echo PHPSTAN_OK
echo "--- php-cs-fixer (PHP_CS_FIXER_IGNORE_ENV must be unset)"
test -z "${PHP_CS_FIXER_IGNORE_ENV:-}"
$PHP vendor/bin/php-cs-fixer fix --dry-run --diff --no-interaction && echo CSFIXER_OK
