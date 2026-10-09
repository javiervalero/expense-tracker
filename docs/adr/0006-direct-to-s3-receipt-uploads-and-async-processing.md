# 0006. Direct-to-S3 receipt uploads and asynchronous processing

- Status: Accepted (2026-10-09)
- Date: 2026-10-07
- Revised: 2026-10-09 (PR #1 review comments #1, #4)

## Context

Users attach receipts (images or PDFs) and see a preview. The brief says receipt files can be large and should not
pass through the application server, and that processing such as thumbnail generation should not block the user.
API Gateway payloads max out at about 10 MB, which makes uploads through the API impractical anyway.

## Decision

### Storage

| Bucket | Contents | Key | Events |
|---|---|---|---|
| `receipts` | Originals uploaded by the browser | `receipts/<userId>/<expenseId>/<receiptId>` | `s3:ObjectCreated:*`, prefix `receipts/` → SQS |
| `receipt-thumbnails` | JPEG thumbnails written by the worker | `<userId>/<expenseId>/<receiptId>.jpg` | none |

Both buckets are private and encrypted, block all public access, and have versioning off, so deletes are real.
Only `receipts` allows CORS (POST from the app's origin). The thumbnail key can always be worked out from the
original's key. The worker's IAM role can read and delete in `receipts` and write and delete in
`receipt-thumbnails`, but it **cannot write to `receipts`**, so processing can never trigger itself.

### Flow

1. **Signed upload.** The API returns an S3 presigned POST policy. It is generated locally with no network call
   and fixes:
   - the exact key;
   - the maximum size (`content-length-range`, 10 MB);
   - the allowed content types (JPEG, PNG, WebP, PDF);
   - a 5-minute expiry.

   The API writes the receipt item as `pending`, with `expiresAt` = now + 24 h (DynamoDB TTL).
2. **Direct upload.** The browser uploads straight to S3.
3. **Event to queue.** S3 sends the `ObjectCreated` event to an SQS queue. The queue has a dead-letter queue
   (`maxReceiveCount` 3). The Lambda event source mapping reports per-message failures (`ReportBatchItemFailures`).
4. **Worker.** A PHP worker Lambda handles each message:
   1. Ignores `s3:TestEvent` messages, and logs and ignores keys that don't match the expected pattern. Keys arrive
      URL-encoded and are decoded first.
   2. Checks the real file type from its magic bytes, and image dimensions before decoding (rejects images over
      about 40 megapixels).
   3. Writes a thumbnail for images (GD).
   4. Updates the item to `ready` and removes `expiresAt`, with the condition
      `objectKey = :key AND status = pending`.
   5. Invalid file: deletes the object and marks the item `failed` with `expiresAt` = now + 7 days.
   6. If the condition fails, re-reads the item:
      - same key, already processed: a duplicate delivery, so do nothing;
      - item missing or holding a different key: the receipt was deleted, replaced or expired, so delete the
        original and the thumbnail.
5. **Preview.** The front-end polls the receipt status. Previews and downloads use short-lived presigned GET URLs.
   PDFs show a generic icon and open in the browser's PDF viewer.

### Lifecycle and cleanup

| Situation | What happens |
|---|---|
| Policy issued, nothing uploaded | No object exists. The `pending` item expires by TTL. Reads treat an expired `pending` item as "no receipt", so behaviour doesn't depend on when TTL runs. |
| Receipt deleted | The API deletes the item, then the original and thumbnail (`DeleteObjects`). |
| Expense deleted | The API deletes the expense and receipt items in one transaction, then the objects. |
| Receipt replaced | The API overwrites the item with the new `pending` one (`ReturnValues=ALL_OLD`), then deletes the old objects. The UI asks for confirmation. |
| Upload arrives after its item was deleted, replaced or expired | The worker's conditional update fails and it deletes the objects (step 4.6). |
| Processing fails repeatedly | The message goes to the DLQ and an alarm fires. After the fix, redriving is safe because the worker is idempotent. |
| S3 delete fails after the record was removed | SDK retries, then an ERROR log with the keys; a CloudWatch metric filter raises an alarm. |

The order is always **record first, then files**. Together with the worker's conditional update, this means no
interleaving of API and worker leaves objects behind. The `receipts` bucket also has a lifecycle rule that aborts
incomplete multipart uploads after 1 day (housekeeping only).

Locally, the same flow runs against an S3-compatible emulator and ElasticMQ, with the worker receiving the same SQS
event shape (see [ADR 0007](0007-local-development-with-docker-compose.md)).

## Alternatives considered

- **Uploading through the API.** Contradicts the brief and runs into API Gateway/Lambda payload limits.
- **Presigned PUT instead of POST.** Simpler, but it can't enforce a maximum file size.
- **Browser calls "upload complete" to trigger processing.** Relies on the client. The S3 event is the reliable signal.
- **S3 invoking the Lambda directly (no SQS).** Fewer parts, but no buffering and weaker retry/DLQ handling.
- **Thumbnails in the same bucket, separated by a prefix filter.** Works, but one wrong filter causes a processing
  loop, and IAM can't prevent it.
- **Thumbnails stored in DynamoDB items.** Items are limited to 400 KB, and the data would bloat the table.
- **Delete files first, then the record.** Leaves a race where a thumbnail written mid-delete is orphaned.
- **DynamoDB Streams → cleanup Lambda.** One central place for cleanup, but another function and another local
  trigger to emulate. Revisit if account deletion is added.
- **Tag-based S3 lifecycle for unconfirmed uploads.** Adds a tagging call on every upload, and emulator support for
  tagging through POST policies is unverified. Not needed given the worker's cleanup.
- **PDF thumbnails.** Needs Imagick + Ghostscript in Lambda. Deferred.

## Consequences

- The application server never handles file bytes.
- The user gets immediate feedback, and the thumbnail appears when ready.
- Cleanup is explicit and covered by tests, including duplicate delivery and the races listed above.
- More moving parts (two buckets, queue, worker, DLQ) need monitoring: alarms on DLQ depth and on
  receipt-cleanup errors. Both are part of the essential infrastructure, not deferred.
- Replacing a receipt removes the old one immediately, even if the new upload is then abandoned.
