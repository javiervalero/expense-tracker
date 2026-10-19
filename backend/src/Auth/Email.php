<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * A normalised e-mail address (A10): surrounding ASCII whitespace trimmed, ASCII lower-cased, at most 254 bytes,
 * no control characters, ASCII only, valid for `FILTER_VALIDATE_EMAIL`, and without quoted local parts or domain literals
 * (no `"`, `\`, `[` or `]`). PHP already refuses a domain without a dot (`a@localhost`). No provider tricks: dots and `+tags` are kept.
 * The user item stores {@see self::value()}.
 */
final class Email
{
    public const MAX_BYTES = 254;

    /** Not NUL, which `trim()` would remove by default: a NUL at the edge must be refused, not hidden. */
    private const TRIM_CHARACTERS = " \t\n\r\v\f";

    private function __construct(private readonly string $value) {}

    public static function parse(string $input): self|InvalidEmail
    {
        $value = strtolower(trim($input, self::TRIM_CHARACTERS));
        $problem = match (true) {
            $value === '' => InvalidEmail::Empty,
            preg_match('/[\x00-\x1F\x7F]/', $value) === 1 => InvalidEmail::ControlCharacter,
            preg_match('/[^\x00-\x7F]/', $value) === 1 => InvalidEmail::NonAscii,
            strlen($value) > self::MAX_BYTES => InvalidEmail::TooLong,
            preg_match('/["\\\\\[\]]/', $value) === 1 => InvalidEmail::Malformed,
            filter_var($value, FILTER_VALIDATE_EMAIL) === false => InvalidEmail::Malformed,
            default => null,
        };

        return $problem ?? new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }
}
