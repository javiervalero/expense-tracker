<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

/**
 * Writes one JSON object per line (time, level, message, then the context fields) to a stream. On Lambda and in
 * the local containers that is stderr, which ends up in CloudWatch or `docker compose logs`, and stays easy to
 * filter with `jq`.
 *
 * The reserved fields always win over the context, so a caller cannot forge the level or the timestamp.
 *
 * Defensive redaction: the values of the secrets given to the constructor never reach the log, nor does the value
 * of a context key named (any depth) `authorization`, `cookie`, `set-cookie`, `x-origin-verify`,
 * `x-csrf-token`, `password`, `current-password`, `new-password` or `passwordHash`. A key is compared in lower
 * case, with `_` as `-` and without a leading `http-`, so `HTTP_COOKIE` and `newPassword` match too. Inside a
 * text (values and string keys), `Authorization: ...`, `Cookie: ...` and `Set-Cookie: ...` lines (to the end of
 * the line), `__Host-session=<value>` (also URL-encoded) and Argon2 hashes are redacted too.
 *
 * Objects in the context: a `JsonSerializable` is logged as its `jsonSerialize()` result, redacted like any other
 * value; every other object is logged as its class name only (its properties never reach the log).
 *
 * A failed write is dropped on purpose: a broken log sink must not fail the work being logged.
 * (The local-only tooling in docker/local-aws has its own copy; they are separate Composer projects.)
 */
