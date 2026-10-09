# 0012. Runtime platform: PHP 8.5, x86_64, Bref 3 configuration and client IP

- Status: Proposed
- Date: 2026-10-09
- Amends: [0001](0001-serverless-aws-php-on-lambda-with-bref.md), [0003](0003-vanilla-php-backend-structure.md),
  [0004](0004-server-side-sessions-with-cookies.md), [0005](0005-single-origin-through-cloudfront.md),
  [0007](0007-local-development-with-docker-compose.md), [0009](0009-terraform-for-infrastructure.md)

## Context

Phase 0 research answered the "To verify in Phase 0" questions of ADR 0001. It was tested hands-on in Bref's
official Docker images and checked against primary sources on 2026-10-09. Four findings change or pin down accepted
decisions.

1. **PHP version.** ADR 0003 chose PHP 8.3. PHP 8.3 has only had security fixes since 2025-12-31, and PHP 8.4 moves
   to security-only on 2026-12-31. PHP 8.5 has active support until 2027-12-31 and security fixes until 2029-12-31.
   These images exist for PHP 8.5: `bref/php-85:3`, `bref/extra-gd-php-85:3` and `php:8.5-fpm-alpine`. The research
   also showed that when Composer runs on a different PHP than the Lambda layer, it can lock packages the layer cannot
   run (seen with `symfony/process` 8.x against PHP 8.3).
2. **Bref 3 without the Serverless Framework.** Bref 3 ships one unified runtime. Its bootstrap
   (`/opt/bref/bootstrap.php`) needs the environment variable `BREF_RUNTIME` set to `fpm`, `function` or
   `console`. Normally the Serverless plugin sets this, so with Terraform we must set it ourselves. Bref's images
   ship the Lambda Runtime Interface Emulator (RIE). The worker was invoked through it (tested on `bref/php-83:3`)
   on container port 8080 at `/2015-03-31/functions/function/invocations` and returned `batchItemFailures`
   correctly. Bref's layers also send an anonymous usage ping. `bref/bref` brings in these runtime packages:
   crwlr/query-string, hollodotme/fast-cgi-client, nyholm/psr7, psr/container, psr/http-message,
   psr/http-server-handler, riverline/multipart-parser, symfony/process and Symfony polyfills.
3. **GD is not in Bref's base build.** It comes from Bref's extra-extensions layer `gd-php-85`, published from AWS
   account 403367587399. The extra-extension layers have **no arm64 build**. Argon2id (`PASSWORD_ARGON2ID`) is
   available in the base build.
4. **Client IP.** Bref's FPM handler hard-codes `REMOTE_ADDR = 127.0.0.1`. Behind CloudFront, the source IP that
   API Gateway sees is a CloudFront edge's IP. Per-IP login throttling (ADR 0004) therefore needs the viewer's
   address from CloudFront. That header can only be trusted if nobody can reach API Gateway except through our
   distribution. ADR 0005 says a secret origin header protects the API, but not where it is checked, where the
   secret lives or how it is rotated. HTTP APIs have no resource policies and cannot have AWS WAF attached.
   Turning off the default `execute-api` endpoint requires a custom domain, and that domain is still public.

## Decision

### 1. PHP 8.5, pinned to the layer

- PHP **8.5** everywhere, with `declare(strict_types=1)` (replaces "PHP 8.3" in ADR 0003).
- `composer.json` sets `"config": {"platform": {"php": "8.5.<patch>"}}`, where `<patch>` is the exact PHP version of
  the pinned `bref/php-85` layer. A CI check fails if the two differ, and another loads the `--no-dev` `vendor/`
  inside the pinned `bref/php-85:3` image.
- If Phase 0 finds that a pinned tool (PHPUnit, PHPStan, PHP-CS-Fixer, opis/json-schema) does not run cleanly on
  8.5, fall back to PHP 8.4 and record it here.

### 2. Lambda configuration (Terraform, no Serverless plugin)

| Setting | API | Worker |
|---|---|---|
| `runtime` | `provided.al2023` | `provided.al2023` |
| `architectures` | `["x86_64"]` | `["x86_64"]` |
| `handler` | `public/index.php` | `entrypoints/lambda/worker.php` (returns a closure) |
| `BREF_RUNTIME` | `fpm` | `function` |
| Layers | `arn:aws:lambda:<region>:873528684822:layer:php-85:<v>` | same, plus `arn:aws:lambda:<region>:403367587399:layer:gd-php-85:<v>` |

- **All Lambdas run on x86_64**, because the GD layer has no arm64 build. Using one architecture for both functions
  keeps builds and tests simple.
