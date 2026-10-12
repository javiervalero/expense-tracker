# contract/

The OpenAPI contract of the API (ADR 0011). `openapi.yaml` is the source of truth.

- `make contract-install` once (and after a lockfile change), then `make contract`: lint, bundle, and check that the
  committed `frontend/src/api/schema.gen.ts` equals what the spec generates (it fails with a diff otherwise).
- `make contract-gen` rewrites `frontend/src/api/schema.gen.ts`; commit the result.

Response headers are documented in the spec for readers, but the generated types for them are not meant to be used:
the header schemas only carry an `example`, so the types are plain `string`. The front-end code (WP14) must not rely
on generated header types.
