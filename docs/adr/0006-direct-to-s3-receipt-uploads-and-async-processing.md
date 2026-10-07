# 0006. Direct-to-S3 receipt uploads and asynchronous processing

- Status: Proposed
- Date: 2026-10-07

## Context

Users attach receipts (images or PDFs) and see a preview. The brief says receipt files can be large and should not
pass through the application server, and that processing such as thumbnail generation should not block the user.
API Gateway and Lambda also limit request size (API Gateway payloads max out at about 10 MB), which makes uploads
through the API impractical anyway.

## Decision

1. **Signed upload.** The API returns an **S3 presigned POST policy**. It is generated locally with the AWS SDK, with
   no network call, and fixes:
   - the exact object key, `receipts/<userId>/<uuid>`;
   - the maximum size (`content-length-range`, 10 MB);
   - the allowed content types (JPEG, PNG, WebP, PDF);
   - a short expiry.

   The receipt is recorded as `pending`.
2. **Direct upload.** The browser uploads straight to S3.
3. **Event to queue.** S3 sends `ObjectCreated` events to an **SQS queue** with a dead-letter queue.
4. **Worker.** A **worker Lambda** (PHP) consumes the queue:
   - checks the real file type from its magic bytes;
   - makes a thumbnail for images with GD;
   - marks the receipt `ready`, or `failed` and deletes the object if it is invalid.
5. **Preview.** The front-end polls the receipt status. Previews and downloads use **short-lived presigned GET URLs**.
   PDFs show a generic icon and open in the browser's PDF viewer.

The receipts bucket is private, encrypted, blocks all public access, and only allows CORS from the app's origin.

Locally, MinIO sends the same S3-format events by webhook to the worker container, which runs the same PHP handler.

## Alternatives considered

- **Uploading through the API.** Contradicts the brief and runs into API Gateway/Lambda payload limits.
- **Presigned PUT instead of POST.** Simpler, but it can't enforce a maximum file size.
- **Having the browser call "upload complete" to trigger processing.** Relies on the client. The S3 event is the
  reliable signal.
- **S3 invoking the Lambda directly (no SQS).** Fewer parts, but no buffering and weaker retry/dead-letter handling.
- **PDF thumbnails.** Needs Imagick + Ghostscript in Lambda. That is heavy for a proof of concept, so it's deferred.

## Consequences

- The application server never handles file bytes; large files cost nothing extra in Lambda.
- The user gets immediate feedback, and the thumbnail appears when ready.
- More moving parts (queue, worker, DLQ) need monitoring. A CloudWatch alarm on the DLQ depth covers this.
- An orphaned upload (policy issued, no file uploaded) leaves a `pending` record. A TTL or lifecycle rule cleans it
  up.
