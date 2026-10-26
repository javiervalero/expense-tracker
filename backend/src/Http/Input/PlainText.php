<?php

declare(strict_types=1);

namespace App\Http\Input;

/**
 * The shared rules of every free-text field (B4), checked in this order:
 *  - an input far longer than any valid text (the limit times four bytes, plus slack for edge padding) is too long,
 *    before any other work;
 *  - valid UTF-8;
 *  - spaces, tabs, line breaks and every Unicode space separator (`\p{Z}`) trimmed at the two edges only, with
 *    `mb_trim` and an explicit list (not its default set, which would also trim NUL and U+0085, hiding them);
 *  - control characters (`\p{Cc}`, so U+0085 and DEL too, even at an edge) and U+2028 and U+2029 refused;
 *  - bidi embedding, override and isolate characters (U+202A to U+202E, U+2066 to U+2069) refused, so a text cannot
 *    reorder what the UI shows;
 *  - invisible characters refused: every format character (`\p{Cf}`) except a few that real text needs: ZWNJ and ZWJ
 *    (U+200C, U+200D: scripts and emoji sequences), the tag characters U+E0020 to U+E007F (emoji flags), the weak
 *    direction marks LRM, RLM and ALM (U+200E, U+200F, U+061C: they come from real keyboards and cannot open an
 *    embedding, unlike the refused bidi controls) and the zero-width space (U+200B: the word-break hint of Thai,
 *    Khmer, Lao and Burmese). The blank-looking fillers (Hangul U+115F, U+1160, U+3164, U+FFA0 and braille blank
 *    U+2800) are refused too. {@see \App\Categories\CategoryName::key()} ignores the allowed invisibles;
 *  - a text with no letter, number, symbol or punctuation (only marks, ZWJ or tags) counts as empty;
 *  - length in characters (`mb_strlen`).
 *
 * The text is kept byte for byte: there is no Unicode normalisation (`intl` is in neither image). A PCRE failure
 * throws instead of passing the text ({@see Pcre}): the checks fail closed.
 */
final class PlainText
{
    /** NUL and U+0085 are deliberately absent. The rest of the list is every `\p{Z}` code point. */
    private const string TRIM_CHARACTERS = " \t\n\r\x0B\x0C\u{00A0}\u{1680}"
        . "\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200A}"
        . "\u{2028}\u{2029}\u{202F}\u{205F}\u{3000}";
    private const string CONTROL = '/[\p{Cc}\x{2028}\x{2029}]/u';
    private const string BIDI = '/[\x{202A}-\x{202E}\x{2066}-\x{2069}]/u';
    private const string INVISIBLE = '/(?![\x{200B}-\x{200F}\x{061C}\x{E0020}-\x{E007F}])\p{Cf}|[\x{115F}\x{1160}\x{2800}\x{3164}\x{FFA0}]/u';
    private const string VISIBLE = '/[\p{L}\p{N}\p{S}\p{P}]/u';
    private const int MAX_BYTES_PER_CHARACTER = 4;
    private const int EDGE_PADDING_SLACK_BYTES = 1024;

    /**
     * The trimmed text, or the first rule broken. A minimum of one character is always required.
     *
     * @param positive-int $maxChars
     */
    public static function parse(string $input, int $maxChars): string|TextViolation
    {
        if (strlen($input) > $maxChars * self::MAX_BYTES_PER_CHARACTER + self::EDGE_PADDING_SLACK_BYTES) {
            return TextViolation::TooLong;
        }
        if (!mb_check_encoding($input, 'UTF-8')) {
            return TextViolation::InvalidEncoding;
        }
        $text = mb_trim($input, self::TRIM_CHARACTERS, 'UTF-8');

        return match (true) {
            $text === '' => TextViolation::Empty,
            Pcre::matches(self::CONTROL, $text) => TextViolation::ControlCharacter,
            Pcre::matches(self::BIDI, $text) => TextViolation::BidiControl,
            Pcre::matches(self::INVISIBLE, $text) => TextViolation::InvisibleCharacter,
            !Pcre::matches(self::VISIBLE, $text) => TextViolation::Empty,
            mb_strlen($text, 'UTF-8') > $maxChars => TextViolation::TooLong,
            default => $text,
        };
    }
}
