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
 * Defensive redaction: the values of the secrets given to the constructor, and the value of any `Authorization`
 * header (a context key of that name, or `Authorization: ...` inside a text), never reach the log.
 *
 * A failed write is dropped on purpose: a broken log sink must not fail the work being logged.
 * (The local-only tooling in docker/local-aws has its own copy; they are separate Composer projects.)
 */
final readonly class JsonLogger
{
    /** Longest exception message written to a log line, in bytes. */
    private const MAX_MESSAGE_BYTES = 1024;
    private const REDACTED = '[REDACTED]';
    private const AUTHORIZATION_KEY = '/^authorization$/i';
    private const AUTHORIZATION_IN_TEXT = '/(authorization\s*[:=]\s*)[^\r\n",]*/i';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private \Closure $clock;

    /**
     * @param resource $stream an open, writable stream
     * @param (\Closure(): \DateTimeImmutable)|null $clock defaults to the system clock
     * @param list<string> $secrets values to hide wherever they appear (empty ones are ignored)
     */
    public function __construct(private mixed $stream, ?\Closure $clock = null, private array $secrets = [])
    {
        if (!\is_resource($stream)) {
            throw new \InvalidArgumentException('The log stream must be an open stream resource.');
        }
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
                \is_string($key) && preg_match(self::AUTHORIZATION_KEY, $key) === 1 => self::REDACTED,
                \is_string($value) => $this->redactText($value),
                \is_array($value) => $this->redactContext($value),
                default => $value,
            };
        }

        return $redacted;
    }

    private function redactText(string $text): string
    {
        return preg_replace(self::AUTHORIZATION_IN_TEXT, '$1' . self::REDACTED, $this->redactSecrets($text)) ?? self::REDACTED;
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
        return mb_strcut($this->redactText($message), 0, self::MAX_MESSAGE_BYTES, 'UTF-8');
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
