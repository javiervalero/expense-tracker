# Spike A: Bref 3 on php-85

Date: 2026-10-09. Plan: `docs/plans/phase-0-foundations.md` WP2. Harness: `spikes/bref-php85/` (deleted at the end of Phase 0; it is in commit `04231f396a5f`) (run it with
`spikes/bref-php85/run.sh`; one check can be run alone, for example `./run.sh A6`). Docker only, no AWS, no
credentials. Host: Docker 29.8.2, linux/amd64 images.

Method: `run.sh` was written first as a list of named checks A1 to A11. The first run was RED (10 of 11 failed,
because no Dockerfile, composer.json or scripts existed; only A5 passed, because it needs no build). The harness was
then built until all checks passed (GREEN). The final run printed `ALL CHECKS PASSED`.

**Result: 11 of 11 PASS. PHP 8.5 is workable. WebP works. RIE works. The telemetry switch is `BREF_PING_DISABLE=1`.**

## Digests (A1)

Taken on 2026-10-09 with `docker image inspect <image> --format '{{index .RepoDigests 0}}'`.

| Image | Digest |
|---|---|
| `bref/php-85:3` | `sha256:1ea1cbb20612cdb6620b1e073600d74eb64bd70450d03c4b47e3dfbc1f1fd740` |
| `bref/php-85-dev:3` | `sha256:2ecbb6efb67d5a4b5b60d840d5a77869fe140bfa0b9afd7f0704c617f3734f8e` |
| `bref/extra-gd-php-85:3` | `sha256:f2ef519e9e5b6fe61008e2290f9fa3ba4e7378e6594be2616eeb402a7452803f` |
| `php:8.5-fpm-alpine` | `sha256:fa01fb1645cd0fc566a5f146b099adace33b906571f972f71f2182a7c12d1cd7` |
| `php:8.5-cli-alpine` | `sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb` |
| `composer:2.10.3` | `sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac` |
| `nicolaka/netshoot:latest` (A6 sidecar) | `sha256:b09d9b21381f47a79b3cbcb30da25266dc17186ea00ae65e99fdc51396f48e70` |

Exact PHP of `bref/php-85:3`: **8.5.11** (NTS, Zend OPcache 8.5.11, built 2026-09-26). `config.platform.php` in
`spikes/bref-php85/composer.json` is set to `8.5.11`. The image tags move, so pin by digest in Dockerfiles and
refresh deliberately. `bref/bref` resolved to 3.0.12 (the Packagist latest of `bref/extra-php-extensions` is
3.0.13; Bref itself was pinned to 3.0.12 as the plan says).

## Results per item

