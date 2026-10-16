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
 * of a context key named (any case, any depth) `authorization`, `cookie`, `set-cookie`, `x-origin-verify`,
 * `x-csrf-token`, `password` or `passwordHash`. Inside a text, `Authorization: ...`, `Cookie: ...` and
 * `Set-Cookie: ...` lines and `__Host-session=<value>` are redacted too.
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
    private const SENSITIVE_KEY = '/^(?:authorization|cookie|set-cookie|x-origin-verify|x-csrf-token|password|passwordhash)$/i';
    private const AUTHORIZATION_IN_TEXT = '/(authorization\s*[:=]\s*)[^\r\n",]*/i';
    /** A whole `Cookie:` or `Set-Cookie:` line: the value holds `;` and `,`, so only the line end stops it. */
    private const COOKIE_LINE_IN_TEXT = '/((?<![A-Za-z0-9_-])(?:set-)?cookie\s*:\s*)[^\r\n"]*/i';
    private const SESSION_COOKIE_IN_TEXT = '/(__Host-session\s*=\s*)[^\s;,"\']+/i';
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
    private function redactContext(array $values): array
    {
        $redacted = [];
        foreach ($values as $key => $value) {
            $redacted[$key] = match (true) {
                \is_string($key) && preg_match(self::SENSITIVE_KEY, $key) === 1 => self::REDACTED,
                \is_string($value) => $this->redactText($value),
                \is_array($value) => $this->redactContext($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function redactText(string $text): string
    {
        $redacted = $this->redactSecrets($text);
        foreach ([self::COOKIE_LINE_IN_TEXT, self::SESSION_COOKIE_IN_TEXT, self::AUTHORIZATION_IN_TEXT] as $pattern) {
            $redacted = preg_replace($pattern, '$1' . self::REDACTED, $redacted) ?? self::REDACTED;
        }

        return $redacted;
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
