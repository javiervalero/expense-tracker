<?php

declare(strict_types=1);

namespace LocalAws\Logging;

/**
 * Writes one JSON object per line (time, level, message, then the context fields). Container logs stay easy to
 * read with `docker compose logs` and easy to filter with `jq`.
 *
 * The reserved fields always win over the context, so a caller cannot forge the level or the timestamp.
 *
 * Defensive redaction: the values of the secrets given to the constructor, and the value of any `Authorization`
 * header (a context key of that name, or `Authorization: ...` inside a text), never reach the log.
 */
final readonly class JsonLogger
{
    private const REDACTED = '[REDACTED]';
    private const AUTHORIZATION_KEY = '/^authorization$/i';
    private const AUTHORIZATION_IN_TEXT = '/(authorization\s*[:=]\s*)[^\r\n",]*/i';
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private \Closure $clock;

    /**
     * @param \Closure(string): void $write receives one complete line, newline included
     * @param (\Closure(): \DateTimeImmutable)|null $clock defaults to the system clock
     * @param list<string> $secrets values to hide wherever they appear (empty ones are ignored)
     */
    public function __construct(private \Closure $write, ?\Closure $clock = null, private array $secrets = [])
    {
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

        ($this->write)(json_encode($entry, self::JSON_FLAGS) . "\n");
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
                is_string($key) && preg_match(self::AUTHORIZATION_KEY, $key) === 1 => self::REDACTED,
                is_string($value) => $this->redactText($value),
                is_array($value) => $this->redactContext($value),
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
