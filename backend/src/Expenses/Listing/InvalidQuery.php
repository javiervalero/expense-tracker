<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use RuntimeException;

/**
 * A list request the server refuses (B17, B18, B19): it maps to `400` (`ListExpensesHandler` does the mapping; `422` is only
 * for body fields). The message is the problem's detail and is safe to show: it names the parameter and never carries
 * its value, the cursor or any item data.
 */
final class InvalidQuery extends RuntimeException
{
    public const string FROM = 'from';
    public const string TO = 'to';
    public const string CATEGORY = 'category';
    public const string Q = 'q';
    public const string LIMIT = 'limit';
    public const string CURSOR = 'cursor';

    /**
     * @param string $parameter one of the constants above, or '' when no parameter can be named
     */
    private function __construct(public readonly string $parameter, string $detail)
    {
        parent::__construct($detail);
    }

    /** @param string $parameter one of the constants above (never a name taken from the request) */
    public static function invalid(string $parameter): self
    {
        return new self($parameter, 'The ' . $parameter . ' parameter is not valid.');
    }

    public static function rangeReversed(): self
    {
        return new self(self::FROM, 'The from parameter must not be after the to parameter.');
    }

    /** B18: the one message of every cursor refusal. */
    public static function cursor(): self
    {
        return new self(self::CURSOR, 'The cursor is not valid for these filters.');
    }

    /** The name of an unknown parameter is client input, so it is not echoed. */
    public static function unknownParameter(): self
    {
        return new self('', 'The query string has a parameter this operation does not accept.');
    }
}
