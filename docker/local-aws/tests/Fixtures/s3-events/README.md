# S3 event fixtures

Sample S3 event notifications, used by the relay tests (and, from WP10, by the worker's S3 event parser tests).

| File | Source |
|---|---|
| `silo-object-created-post.json` | Captured from the `pgsty/silo` emulator in the Phase 0 spike (copied from `docs/spikes/fixtures/`). Note the emulator's extra top-level `EventName` and `Key`, the percent-encoded key including `/`, the empty `awsRegion` and `eventVersion` 2.0. |
| `aws-object-created-post.json` | Transcribed from the "Example message when an object is created using a PUT request" in the AWS documentation, [Event message structure](https://docs.aws.amazon.com/AmazonS3/latest/userguide/notification-content-structure.html) (checked 2026-10-09). The only change: `eventName` is `ObjectCreated:Post` instead of `ObjectCreated:Put`, to match what a presigned POST produces. |
| `aws-s3-test-event.json` | Transcribed unchanged from "Amazon S3 test message" on the same page. It has no `Records`: S3 sends it once when a notification is configured. |