| # | Result | Evidence command | Output excerpt |
|---|---|---|---|
| A1 | PASS | `docker run --rm --entrypoint php bref/php-85:3 -r 'echo PHP_VERSION;'` plus the `RepoDigests` above | `8.5.11`; composer.json platform equals it |
| A2a | PASS | `curl -si -X POST --data-binary @events/sqs-batch.json http://127.0.0.1:19001/2015-03-31/functions/function/invocations` with `BREF_RUNTIME=function`, CMD `worker.php` | `HTTP/1.1 200 OK` then `{"batchItemFailures":[{"itemIdentifier":"m2"}]}` |
| A2b | PASS (recorded) | same, with `events/sqs-throw.json` | `HTTP/1.1 200 OK`, `Content-Type: text/plain`, **no `X-Amz-Function-Error` header**, body `{"errorType":"RuntimeException","errorMessage":"handler blew up on t1","stackTrace":[...]}` |
| A2c | PASS (recorded) | same, container started without `BREF_RUNTIME` | `HTTP/1.1 502 Bad Gateway`, empty body. Container log: `PHP Fatal error: Uncaught RuntimeException: The environment variable BREF_RUNTIME is not set ... in /opt/bref/bootstrap.php:19`, then RIE `Runtime exited with error: exit status 255`, `Sandbox.Failure` |
| A3 | PASS | `BREF_RUNTIME=fpm`, CMD `public/index.php`, POST `events/apigw-v2.json` (headers: `cloudfront-viewer-address: 203.0.113.7:51234`; `sourceIp` 130.176.0.1) | PHP sees `"remote_addr":"127.0.0.1"`, `"http_cloudfront_viewer_address":"203.0.113.7:51234"`, `"env_app_spike_var":"visible-in-php"`, `"env_bref_runtime":"fpm"`; response envelope is v2 (`statusCode`, `headers`, `body`, `cookies`) |
| A4 | PASS | `COPY --from=bref/extra-gd-php-85:3 /opt /opt`, then `php scripts/gd-check.php` | `JPEG Support=1`, `PNG Support=1`, `WebP Support=1`, `AVIF Support=0`, `FreeType Support=1`; wrote webp/jpeg/png and `imagecreatefromwebp` read back 32x24 (`webp_roundtrip=ok`); GD is bundled 2.1.0-compatible |
| A5 | PASS | `php -r 'password_hash("...", PASSWORD_ARGON2ID)'` in each image | both: `8.5.11 argon2id $argon2id$v=19$m=65536,t=4,p=1$ verify=true` (php:8.5-fpm-alpine also 8.5.11) |
| A6 | PASS | Source read in `vendor/bref/bref` 3.0.12 and a real UDP capture (details below) | Switch `BREF_PING_DISABLE`; 14 packets in 1500 invocations without it, 0 with `BREF_PING_DISABLE=1` |
| A7 | PASS | `spikes/bref-php85/scripts/a7-layers-json.sh` (raw GitHub + Packagist) | `layers.json` exists: 148 layer names; `gd-php-85` has 22 regions, all at version `1` (`eu-west-1`: 1); not export-ignored, so it is also inside the Composer dist zip |
| A8 | PASS | `composer show --locked --tree bref/bref` in `composer:2.10.3` with `platform.php=8.5.11` | resolved `bref/bref 3.0.12`; tree below |
| A9 | PASS | `docker run --rm spikea-tools scripts/run-tools.sh` (image: `php:8.5-cli-alpine` + `composer:2.10.3`) | PHPUnit 13.4.1 `OK (3 tests, 4 assertions)`; PHPStan 2.3.1 level max + strict-rules 2.1.0 + phpstan-phpunit 2.1.1 `[OK] No errors`; PHP-CS-Fixer v3.95.27 `Found 0 of 3 files that can be fixed` (`PHP_CS_FIXER_IGNORE_ENV` unset, asserted); opis/json-schema 2.6.0 validated a `$schema: .../2020-12/schema` schema (valid and invalid case); aws-sdk-php 3.400.0 built `DynamoDbClient`, `S3Client`, `SqsClient` |
| A10 | PASS | `apk add $PHPIZE_DEPS && pecl install pcov && docker-php-ext-enable pcov` on `php:8.5-cli-alpine`, then `phpunit --coverage-text` | `PHP 8.5.11 with PCOV 1.0.12`, `Lines: 100.00% (23/23)`; no Xdebug fallback needed |
| A11 | PASS | `php -d error_reporting=-1 -r 'require "/var/task/vendor/autoload.php"; ...'` in the worker image (`composer install --no-dev --classmap-authoritative`) | `autoload_ok 8.5.11`, `bref_class_ok`, `platform_check_present`, `no_dev_ok` (no `vendor/phpunit`) |

A9 detail: the tests ran with `error_reporting(E_ALL)`, an error handler that turns every diagnostic (including
`E_DEPRECATED`) into an exception, and `failOnDeprecation/Notice/Warning/Risky` set in `phpunit.xml`. The tools
themselves ran with `php -d error_reporting=-1 -d display_errors=1`. Nothing fired. PHPUnit 13 works, so the 12.5
fallback is not needed. One first-run PHP-CS-Fixer failure was my own ruleset (`@PHP8x4Migration` rewrote
`(new X())->m()` to `new X()->m()`, a PHP 8.4 syntax); it was a style choice, not an 8.5 problem, and the rule was
dropped.