- Only the worker gets the GD layer. The API does not process images.
- **Layer versions follow `composer.lock`.** Terraform reads the `php-85` layer version from
  `vendor/bref/bref/layers.json` (passed in as a variable, or written to an `*.auto.tfvars.json` by `make build`, so
  `terraform validate` works without `vendor/`), so upgrading `bref/bref` and the layer is one change. The HCL is our
  own. The reference repo psantus/terraform-null-bref-layer has no licence, so none of its code is copied. The
  `gd-php-85` version comes from the matching `bref/extra-php-extensions` release. It is read the same way if that
  package publishes a `layers.json`; otherwise it is a pinned Terraform variable bumped in the same PR as `bref/bref`.
- **Telemetry off:** both functions set `BREF_PING_DISABLE=1`. The app needs no outbound calls to third parties.

### 3. Local images

- **Worker:** `FROM bref/php-85:3`, then `COPY --from=bref/extra-gd-php-85:3 /opt /opt`, with `BREF_RUNTIME=function`.
  The local runner invokes it through the bundled RIE. This **drops ADR 0007's in-process fallback**.
- **API:** nginx + `php:8.5-fpm-alpine`. Same PHP minor version as Lambda; the patch version may differ.
- Images are pinned by digest, as in ADR 0007.

### 4. Client IP and origin lockdown

1. **CloudFront (`/api/*`).**
   - A custom origin request policy forwards all cookies, all query strings, and an allow-list of headers:
     `Origin`, `X-Requested-With`, `Content-Type`, `Accept` and `CloudFront-Viewer-Address`.
   - `Host` is not forwarded, so API Gateway still sees its own host name.
   - A custom origin header, `X-Origin-Verify: <secret>`, is added to every API request.
2. **Origin check in PHP.** The first middleware after error handling compares `X-Origin-Verify` against the
   configured values with `hash_equals`.
   - A missing or wrong header gets `403` problem details, and the header value is never logged.
   - No route is exempt, including `/health`.
   - The setting is required: an empty `ORIGIN_VERIFY_SECRETS` stops the app at startup (fail closed). There is no
     "disabled" mode.
3. **Client IP.** `Http\ClientIp` reads only the header named in `CLIENT_IP_HEADER`, which is
   `CloudFront-Viewer-Address` in every environment (`$_SERVER['HTTP_CLOUDFRONT_VIEWER_ADDRESS']`).
   - It strips the port after the last `:` (brackets are optional), then validates with `FILTER_VALIDATE_IP`.
   - The throttle key is the IPv4 address, or the **IPv6 /64 prefix**, because one client can easily hold a whole
     /64.
   - `REMOTE_ADDR` and `X-Forwarded-For` are never used.
   - If the header is missing or invalid, the request is throttled under one shared key, `unknown`, and an error is
     logged (fail closed). Behind the origin check, that can only happen if something is misconfigured.
4. **The secret.** Terraform generates it (`random_password`, 32+ characters, marked sensitive). It is set as the
   CloudFront custom header and as the API Lambda's `ORIGIN_VERIFY_SECRETS` environment variable. It is never
   committed or printed.
   - There are two slots, A and B. The Lambda accepts both; CloudFront sends the active one.
   - To rotate, regenerate the inactive slot (apply), then make it active (apply). There is no downtime and nothing
     to automate.
   - Rotate when exposure is suspected or when someone who had access leaves.
   - Why an environment variable and not SSM or Secrets Manager: the value already sits in plain form in the
     CloudFront configuration and in Terraform state. SSM would add a call on every cold start, an IAM permission and
     SDK code without reducing who can read the secret. Secrets Manager also costs $0.40 a month and its managed
     rotation would need a rotation Lambda that also updates CloudFront.
