<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Logging;

use LocalAws\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class JsonLoggerTest extends TestCase
{
    /**
     * @param list<string> $lines filled with every line the logger writes
     */
    private static function loggerWritingTo(array &$lines): JsonLogger
    {
        return new JsonLogger(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-09T12:00:00+00:00'),
        );
    }

    public function testWritesOneJsonObjectPerLineWithTimeLevelMessageAndContext(): void
    {
        $lines = [];
        $logger = self::loggerWritingTo($lines);

        $logger->info('created bucket', ['bucket' => 'receipts']);

        self::assertCount(1, $lines);
        self::assertStringEndsWith("\n", $lines[0]);
        self::assertSame(
            ['time' => '2026-10-09T12:00:00+00:00', 'level' => 'info', 'message' => 'created bucket', 'bucket' => 'receipts'],
            json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function testErrorUsesTheErrorLevel(): void
    {
        $lines = [];
        $logger = self::loggerWritingTo($lines);

        $logger->error('boom');

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('error', $decoded['level']);
    }

    public function testContextCannotOverrideTheReservedFields(): void
    {
        $lines = [];
        $logger = self::loggerWritingTo($lines);

        $logger->info('real message', ['level' => 'fake', 'message' => 'fake', 'time' => 'fake']);

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('info', $decoded['level']);
        self::assertSame('real message', $decoded['message']);
        self::assertSame('2026-10-09T12:00:00+00:00', $decoded['time']);
    }

    public function testKeepsUnicodeReadableAndSurvivesInvalidUtf8(): void
    {
        $lines = [];
        $logger = self::loggerWritingTo($lines);

        $logger->info('ünï 🧾', ['raw' => "bad\xB1byte"]);

        self::assertCount(1, $lines);
        self::assertStringContainsString('ünï 🧾', $lines[0]);
        self::assertNotFalse(json_decode($lines[0], true), 'the line must still be valid JSON');
    }

    /**
     * @param list<string> $lines
     * @param list<string> $secrets
     */
    private static function redactingLogger(array &$lines, array $secrets): JsonLogger
    {
        return new JsonLogger(
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
            static fn(): \DateTimeImmutable => new \DateTimeImmutable('2026-10-09T12:00:00+00:00'),
            $secrets,
        );
    }

    public function testRedactsSecretValuesFromMessageAndContext(): void
    {
        $lines = [];
        $logger = self::redactingLogger($lines, ['localdevsecret', 'tok/en"1']);

        $logger->error('failed with localdevsecret', ['error' => 'url had tok/en"1 inside', 'nested' => ['deep' => ['localdevsecret']]]);

        self::assertStringNotContainsString('localdevsecret', $lines[0]);
        self::assertStringNotContainsString('tok/en', $lines[0]);
        self::assertStringContainsString('[REDACTED]', $lines[0]);
        self::assertNotFalse(json_decode($lines[0], true), 'the line must still be valid JSON');
    }

    public function testRedactsAnAuthorizationHeaderValueInTextAndByKey(): void
    {
        $lines = [];
        $logger = self::redactingLogger($lines, []);

        $logger->info('sent', [
            'headers' => ['Authorization' => 'Bearer abc123', 'authorization' => ['Bearer def456'], 'Accept' => 'text/plain'],
            'raw' => "POST /\r\nAuthorization: Bearer ghi789\r\nHost: s3",
        ]);

        self::assertStringNotContainsString('abc123', $lines[0]);
        self::assertStringNotContainsString('def456', $lines[0]);
        self::assertStringNotContainsString('ghi789', $lines[0]);
        self::assertStringContainsString('text/plain', $lines[0]);
        self::assertStringContainsString('Host: s3', $lines[0], 'only the header value is redacted, not the rest of the text');
    }

    public function testEmptySecretsAreIgnoredSoNothingIsMangled(): void
    {
        $lines = [];
        $logger = self::redactingLogger($lines, ['']);

        $logger->info('plain message');

        self::assertStringContainsString('plain message', $lines[0]);
        self::assertStringNotContainsString('[REDACTED]', $lines[0]);
    }

    public function testNonStringContextValuesAreKept(): void
    {
        $lines = [];
        $logger = self::redactingLogger($lines, ['secret']);

        $logger->info('counts', ['changes' => 3, 'ok' => true, 'nothing' => null]);

        $decoded = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame([3, true, null], [$decoded['changes'], $decoded['ok'], $decoded['nothing']]);
    }
}
