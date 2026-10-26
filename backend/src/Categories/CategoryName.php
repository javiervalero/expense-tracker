<?php

declare(strict_types=1);

namespace App\Categories;

use App\Http\Input\FoldedText;
use App\Http\Input\PlainText;
use App\Http\Input\TextViolation;

/**
 * A category name (B6): required, 1 to {@see self::MAX_CHARS} characters after trimming, with the B4 rules. The text
 * is stored as given ({@see self::value()}). Uniqueness is case-insensitive: {@see self::key()} is the SHA-256 hex
 * of the name without its allowed invisible characters (ZWSP, ZWNJ, ZWJ, LRM, RLM, ALM), with every run of space
 * separators (`\p{Zs}`) collapsed to one U+0020, and then case-folded (`MB_CASE_FOLD`, so `Straße` and `STRASSE`
 * collide, and so do `Eating out`, `Eating  out` and `Eating` + NBSP + `out`), the sort key of the name lock item.
 */
final class CategoryName
{
    public const int MAX_CHARS = 50;

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

    /** The name as uniqueness sees it: invisible characters removed, space runs collapsed, case-folded. Also the sort key. */
    public function foldedValue(): string
    {
        return FoldedText::of($this->value);
    }

    public function key(): string
    {
        return hash('sha256', $this->foldedValue());
    }
}