5. **Local.** The code paths match AWS:
   - The Vite proxy adds `X-Origin-Verify` (a non-secret local value from `.env`) and `CloudFront-Viewer-Address`
     (the browser's socket address and port).
   - nginx fills `CloudFront-Viewer-Address` from `$remote_addr:$remote_port` only when it is absent (direct calls).
   - PHPUnit integration tests and `scripts/smoke.sh` send `X-Origin-Verify` themselves.

### What this amends

- **0001:** layer `php-85` + `gd-php-85`, x86_64, `BREF_RUNTIME` configuration, telemetry off. Its "To verify in
  Phase 0" list is answered here.
- **0003:** PHP 8.3 → 8.5; Composer platform pin; origin-verify middleware added to the chain; `Http\ClientIp`.
- **0004:** where the "IP" in per-IP throttling comes from; IPv6 counted per /64.
- **0005:** forwarding `CloudFront-Viewer-Address`; how the secret origin header is checked, stored and rotated.
- **0007:** the worker image is `bref/php-85:3` + GD; RIE confirmed, so the in-process fallback is removed; local
  origin and client-IP headers.
- **0009:** Lambda `runtime`/`architectures`/`handler`/`BREF_RUNTIME`; layer versions read from the lock file.

Related, not decided here: the S3 emulator choice (pgsty/silo recommended) is recorded in ADR 0007's pending section
by the Phase 0 PR.

## Alternatives considered

- **Stay on PHP 8.3.** It already gets only security fixes, and those end on 2027-12-31.
- **PHP 8.4.** More mature tool support, but it moves to security-only on 2026-12-31, before the first deployment is
  likely to settle. Kept as the fallback if Phase 0 finds a tool that doesn't run on 8.5.
- **arm64 (Graviton).** About 20% cheaper per GB-second, but Bref's extra-extension layers (GD) have no arm64 build.
  Building and maintaining our own GD layer means maintaining part of a runtime.
- **Thumbnails without GD** (Imagick layer, pure-PHP libraries). Imagick is also an extra layer; pure-PHP decoders
  are slow or unmaintained.
- **Client IP from `X-Forwarded-For` or `requestContext.http.sourceIp`.** `X-Forwarded-For` can be spoofed from its
  left end, and picking the right entry depends on how many proxies sit in front. `sourceIp` is CloudFront's
  address. One explicit header set by CloudFront is simpler and harder to get wrong.
- **IPv6 throttling per exact address.** Trivially bypassed: one client usually holds a whole /64.
- **Lambda authorizer checking the secret.** Rejects requests before the API Lambda runs, but adds a second
  function, more Terraform and extra latency, and still needs the same secret.
- **Custom domain with the default `execute-api` endpoint disabled.** Needs Route 53 and an ACM certificate, and the
  custom domain is still public, so on its own it does not restrict access to CloudFront. Could be added later on
  top of the secret header.
- **AWS WAF.** Cannot be attached to an HTTP API. On CloudFront it does not protect the origin. It also has a fixed
  monthly fee.
- **REST API with a resource policy (CloudFront IP prefix list).** Costs more per request, and the prefix list
  matches every CloudFront distribution, including an attacker's.
- **Lambda function URL behind CloudFront Origin Access Control (OAC).** CloudFront signs each request with IAM, so
  no shared secret is needed: this is the strongest lockdown. But it removes API Gateway and its throttling (ADR 0001).
  Also, `POST`/`PATCH`/`DELETE` requests with a body would need the browser to send the body's SHA-256 in
  `x-amz-content-sha256`. A candidate if API Gateway is ever dropped.
- **Secret in SSM Parameter Store or Secrets Manager.** See Decision §4.4.

## Consequences

- PHP is supported for longer, and locked dependencies always run on the layer.
- The worker's local path is the real Lambda runtime (RIE), so ADR 0007's fallback code is never written.
- x86_64 costs about 20% more per GB-second than arm64. That is negligible at proof-of-concept volume. Developers on
  Apple Silicon run the worker image under emulation.
- Per-IP throttling works on AWS and locally through the same code. The client IP cannot be forged without the
  origin secret.
- The `execute-api` URL is still publicly reachable. Requests without the secret are rejected in milliseconds, but
  each one costs a Lambda invocation and counts against the shared API Gateway rate limit, so a flood could slow
  legitimate users. Mitigations: the budget alarm and API Gateway throttling. Function URL + OAC removes this risk.
- Anyone with `cloudfront:GetDistributionConfig`, `lambda:GetFunctionConfiguration` or read access to the state
  bucket can read the origin secret. That is acceptable: the secret only protects the origin; the data is protected
  by authentication.
- Upgrading Bref, the layers and the PHP platform pin is one PR, checked in CI.

## To verify in Phase 0 (Docker, no AWS)

- On `bref/php-85:3`: the `BREF_RUNTIME` values and handler meaning, RIE invocation and `batchItemFailures` behave
  as on `php-83:3`.
- The pinned tools (PHPUnit, PHPStan, PHP-CS-Fixer, opis/json-schema, aws-sdk-php) install and run on 8.5.
- Record the layer's exact PHP patch version in `config.platform.php`. Re-run `composer show --tree bref/bref` on
  8.5 and record the result.
- GD in the worker image: `gd_info()` reports JPEG, PNG and **WebP** support. If WebP is missing, remove it from the
  upload policy (ADR 0006).
- `PASSWORD_ARGON2ID` is defined in `bref/php-85:3` and in `php:8.5-fpm-alpine`.
- FPM mode on 8.5: `REMOTE_ADDR` is `127.0.0.1`, and a `CloudFront-Viewer-Address` header in an API Gateway v2 event
  reaches PHP as `HTTP_CLOUDFRONT_VIEWER_ADDRESS`.
- The telemetry switch name in Bref 3 (`BREF_PING_DISABLE`) and that it actually stops the ping.
- Where the `gd-php-85` layer version can be read from (a `layers.json` in `bref/extra-php-extensions`, or not).
- The Vite proxy can set a header from the client's socket address.

## To verify on first deployment (Phase 8)

- A direct call to the `execute-api` URL returns 403.
- A `CloudFront-Viewer-Address` sent by the client is replaced by CloudFront.
- The IPv6 format through CloudFront (IPv6 enabled on the distribution).
- The allow-list origin request policy works with API Gateway (`Host` not forwarded).