### A6 detail

- Code path: `Bref\Runtime\LambdaRuntime::processNextEvent()` calls `ping()` (LambdaRuntime.php line 94). `ping()`
  (line 391) returns immediately if `$_SERVER['BREF_PING_DISABLE']` is truthy (line 393; so `1` or `true` disables,
  `0` and empty do not), returns if the `sockets` extension is missing, and otherwise acts on about 1% of
  invocations (`rand(0, 99) > 0` returns). It sends one statsd UDP datagram (68 bytes here: `Invocations_100`,
  `Layer_<layer>_100`, `Cold_100`, `Warm_100`) to `3.219.198.164:8125`. Nothing else is sent. The docblock says
  "Yes, set the `BREF_PING_DISABLE` environment variable to `1`".
- Traffic test (not only source reading; the ping is not skipped under RIE): container `spikea-telem-*` runs the
  worker in function mode; a `nicolaka/netshoot` sidecar with `--network container:<worker> --cap-add NET_RAW
  --cap-add NET_ADMIN` ran `tcpdump -nn -i any 'udp and not port 53'`; 1500 invocations were sent from a second
  container in the same network namespace.
  - Switch not set: 14 packets, for example `Out IP 172.17.0.5.55464 > 3.219.198.164.8125: UDP, length 68`
    (about 1%, as the code says).
  - `BREF_PING_DISABLE=1`: 0 packets.
  - Limit: with the switch off, a 1% sample over a short run can in theory show 0 packets (probability about
    3e-7 for 1500 invocations), so the check asserts at least 1 packet with the switch off and exactly 0 with it on.
- The FPM path uses the same `LambdaRuntime`, so the same variable applies. Not separately captured.

### A8 tree (PHP 8.5.11 platform pin)

