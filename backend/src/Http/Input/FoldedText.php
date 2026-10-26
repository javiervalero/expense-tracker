<?php

declare(strict_types=1);

namespace App\Http\Input;

/**
 * The one folding rule for comparing texts without regard to case or spacing: the allowed invisible characters (ZWSP,
 * ZWNJ, ZWJ, LRM, RLM, ALM) removed, every run of space separators (`\p{Zs}`) collapsed to one U+0020, and then
 * case-folded (`MB_CASE_FOLD`, so `Straße` and `STRASSE` are equal). Shared by the uniqueness of category names
 * ({@see \App\Categories\CategoryName::foldedValue()}) and the `q` search of expenses, so the two cannot drift apart.
 * The input is a text that already passed {@see PlainText}; a PCRE failure throws ({@see Pcre}).
 */
final class FoldedText
{
    public static function of(string $text): string
    {
        $visible = Pcre::replace('/[\x{200B}-\x{200F}\x{061C}]/u', '', $text);
        $spaced = Pcre::replace('/\p{Zs}+/u', ' ', $visible);

        return mb_convert_case($spaced, MB_CASE_FOLD, 'UTF-8');
    }
}
