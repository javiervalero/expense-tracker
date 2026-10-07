# 0003. Vanilla PHP back-end structure

- Status: Proposed
- Date: 2026-10-07

## Context

The back-end must be vanilla PHP with no framework (a standing requirement). It still needs what a framework would
normally provide: routing, request and response handling, error handling, validation, authentication and testing.
That has to stay small, readable and secure.

## Decision

- **PHP 8.3** with `declare(strict_types=1)` everywhere.
- **Composer**, used only for PSR-4 autoloading and a few libraries. Composer is a package manager, not a framework.
  - Runtime: `aws/aws-sdk-php`.
  - Development: PHPUnit, PHPStan (strict level), PHP-CS-Fixer (PER coding style).
- **One entry point**, `public/index.php`. It builds a small hand-written `Request`, runs it through a short
  middleware chain, and dispatches it with a hand-written router:
  - error handling;
  - request logging as JSON lines to stdout, which ends up in CloudWatch on AWS;
  - session authentication;
  - CSRF checks.
- **Errors** are returned as RFC 9457 problem details (`application/problem+json`), including field-level validation
  errors.
- **Code organised by feature:** `Auth`, `Categories`, `Expenses`, `Receipts`, `Reports`. Shared plumbing lives in
  `Http` and `Infrastructure` (DynamoDB, S3, clock, config).
- **Dependencies are wired by hand** in one place (a small composition root); there is no container library.
- **Configuration comes from environment variables only.**

## Alternatives considered

- **A micro-framework (Slim, Lumen, Mezzio) or Symfony components.** Excluded by the "no framework" requirement.
- **A single procedural script.** Simplest to start, but hard to test and secure as features grow.

## Consequences

- Every moving part is visible and small enough to read, which helps the owner learn how the pieces work.
- We maintain our own router, validation and error handling. Keeping them minimal and well tested is essential.
- The same code runs under php-fpm locally and under Bref's FPM layer on Lambda.