```
bref/bref 3.0.12
|--crwlr/query-string ^1.0.3
|--ext-curl *
|--hollodotme/fast-cgi-client ^3.0.1 (ext-json)
|--nyholm/psr7 ^1.4.1 (psr/http-factory, psr/http-message)
|--php >=8.2
|--psr/container ^1.0|^2.0
|--psr/http-message ^1.0|^2.0
|--psr/http-server-handler ^1.0
|--riverline/multipart-parser ^2.1.2 (ext-mbstring)
`--symfony/process ^5.4|^6.4|^7.0|^8.0   (locked v8.1.7, requires php >=8.4.1)
```

Differences from the ADR 0012 text: `symfony/process` locked at v8.1.7, which needs PHP >= 8.4.1, so it is fine on 8.5
but would be unusable on the 8.3 that ADR 0003 first chose. No Symfony polyfill packages appear in the Bref 3.0.12
tree on 8.5 (the ADR lists "Symfony polyfills"); update that sentence. The lock file is
`spikes/bref-php85/composer.lock`.

## Decisions

### D1: PHP 8.5 or 8.4? Recommendation: keep PHP 8.5, no fallback needed

Every pinned tool installs and runs on 8.5.11 under E_ALL with deprecations as errors (PHPUnit 13.4.1, PHPStan 2.3.1
with strict-rules and phpstan-phpunit, PHP-CS-Fixer 3.95.27 without the ignore-env switch, opis/json-schema 2.6.0,
aws-sdk-php 3.400.0), pcov 1.0.12 builds, Argon2id and GD work, and the Bref runtime boots. The D1 trigger ("a pinned
tool fails on 8.5") did not fire. Record `8.5.11` as the `config.platform.php` value and the layer's patch version in
ADR 0012.

### D2: WebP? Recommendation: keep WebP in the upload policy

`gd_info()` reports `WebP Support=1` with `bref/extra-gd-php-85:3` copied into `bref/php-85:3`, and a generated
WebP was written and read back with `imagecreatefromwebp`. Do not amend ADR 0006. AVIF is not supported
(`AVIF Support=0`); do not offer it. Caveat: this was tested by copying the layer image content to `/opt`, which
is how a Lambda layer is laid out, but not on a real Lambda.

### D3: RIE? Recommendation: use the RIE that ships in `bref/php-85:3`; no extra binary

`/usr/local/bin/aws-lambda-rie` is present and the image entrypoint `/lambda-entrypoint.sh` starts it when
`AWS_LAMBDA_RUNTIME_API` is unset, serving on container port 8080 at
`/2015-03-31/functions/function/invocations`. Function mode returns `batchItemFailures` correctly. Notes for WP10
and WP11:

1. A handler exception returns **HTTP 200 with no `X-Amz-Function-Error` header**; the failure is only visible as
   `errorType`/`errorMessage`/`stackTrace` in the JSON body. A relay must treat a body containing `errorType` as a
   failed invocation (do not rely on the header or the status code). On real Lambda the event source mapping sees
   the failure itself; the local relay must emulate it.
2. A missing `BREF_RUNTIME` gives HTTP 502 with an empty body and a PHP fatal in the container log (the process
   restarts in a loop). Always set `BREF_RUNTIME` explicitly in Dockerfile `ENV` or Terraform.
3. Handler: the CMD argument is the handler file (`worker.php`, a file that returns a closure
   `fn(array $event, Context $context)`). `BREF_RUNTIME=function`; for FPM use `BREF_RUNTIME=fpm` and CMD
   `public/index.php`.

### D7: telemetry switch name? Recommendation: `BREF_PING_DISABLE=1`

The name in ADR 0012 is right. Set it to the literal string `1` (any truthy PHP string; `0` or empty does not
disable). Verified by traffic capture, not only by source. Set it in the Dockerfile `ENV` for local/CI and in the
Lambda environment in Terraform.

## Other findings

- **A7 / D8:** `bref/extra-php-extensions` publishes `layers.json` (shape `{layer-name: {region: version}}`), so the
  `gd-php-85` layer version can be read from it (currently `1` in all 22 regions) and D8's "pinned Terraform
  variable" fallback is not needed; still pin the version in Terraform and update it deliberately. The file is in
  the Composer dist zip, so it can also be read from `vendor/bref/extra-php-extensions/layers.json` if the package
  is required. Layer ARN account (from the ADR): 403367587399.
- **A3 and security:** `REMOTE_ADDR` is always `127.0.0.1` under Bref FPM, and `cloudfront-viewer-address` reaches
  PHP verbatim as `HTTP_CLOUDFRONT_VIEWER_ADDRESS` (format `ip:port`; IPv6 form is a Phase 8 check). The value is
  client-controlled unless the origin is reachable only through CloudFront, as the ADR already says. The test event
  carried it as an API Gateway header, so this does not prove CloudFront overwrites a forged one (a Phase 8 item).
- **A6 and privacy:** without the switch, the runtime sends an anonymous 68-byte UDP datagram to a Bref-owned IP for
  about 1% of invocations. Disabling it is a one-line env var.
- **Not covered here:** the "Vite proxy sets a header from the client socket address" item of ADR 0012 belongs
  to another work package and was not in A1 to A11. Lambda arm64 was not tested (the layer is x86_64; the images
  here are linux/amd64).

## Harness and cleanup

Files under `spikes/bref-php85/`: `run.sh`, `Dockerfile.worker`, `Dockerfile.tools`, `worker.php`,
`public/index.php`, `composer.json`, `composer.lock`, `events/{sqs-batch,sqs-throw,apigw-v2}.json`,
`scripts/{gd-check.php,a7-layers-json.sh,run-tools.sh,run-coverage.sh}`, `tools/` (composer.json, phpunit.xml,
phpstan.neon, `.php-cs-fixer.dist.php`, a small `Probe` class and its test), `.gitignore` (ignores `.out/`, which
holds per-check logs). Containers are named `spikea-*`, use host ports 19001 to 19003 on 127.0.0.1 and the
default Docker bridge (no networks are created); `run.sh` removes its containers on exit. Images built locally:
`spikea-worker`, `spikea-vendor`, `spikea-tools` (left in the cache, safe to delete with `docker rmi`).
