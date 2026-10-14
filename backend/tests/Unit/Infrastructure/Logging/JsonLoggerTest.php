<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\TestCase;

final class JsonLoggerTest extends TestCase
{
    private const FIXED_TIME = '2026-10-09T12:00:00+00:00';

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    /**
     * @param list<string> $secrets
     */
    private function logger(array $secrets = []): JsonLogger
    {
        return new JsonLogger($this->stream, static fn(): \DateTimeImmutable => new \DateTimeImmutable(self::FIXED_TIME), $secrets);
    }

    private static function stringOf(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function firstEntry(): array
    {
        $entry = json_decode(explode("\n", $this->logged())[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);

        return $entry;
    }

    public function testWritesOneJsonObjectPerLineWithTimeLevelMessageAndContext(): void
    {
        $this->logger()->info('created bucket', ['bucket' => 'receipts']);

        self::assertStringEndsWith("\n", $this->logged());
        self::assertSame(1, substr_count($this->logged(), "\n"));
        self::assertSame(
            ['time' => self::FIXED_TIME, 'level' => 'info', 'message' => 'created bucket', 'bucket' => 'receipts'],
            $this->firstEntry(),
        );
    }

    public function testUsesTheSystemClockByDefault(): void
    {
        (new JsonLogger($this->stream))->info('now');

        self::assertNotFalse(\DateTimeImmutable::createFromFormat(DATE_ATOM, self::stringOf($this->firstEntry()['time'] ?? null)));
    }

    public function testWarningAndErrorUseTheirLevels(): void
    {
        $logger = $this->logger();

        $logger->warning('careful');
        $logger->error('boom');

        $levels = [];
        foreach (explode("\n", trim($this->logged())) as $line) {
            $entry = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($entry);
            $levels[] = $entry['level'];
        }
        self::assertSame(['warning', 'error'], $levels);
    }

    public function testContextCannotOverrideTheReservedFields(): void
    {
        $this->logger()->info('real message', ['level' => 'fake', 'message' => 'fake', 'time' => 'fake']);

        $entry = $this->firstEntry();
        self::assertSame('info', $entry['level']);
        self::assertSame('real message', $entry['message']);
        self::assertSame(self::FIXED_TIME, $entry['time']);
    }

    public function testKeepsUnicodeAndSlashesReadableAndSurvivesInvalidUtf8(): void
    {
        $this->logger()->info('ünï 🧾', ['key' => 'a/b', 'raw' => "bad\xB1byte"]);

        self::assertStringContainsString('ünï 🧾', $this->logged());
        self::assertStringContainsString('"key":"a/b"', $this->logged());
        self::assertNotFalse(json_decode($this->logged(), true), 'the line must still be valid JSON');
    }

    public function testRedactsSecretValuesFromMessageAndNestedContext(): void
    {
        $this->logger(['topsecret', 'tok/en"1'])->error('failed with topsecret', ['error' => 'url had tok/en"1 inside', 'nested' => ['deep' => ['topsecret']]]);

        self::assertStringNotContainsString('topsecret', $this->logged());
        self::assertStringNotContainsString('tok/en', $this->logged());
        self::assertStringContainsString('[REDACTED]', $this->logged());
        self::assertNotFalse(json_decode($this->logged(), true));
    }

    public function testRedactsAnAuthorizationHeaderByKeyAndInText(): void
    {
        $this->logger()->info('sent', [
            'headers' => ['Authorization' => 'Bearer abc123', 'Accept' => 'text/plain'],
            'raw' => "POST /\r\nAuthorization: Bearer ghi789\r\nHost: s3",
        ]);

        self::assertStringNotContainsString('abc123', $this->logged());
        self::assertStringNotContainsString('ghi789', $this->logged());
        self::assertStringContainsString('text/plain', $this->logged());
        self::assertStringContainsString('Host: s3', $this->logged());
    }

    public function testEmptySecretsAreIgnored(): void
    {
        $this->logger([''])->info('plain message');

        self::assertSame('plain message', $this->firstEntry()['message']);
    }

    public function testNonStringContextValuesAreKept(): void
    {
        $this->logger(['secret'])->info('counts', ['changes' => 3, 'ok' => true, 'nothing' => null]);

        $entry = $this->firstEntry();
        self::assertSame([3, true, null], [$entry['changes'], $entry['ok'], $entry['nothing']]);
    }

    /**
     * @return resource
     */
    private static function notAStream(): mixed
    {
        return 'php://stderr'; // @phpstan-ignore return.type
    }

    public function testRejectsAStreamThatIsNotAResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new JsonLogger(self::notAStream());
    }

    public function testALogWriteToAClosedStreamDoesNotBreakTheCaller(): void
    {
        $closed = fopen('php://memory', 'w+b');
        self::assertIsResource($closed);
        $logger = new JsonLogger($closed);
        fclose($closed);

        $logger->info('nobody listens');

        $this->addToAssertionCount(1);
    }

    public function testCapMessageCutsAtTheByteLimitWithoutSplittingACharacter(): void
    {
        $capped = JsonLogger::capMessage(str_repeat('a', 1023) . 'é');

        self::assertSame(str_repeat('a', 1023), $capped);
        self::assertSame('short', JsonLogger::capMessage('short'));
    }
}
