<?php

declare(strict_types=1);

namespace LocalAws\Relay;

use LocalAws\Logging\JsonLogger;

/**
 * The webhook of the S3 emulator, as a pure function of (method, headers, body) to a response. The only side
 * effect is the injected {@see MessageSender}, so every status code is unit-testable without HTTP.
 *
 * Contract (ADR 0007, WP9):
 * - only POST (405 with `Allow: POST` otherwise);
 * - `Authorization: Bearer <token>` must match the shared token, compared with hash_equals (401 otherwise);
 * - the body is at most $maxBodyBytes (413): a `Content-Length` over the cap is refused before the body is read at
 *   all; the body reader is also capped, so a request without `Content-Length` (chunked) is accepted up to the cap
 *   and no further. It is a JSON object with a non-empty `Records` list of objects (400);
 * - the queue gets exactly `{"Records":[...]}`: the emulator's wrapper fields (`EventName`, `Key`) are dropped and
 *   the records are not touched. In particular the object key stays percent-encoded (the worker decodes it, as it
 *   does on AWS; decoding here too would decode twice) and events are never deduplicated (an overwrite is a new
 *   event on AWS as well);
 * - 204 on success, 502 when the queue refuses (the emulator then retries from its own `queue_dir`).
 *
 * Forwarding is semantically faithful, not byte-identical: the records are decoded and encoded again, so
 * insignificant whitespace and the escaping of non-ASCII text can differ, while `{}` stays `{}`, `[]` stays `[]` and
 * `1.0` stays `1.0` (JSON_PRESERVE_ZERO_FRACTION). Two known limits, neither present in S3 events: integers beyond
 * PHP_INT_MAX become floats, and lone UTF-16 surrogates (`\ud800`) are rejected with 400.
 *
 * Logs one JSON line per request: counts and object keys, never the token, the Authorization header or the body.
 */
final readonly class RelayHandler
{
    private const JSON_DEPTH = 512;
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    private const BEARER = '/^Bearer ([^\s]+)\z/i';
    private const MAX_LOGGED_METHOD_LENGTH = 16;

    public function __construct(
        private MessageSender $sender,
        private JsonLogger $logger,
        private string $authToken,
        private int $maxBodyBytes,
    ) {}

    /**
     * @param array<string, string> $headers names in any case
     * @param string|\Closure(): string $body the body, or a reader called only after the cheap checks (method, token,
     *                                         declared length) have passed
     */
    public function handle(string $method, array $headers, string|\Closure $body): RelayResponse
    {
        if ($method !== 'POST') {
            return $this->refuse(405, 'method not allowed', ['method' => substr($method, 0, self::MAX_LOGGED_METHOD_LENGTH)], ['Allow' => 'POST']);
        }
        if (!$this->isAuthorized($headers)) {
            return $this->refuse(401, 'not authorized', [], ['WWW-Authenticate' => 'Bearer']);
        }
        $declaredLength = self::declaredLength($headers);
        if ($declaredLength !== null && $declaredLength > $this->maxBodyBytes) {
            return $this->refuse(413, 'body too large', ['limitBytes' => $this->maxBodyBytes, 'declaredBytes' => $declaredLength]);
        }
        $body = $body instanceof \Closure ? $body() : $body;
        if (strlen($body) > $this->maxBodyBytes) {
            return $this->refuse(413, 'body too large', ['limitBytes' => $this->maxBodyBytes]);
        }
        $records = self::recordsOf($body);
        if ($records === null) {
            return $this->refuse(400, 'body is not a JSON object with a non-empty Records list');
        }

        return $this->forward($records);
    }

    /**
     * @param non-empty-list<\stdClass> $records
     */
    private function forward(array $records): RelayResponse
    {
        $context = ['records' => count($records), 'keys' => self::keysOf($records)];
        try {
            $this->sender->send(json_encode(['Records' => $records], self::ENCODE_FLAGS));
        } catch (MessageSendFailed $failure) {
            $this->logger->error('sending to the queue failed, the emulator will retry', $context + ['status' => 502, 'error' => $failure->getMessage()]);

            return new RelayResponse(502);
        }
        $this->logger->info('event forwarded to the queue', $context + ['status' => 204]);

        return new RelayResponse(204);
    }

    /**
     * @param array<string, string> $headers
     */
    private function isAuthorized(array $headers): bool
    {
        $authorization = self::header($headers, 'Authorization') ?? '';
        if (preg_match(self::BEARER, $authorization, $matches) !== 1) {
            return false;
        }

        // Hashing first makes both sides the same length, so the comparison also hides the token's length.
        return hash_equals(hash('sha256', $this->authToken), hash('sha256', $matches[1]));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function header(array $headers, string $wanted): ?string
    {
        $found = null;
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $wanted) === 0) {
                $found = $value;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return int|null null when absent or not a plain run of digits (then only the read cap applies)
     */
    private static function declaredLength(array $headers): ?int
    {
        $value = self::header($headers, 'Content-Length');
        if ($value === null || preg_match('/^\d{1,15}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return non-empty-list<\stdClass>|null null when the body is not an event with records
     */
    private static function recordsOf(string $body): ?array
    {
        try {
            $event = json_decode($body, false, self::JSON_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $records = $event instanceof \stdClass ? ($event->Records ?? null) : null;
        if (!is_array($records) || $records === [] || !array_is_list($records)) {
            return null;
        }
        foreach ($records as $record) {
            if (!$record instanceof \stdClass) {
                return null;
            }
        }

        return $records;
    }

    /**
     * The (still encoded) object keys, for the log.
     *
     * @param list<\stdClass> $records
     *
     * @return list<string>
     */
    private static function keysOf(array $records): array
    {
        $keys = [];
        foreach ($records as $record) {
            $s3 = $record->s3 ?? null;
            $object = $s3 instanceof \stdClass ? ($s3->object ?? null) : null;
            $key = $object instanceof \stdClass ? ($object->key ?? null) : null;
            if (is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $headers
     */
    private function refuse(int $status, string $reason, array $context = [], array $headers = []): RelayResponse
    {
        $this->logger->warning('request refused: ' . $reason, ['status' => $status] + $context);

        return new RelayResponse($status, '', $headers);
    }
}