final readonly class JsonLogger
{
    /** Longest exception message written to a log line, in bytes. */
    private const MAX_MESSAGE_BYTES = 1024;
    private const REDACTED = '[REDACTED]';
    private const UNSAFE_LINE = '[REDACTED: line could not be redacted safely]';
    /** Matched against a normalised key (see {@see self::isSensitiveKey()}): lower case, `-` for `_`, no `http-`. */
    private const SENSITIVE_KEY = '/\A(?:authorization|cookie|set-cookie|x-origin-verify|x-csrf-token|(?:current-?|new-?)?password|passwordhash)\z/';
    private const AUTHORIZATION_IN_TEXT = '/(authorization\s*[:=]\s*)[^\r\n",]*/i';
    /**
     * A whole `Cookie:` or `Set-Cookie:` line (also as a server variable, `HTTP_COOKIE:`): the value holds `;`, `,`
     * and quotes, so only the line end stops it.
     */
    private const COOKIE_LINE_IN_TEXT = '/((?<![A-Za-z0-9_-])(?:http[_-])?(?:set[_-])?cookie\s*:\s*)[^\r\n]*/i';
    /** `__Host-session=<token>`, also URL-encoded (`%3D`). */
    private const SESSION_COOKIE_IN_TEXT = '/(__Host-session\s*(?:=|%3D)\s*)[^\s;,"\'&]+/i';
    private const ARGON2_HASH_IN_TEXT = '/\$argon2(?:id|i|d)\$[^\s"]+/i';
    /** Deepest chain of `JsonSerializable` objects followed, so an object returning itself cannot loop. */
    private const MAX_OBJECT_DEPTH = 8;
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private \Closure $clock;

    /** @var list<string> the secrets, longest first, so a secret inside another one cannot leave the rest of it */
    private array $secrets;

    /**
     * @param resource $stream an open, writable stream
     * @param (\Closure(): \DateTimeImmutable)|null $clock defaults to the system clock
     * @param list<string> $secrets values to hide wherever they appear (empty ones are ignored)
     */
    public function __construct(private mixed $stream, ?\Closure $clock = null, array $secrets = [])
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('The log stream must be an open stream resource.');
        }
        usort($secrets, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $this->secrets = $secrets;
        $this->clock = $clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $message, array $context): void
    {
        $entry = [
            'time' => ($this->clock)()->format(DATE_ATOM),
            'level' => $level,
            'message' => $this->redactText($message),
        ] + $this->redactContext($context);

        if (!\is_resource($this->stream)) {
            return;
        }
        // Last line of defence: whatever the walk above missed (object properties, array keys, text that was JSON
        // encoded before it got here) is still a secret, or its JSON-escaped form, in the final line.
        $line = $this->redactSecrets((string) json_encode($entry, self::JSON_FLAGS));
        if (!json_validate($line)) {
            // A match that started inside a JSON escape (`\n` and a secret starting with n) broke the line.
            $line = (string) json_encode(
                ['time' => $entry['time'], 'level' => $level, 'message' => self::UNSAFE_LINE],
                self::JSON_FLAGS,
            );
        }
        @fwrite($this->stream, $line . "\n");
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function redactContext(array $values, int $depth = 0): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key)) {
                $redacted[$key] = $this->redactValue($value, $depth);
                continue;
            }
            $redacted[$this->redactText($key)] = $this->isSensitiveKey($key) ? self::REDACTED : $this->redactValue($value, $depth);
        }

        return $redacted;
    }

    private function redactValue(mixed $value, int $depth): mixed
    {
        return match (true) {
            \is_string($value) => $this->redactText($value),
            \is_array($value) => $this->redactContext($value, $depth),
            $value instanceof \JsonSerializable => $this->redactSerializable($value, $depth),
            \is_object($value) => ['class' => $value::class],
            default => $value,
        };
    }

    /**
     * What a `JsonSerializable` says about itself is what would be logged, so it gets the same redaction.
     */
    private function redactSerializable(\JsonSerializable $value, int $depth): mixed
    {
        if ($depth >= self::MAX_OBJECT_DEPTH) {
            return ['class' => $value::class];
        }
        try {
            $serialized = $value->jsonSerialize();
        } catch (\Throwable) {
            return ['class' => $value::class];
        }

        return $this->redactValue($serialized, $depth + 1);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalised = str_replace('_', '-', strtolower(trim($key)));
        $normalised = str_starts_with($normalised, 'http-') ? substr($normalised, \strlen('http-')) : $normalised;

        return preg_match(self::SENSITIVE_KEY, $normalised) === 1;
    }

    private function redactText(string $text): string
    {
        $redacted = $this->redactSecrets($text);
        foreach ([self::COOKIE_LINE_IN_TEXT, self::SESSION_COOKIE_IN_TEXT, self::AUTHORIZATION_IN_TEXT] as $pattern) {
            $redacted = preg_replace($pattern, '$1' . self::REDACTED, $redacted) ?? self::REDACTED;
        }

        return preg_replace(self::ARGON2_HASH_IN_TEXT, self::REDACTED, $redacted) ?? self::REDACTED;
    }

    /**
     * Replaces each secret, and its JSON-escaped form, by the placeholder.
     */
    private function redactSecrets(string $text): string
    {
        foreach ($this->secrets as $secret) {
            if ($secret === '') {
                continue;
            }
            $escaped = substr((string) json_encode($secret, self::JSON_FLAGS), 1, -1);
            $text = str_replace([$secret, $escaped], self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * Caps an exception message for a log field, never cutting a UTF-8 character in half. The secrets are hidden
     * BEFORE the cut, so a cut can never leave the first characters of one.
     */
    public function capMessage(string $message): string
    {
        return $this->capValue($message, self::MAX_MESSAGE_BYTES);
    }

    /**
     * Like {@see self::capMessage()} with a cap of your own: redact first, then cut to at most $bytes bytes.
     */
    public function capValue(string $value, int $bytes): string
    {
        return mb_strcut($this->redactText($value), 0, $bytes, 'UTF-8');
    }

    /**
     * A write-only stream on stderr, the log destination of every process of the project.
     *
     * @return resource
     */
    public static function stderrStream(): mixed
    {
        $stream = fopen('php://stderr', 'wb');
        // @codeCoverageIgnoreStart
        // Unreachable in tests: php://stderr cannot be made to fail to open.
        if ($stream === false) {
            throw new \RuntimeException('Cannot open php://stderr for logging.');
        }
        // @codeCoverageIgnoreEnd

        return $stream;
    }
}
