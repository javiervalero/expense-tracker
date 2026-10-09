#!/bin/sh
set -eu
cd /app
php -d error_reporting=-1 vendor/bin/phpunit --coverage-text --colors=never | tee /tmp/cov.txt
grep -q 'Lines:' /tmp/cov.txt && echo COVERAGE_OK
