<?php

declare(strict_types=1);

namespace App\Infrastructure\Time;

use DateTimeImmutable;
use DateTimeZone;

/**
 * How times are stored: whole seconds in UTC, written `2026-10-23T12:34:56Z`. Reading is strict, so a value that
 * is not exactly what {@see self::format()} writes is refused rather than guessed at.
 */
final class StoredTime
{
    public const string FORMAT = 'Y-m-d\TH:i:s\Z';

    private function __construct() {}

    public static function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT);
    }

    /**
     * @return DateTimeImmutable|null null when the value is not a string written by {@see self::format()}
     */
    public static function parse(mixed $stored): ?DateTimeImmutable
    {
        if (!is_string($stored)) {
            return null;
        }
        $time = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $stored, new DateTimeZone('UTC'));

        return $time !== false && $time->format(self::FORMAT) === $stored ? $time : null;
    }

    /** Stored times have whole seconds in UTC, so a new or changed entity equals what a read gives back. */
    public static function wholeSecondsUtc(DateTimeImmutable $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s'), new DateTimeZone('UTC'));
    }
}
