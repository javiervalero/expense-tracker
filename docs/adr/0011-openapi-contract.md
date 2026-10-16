# 0011. OpenAPI 3.1 contract as the source of truth for the API

- Status: Accepted (2026-10-09)
- Date: 2026-10-09 (PR #1 review comment #7)

## Context

A clear separation between front-end and back-end is a core requirement. The Vue front-end and the PHP back-end are
developed side by side, and the API will change as features are added. A table in the plan does not stop the two
sides drifting apart. The back-end must stay vanilla PHP: dev-only tools are acceptable, runtime libraries are not.

## Decision

- `contract/openapi.yaml` (OpenAPI 3.1) describes every `/api/v1` endpoint: request bodies, parameters, every
  status code, response bodies, and the shared RFC 9457 `ProblemDetails` schema. Response schemas use
  `additionalProperties: false`, so undocumented fields fail.
- **Contract first:** a change to the API changes the spec in the same pull request.
- CI enforces the contract on every PR:
  - **Lint and bundle:** `@redocly/cli lint` and `bundle` (output `contract/dist/openapi.json`, git-ignored), so PHP
    needs no YAML parser.
  - **Front-end:** `openapi-typescript` generates `frontend/src/api/schema.gen.ts` (committed). CI regenerates it
    and fails on any diff. API calls are typed from it, so `vue-tsc` catches drift.
  - **Back-end:** every PHPUnit integration request goes through `ApiTestCase::request()`, which validates status,
    content type and body against the bundled spec using `opis/json-schema` (**`require-dev` only**, never deployed).
    OpenAPI 3.1 schemas are JSON Schema 2020-12, which it supports. A small helper (about 100 lines) finds the schema
    for a route, status and content type. Every integration test is therefore also a contract test.
  - **Route parity:** a test asserts that the router's routes equal the spec's paths and methods.
- The API stays at `/api/v1`. Additive changes are allowed. Breaking changes need a reviewed decision.
- To verify in Phase 1: `opis/json-schema` resolves the bundled document's `$ref`s cleanly. If not, use the fallback
  below.

## Alternatives considered

- **`league/openapi-psr7-validator`.** Validates requests and responses more completely, but needs PSR-7 objects
  (`nyholm/psr7`) and its OpenAPI 3.1 support is uncertain. It is the fallback if the `opis` helper proves awkward.
- **Generating the spec from PHP annotations (e.g. swagger-php).** Couples the contract to the PHP code and adds
  annotations everywhere. Rejected.
- **Hand-written TypeScript types only.** Nothing checks them against the server. Rejected.

## Consequences

- One readable file documents the API for the owner and for both code bases.
- Drift fails CI on whichever side diverges.
- About 100 lines of test-helper code to maintain, plus two front-end dev tools.
- Later option: an `oasdiff` breaking-change check against `main`.

## Verified in Phase 1 (2026-10-13, PR #4)

**PASS: `opis/json-schema` 2.6 resolves the bundled document's `$ref`s.** The fallback validator is not needed
(decision point D1 did not fire; gate G2 was not used).

- The helper registers the whole bundled `contract/dist/openapi.json` once under a fixed `$id` and validates each
  body against a JSON-pointer `$ref` into it, so `responses → schemas` chains resolve like any JSON Schema reference.
  OpenAPI annotation keywords (`example`, `format: uri-reference`) do not cause errors.
- Built test-first in `backend/tests/Support/Contract/` (`OpenApiDocument`, `ContractValidator`,
  `ContractViolation`), proven on a small fixture spec before anything used it, then on the real bundle.
- `opis/json-schema ~2.6.0` is in `require-dev` only, as decided.
- In place: every integration request goes through `ApiTestCase::request()`; route parity is a test; another test
  requires every operation to document its `403` and `500` problem responses. These run with `make test-contract` and
  `make test-integration`, inside the existing CI `integration` job.
