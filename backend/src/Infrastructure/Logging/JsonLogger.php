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
        @fwrite($this->stream, json_encode($entry, self::JSON_FLAGS) . "\n");
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
        foreach ($this->secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, self::REDACTED, $text);
            }
        }

        return preg_replace(self::AUTHORIZATION_IN_TEXT, '$1' . self::REDACTED, $text) ?? self::REDACTED;
    }
}
