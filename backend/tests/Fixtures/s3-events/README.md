# S3 event fixtures (worker parser tests)

Copies of `docker/local-aws/tests/Fixtures/s3-events/*.json` (the backend cannot depend on `docker/local-aws`, a separate
Composer project). Provenance of each file is described in that directory's README. If a fixture changes there,
copy it here too; `S3EventParserTest` proves the AWS and the emulator shapes yield the same object reference.
