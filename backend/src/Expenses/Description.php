<?php

declare(strict_types=1);

namespace App\Expenses;

use App\Http\Input\PlainText;
use App\Http\Input\TextViolation;

/** An expense description (B5): required, 1 to {@see self::MAX_CHARS} characters after trimming, with the B4 rules. */
final class Description
{
    public const MAX_CHARS = 200;

    private function __construct(private readonly string $value) {}

    public static function parse(string $input): self|TextViolation
    {
        $text = PlainText::parse($input, self::MAX_CHARS);

        return $text instanceof TextViolation ? $text : new self($text);
    }

    public function value(): string
    {
        return $this->value;
    }
}
