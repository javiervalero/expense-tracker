<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Logging;

use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertIsArray(json_decode($this->logged(), true, flags: JSON_THROW_ON_ERROR), 'the line must still be valid JSON');
    }

    public function testRedactsSecretValuesFromMessageAndNestedContext(): void
    {
        $this->logger(['topsecret', 'tok/en"1'])->error('failed with topsecret', ['error' => 'url had tok/en"1 inside', 'nested' => ['deep' => ['topsecret']]]);

        self::assertStringNotContainsString('topsecret', $this->logged());
        self::assertStringNotContainsString('tok/en', $this->logged());
        self::assertStringContainsString('[REDACTED]', $this->logged());
        self::assertIsArray(json_decode($this->logged(), true, flags: JSON_THROW_ON_ERROR));
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function cookieAndPasswordKeys(): iterable
    {
        foreach (['cookie', 'Cookie', 'set-cookie', 'Set-Cookie', 'x-origin-verify', 'X-Origin-Verify', 'x-csrf-token', 'X-CSRF-Token', 'password', 'Password', 'passwordHash', 'PASSWORDHASH'] as $key) {
            yield $key => [$key];
        }
    }

    #[DataProvider('cookieAndPasswordKeys')]
    public function testRedactsTheValueOfEachSensitiveKeyAtAnyDepth(string $key): void
    {
        $this->logger()->info('seen', [
            $key => 'top-level-value',
            'request' => ['headers' => [$key => 'nested-value'], 'list' => [[$key => 'deep-value']]],
        ]);

        $logged = $this->logged();
        self::assertStringNotContainsString('top-level-value', $logged);
        self::assertStringNotContainsString('nested-value', $logged);
        self::assertStringNotContainsString('deep-value', $logged);
        self::assertStringContainsString('[REDACTED]', $logged);
    }

    public function testRedactsASensitiveKeyWhoseValueIsAnArrayOrNumber(): void
    {
        $this->logger()->info('seen', ['password' => ['a' => 'hunter2'], 'cookie' => 12345]);

        self::assertStringNotContainsString('hunter2', $this->logged());
        self::assertStringNotContainsString('12345', $this->logged());
    }

    public function testKeepsNeighbouringKeysThatMerelyLookSimilar(): void
    {
        $this->logger()->info('seen', ['cookies' => 'keep-me', 'passwordPolicy' => 'keep-too']);

        self::assertStringContainsString('keep-me', $this->logged());
        self::assertStringContainsString('keep-too', $this->logged());
    }

    public function testRedactsCookieAndSetCookieLinesInsideAMessage(): void
    {
        $this->logger()->info("GET /\r\nCookie: a=b; __Host-session=zzz\r\nHost: x\r\nSet-Cookie: __Host-session=qqq; Secure; HttpOnly\r\nAccept: */*");

        $logged = $this->logged();
        self::assertStringNotContainsString('a=b', $logged);
        self::assertStringNotContainsString('zzz', $logged);
        self::assertStringNotContainsString('qqq', $logged);
        self::assertStringContainsString('Host: x', $logged);
        self::assertStringContainsString('Accept: */*', $logged);
        self::assertIsArray(json_decode($logged, true, flags: JSON_THROW_ON_ERROR));
    }

    public function testRedactsACookieLineInsideAContextString(): void
    {
        $this->logger()->info('sent', ['raw' => 'cookie: a=b']);

        self::assertStringNotContainsString('a=b', $this->logged());
    }

    public function testRedactsASessionCookieValueInsideAMessage(): void
    {
        $token = str_repeat('Ab3_-', 8) . 'xyz';

        $this->logger()->info("session __Host-session={$token} seen", ['detail' => "x __HOST-SESSION={$token}"]);

        self::assertStringNotContainsString($token, $this->logged());
        self::assertStringContainsString('seen', $this->logged());
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
        $capped = $this->logger()->capMessage(str_repeat('a', 1023) . 'é');

        self::assertSame(str_repeat('a', 1023), $capped);
        self::assertSame('short', $this->logger()->capMessage('short'));
    }

    public function testCapMessageRedactsBeforeCuttingSoASecretOnTheCapBoundaryDoesNotLeakItsPrefix(): void
    {
        $secret = 'Slot-A-0123456789-0123456789-abcdef';

        $capped = $this->logger([$secret])->capMessage(str_repeat('x', 1000) . $secret);

        self::assertStringNotContainsString(substr($secret, 0, 8), $capped);
        self::assertStringContainsString('[REDACTED]', $capped);
    }

    public function testAPlainObjectIsLoggedAsItsClassNameOnly(): void
    {
        $secret = 'Slot-A-0123456789-0123456789-abcdef';
        $object = new class ($secret) {
            public function __construct(public string $token) {}
        };

        $this->logger([$secret])->info('object', ['value' => $object, 'std' => (object) ['cookie' => 'a=b-cookie-value', 'other' => 'visible-property']]);

        $logged = $this->logged();
        self::assertStringNotContainsString($secret, $logged);
        self::assertStringNotContainsString('a=b-cookie-value', $logged);
        self::assertStringNotContainsString('visible-property', $logged);
        $entry = $this->firstEntry();
        self::assertSame(['class' => \stdClass::class], $entry['std']);
        self::assertSame(['class' => $object::class], $entry['value']);
    }

    public function testAJsonSerializableObjectGoesThroughTheSameRedaction(): void
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['password' => 'x-password-value', 'note' => "Cookie: a=b-cookie-value\n", 'fine' => 'visible'];
            }
        };

        $this->logger()->info('object', ['value' => $object]);

        $logged = $this->logged();
        self::assertStringNotContainsString('x-password-value', $logged);
        self::assertStringNotContainsString('a=b-cookie-value', $logged);
        self::assertStringContainsString('visible', $logged);
    }

    public function testAJsonSerializableThatReturnsAScalarIsRedactedAsText(): void
    {
        $object = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return 'Authorization: Bearer scalar-token';
            }
        };

        $this->logger()->info('object', ['value' => $object]);

        self::assertStringNotContainsString('scalar-token', $this->logged());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function normalisedSensitiveKeys(): iterable
    {
        foreach (['HTTP_COOKIE', 'set_cookie', 'HTTP_X_ORIGIN_VERIFY', 'HTTP_AUTHORIZATION', 'newPassword', "cookie\n", 'current-password', 'new_password', 'HTTP-Cookie', 'X_CSRF_TOKEN'] as $key) {
            yield addcslashes($key, "\n") => [$key];
        }
    }

    #[DataProvider('normalisedSensitiveKeys')]
    public function testRedactsKeysAfterNormalisingThem(string $key): void
    {
        $this->logger()->info('seen', [$key => 'sensitive-value']);

        self::assertStringNotContainsString('sensitive-value', $this->logged());
    }

    public function testKeepsKeysThatOnlyContainASensitiveWord(): void
    {
        $this->logger()->info('seen', ['http-cookies' => 'keep-1', 'passwordPolicy' => 'keep-2', 'my-cookie' => 'keep-3']);

        self::assertStringContainsString('keep-1', $this->logged());
        self::assertStringContainsString('keep-2', $this->logged());
        self::assertStringContainsString('keep-3', $this->logged());
    }

    public function testAStringArrayKeyIsRedactedAsText(): void
    {
        $token = str_repeat('Ab3_-', 8) . 'xyz';

        $this->logger()->info('keyed', ['map' => ["x __Host-session={$token} y" => 'value']]);

        self::assertStringNotContainsString($token, $this->logged());
    }

    public function testACookieLineIsRedactedToTheEndOfTheLineEvenWithQuotes(): void
    {
        $token = str_repeat('Ab3_-', 8) . 'xyz';

        $this->logger()->info("Cookie: a=\"x y\"; __Host-session={$token}\r\nHost: kept");

        $logged = $this->logged();
        self::assertStringNotContainsString($token, $logged);
        self::assertStringNotContainsString('x y', $logged);
        self::assertStringContainsString('Host: kept', $logged);
    }

    public function testACookieLineInsideAQuotedContextStringIsRedactedToTheEnd(): void
    {
        $token = str_repeat('Ab3_-', 8) . 'xyz';

        $this->logger()->info('sent', ['raw' => "Cookie: a=\"x y\"; __Host-session={$token}"]);

        self::assertStringNotContainsString($token, $this->logged());
        self::assertStringNotContainsString('x y', $this->logged());
    }

    public function testRedactsServerVariableStyleCookieLines(): void
    {
        $this->logger()->info("http_cookie: a=b-one\nset_cookie: c=d-two; Secure\nHTTP_COOKIE: e=f-three");

        $logged = $this->logged();
        self::assertStringNotContainsString('b-one', $logged);
        self::assertStringNotContainsString('d-two', $logged);
        self::assertStringNotContainsString('f-three', $logged);
    }

    public function testRedactsAUrlEncodedSessionCookie(): void
    {
        $token = str_repeat('Ab3_-', 8) . 'xyz';

        $this->logger()->info("query cookie=__Host-session%3D{$token}&a=1", ['more' => "__host-session%3d{$token}"]);

        self::assertStringNotContainsString($token, $this->logged());
    }

    public function testRedactsArgon2HashesInText(): void
    {
        $hash = '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$aGFzaGhhc2hoYXNo';

        $this->logger()->info("stored {$hash} done", ['i' => '$argon2i$v=19$m=1,t=1,p=1$abc$def', 'd' => 'x $argon2d$v=19$m=1,t=1,p=1$abc$def']);

        $logged = $this->logged();
        self::assertStringNotContainsString('argon2', $logged);
        self::assertStringNotContainsString('aGFzaGhhc2hoYXNo', $logged);
        self::assertStringContainsString('done', $logged);
    }

    public function testASecretUsedAsAnArrayKeyIsRedacted(): void
    {
        $secret = 'Slot-A-0123456789-0123456789-abcdef';

        $this->logger([$secret])->info('keyed', ['map' => [$secret => 'value']]);

        self::assertStringNotContainsString($secret, $this->logged());
    }

    public function testAJsonEscapedSecretInsideAlreadyEncodedTextIsRedacted(): void
    {
        $secret = 'Slot-"quoted"-0123456789-0123456789\end';
        $encoded = (string) json_encode(['header' => $secret]);

        $this->logger([$secret])->info($encoded, ['again' => $encoded]);

        $escaped = substr((string) json_encode($secret), 1, -1);
        self::assertStringNotContainsString($escaped, $this->logged());
        self::assertStringNotContainsString('0123456789-0123456789', $this->logged());
    }

    public function testARedactionThatWouldBreakTheJsonEscapesWritesAFixedSafeLine(): void
    {
        $slot = 'n0123456789abcdefghijklmnopqrstuvwxyzABC';

        $this->logger([$slot])->warning("\n" . substr($slot, 1), ['k' => 'v']);

        $line = trim($this->logged());
        self::assertTrue(json_validate($line));
        for ($i = 0; $i + 8 <= strlen($slot); ++$i) {
            self::assertStringNotContainsString(substr($slot, $i, 8), $line);
        }
        $entry = json_decode($line, true);
        self::assertIsArray($entry);
        self::assertSame(self::FIXED_TIME, $entry['time']);
        self::assertSame('warning', $entry['level']);
        self::assertSame('[REDACTED: line could not be redacted safely]', $entry['message']);
        self::assertArrayNotHasKey('k', $entry);
    }

    public function testASecretContainedInAnotherSecretIsRedactedWholeWhateverTheOrder(): void
    {
        $short = 'Slot-A-0123456789-0123456789-abcdef';

        $this->logger([$short, $short . 'TAIL'])->info('value ' . $short . 'TAIL end');

        self::assertStringNotContainsString('TAIL', $this->logged());
        self::assertStringContainsString('value [REDACTED] end', $this->logged());
    }
}
