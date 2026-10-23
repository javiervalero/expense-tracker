<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Time;

use App\Infrastructure\Time\StoredTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoredTimeTest extends TestCase
{
    public function testFormatWritesWholeSecondsInUtcWithAZ(): void
    {
        $time = new DateTimeImmutable('2026-10-23T12:34:56Z');

        self::assertSame('2026-10-23T12:34:56Z', StoredTime::format($time));
    }

    public function testFormatDropsMicrosecondsAndConvertsToUtc(): void
    {
        $time = new DateTimeImmutable('2026-10-23T14:34:56.987654+02:00');

        self::assertSame('2026-10-23T12:34:56Z', StoredTime::format($time));
    }

    public function testParseReadsBackWhatFormatWrote(): void
    {
        $parsed = StoredTime::parse('2026-10-23T12:34:56Z');

        self::assertInstanceOf(DateTimeImmutable::class, $parsed);
        self::assertSame('UTC', $parsed->getTimezone()->getName());
        self::assertSame('2026-10-23T12:34:56Z', StoredTime::format($parsed));
        self::assertSame(1792758896, $parsed->getTimestamp());
    }

    #[DataProvider('notStoredTimes')]
    public function testParseRefusesAnythingThatIsNotAStrictStoredTime(mixed $value): void
    {
        self::assertNull(StoredTime::parse($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notStoredTimes(): iterable
    {
        yield 'missing zone' => ['2026-10-23T12:34:56'];
        yield 'an offset instead of Z' => ['2026-10-23T12:34:56+00:00'];
        yield 'fractions' => ['2026-10-23T12:34:56.123Z'];
        yield 'impossible date' => ['2026-02-30T12:34:56Z'];
        yield 'impossible time' => ['2026-10-23T25:00:00Z'];
        yield 'empty string' => [''];
        yield 'trailing text' => ['2026-10-23T12:34:56Z '];
        yield 'leading space' => [' 2026-10-23T12:34:56Z'];
        yield 'lower-case t and z' => ['2026-10-23t12:34:56z'];
        yield 'a space instead of T' => ['2026-10-23 12:34:56Z'];
        yield 'unpadded fields' => ['2026-1-3T1:2:3Z'];
        yield 'a leap second' => ['2026-10-23T23:59:60Z'];
        yield 'a five-digit year' => ['12026-10-23T12:34:56Z'];
        yield 'an integer' => [1792758896];
        yield 'a float' => [1792758896.0];
        yield 'null' => [null];
        yield 'a boolean' => [true];
        yield 'an array' => [['2026-10-23T12:34:56Z']];
    }

    public function testWholeSecondsUtcTruncatesMicrosecondsAndConvertsToUtc(): void
    {
        $time = new DateTimeImmutable('2026-10-23T14:34:56.987654', new DateTimeZone('Europe/Madrid'));

        $whole = StoredTime::wholeSecondsUtc($time);

        self::assertSame('UTC', $whole->getTimezone()->getName());
        self::assertSame('2026-10-23 12:34:56.000000', $whole->format('Y-m-d H:i:s.u'));
        self::assertEquals(StoredTime::parse('2026-10-23T12:34:56Z'), $whole);
    }
}
