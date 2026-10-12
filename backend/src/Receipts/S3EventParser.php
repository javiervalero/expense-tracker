<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * Reads the objects out of an S3 event notification (the body of an SQS message), in the AWS shape and in the one
 * the local S3 emulator produces (ADR 0006, ADR 0007).
 *
 * Deliberately never reads `eventSource` or the prefix of `eventName`: the emulator says `minio:s3` and
 * `s3:ObjectCreated:Post`, AWS says `aws:s3` and `ObjectCreated:Post`. The bucket rule (prefix filter on the
 * `receipts/` keys) already decides what is sent here.
 *
 * Keys arrive URL-encoded (AWS: form encoding, so `+` is a space; the emulator also encodes `/` as `%2F`).
 * `urldecode` turns both into the real key.
 */
final class S3EventParser
{
    private const TEST_EVENT = 's3:TestEvent';

    /**
     * @return list<S3ObjectRef> empty for the `s3:TestEvent` message that S3 sends when a notification is set up
     *
     * @throws InvalidS3Event
     */
    public function parse(string $body): array
    {
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidS3Event('The message body is not valid JSON.');
        }
        if (!\is_array($event) || ($event !== [] && array_is_list($event))) {
            throw new InvalidS3Event(\sprintf(
                'The message body is not an S3 event notification (decoded to %s).',
                \is_array($event) ? 'list' : get_debug_type($event),
            ));
        }

        if (!\array_key_exists('Records', $event)) {
            if (($event['Event'] ?? null) === self::TEST_EVENT) {
                return [];
            }
            throw new InvalidS3Event('The message body has no "Records": it is not an S3 event notification.');
        }

        $records = $event['Records'];
        if (!\is_array($records) || $records === [] || !array_is_list($records)) {
            throw new InvalidS3Event('"Records" is not a non-empty list.');
        }

        return array_map($this->objectOf(...), $records);
    }

    private function objectOf(mixed $record): S3ObjectRef
    {
        $s3 = \is_array($record) ? ($record['s3'] ?? null) : null;
        $bucket = \is_array($s3) && \is_array($s3['bucket'] ?? null) ? ($s3['bucket']['name'] ?? null) : null;
        $key = \is_array($s3) && \is_array($s3['object'] ?? null) ? ($s3['object']['key'] ?? null) : null;

        if (!\is_string($bucket) || $bucket === '') {
            throw new InvalidS3Event('A record has no s3.bucket.name.');
        }
        if (!\is_string($key) || $key === '') {
            throw new InvalidS3Event('A record has no s3.object.key.');
        }

        return new S3ObjectRef($bucket, urldecode($key));
    }
}
