<?php

declare(strict_types=1);

namespace App\Expenses\Listing;

use App\Categories\CategoryResolver;
use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use App\Http\Input\FoldedText;
use App\Http\Input\PlainText;
use App\Http\Input\TextViolation;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;

/**
 * Everything a `GET /expenses` request asks for, validated (B17, B19): the day range (defaults: all), the category
 * filter, the folded text `q`, the page size and the cursor. Immutable. Every refusal is an {@see InvalidQuery} that
 * names the parameter and never its value, and the whole query is checked before the one store call that a category
 * filter needs, so a refused request costs nothing.
 *
 * Only `from`, `to`, `category` and `q` are part of the filter hash (the page size may change between pages).
 */
final readonly class ExpenseFilter
{
    /** The query parameters of the list; anything else is a typo or an attack, and is refused. */
    private const array PARAMETERS = [InvalidQuery::FROM, InvalidQuery::TO, InvalidQuery::CATEGORY, InvalidQuery::Q, InvalidQuery::LIMIT, InvalidQuery::CURSOR];
    private const int Q_MAX_CHARS = 100;
    private const string LIMIT_FORMAT = '/\A[1-9][0-9]{0,2}\z/D';

    /**
     * @param string|null $q the trimmed, folded text; null means no text filter
     */
    private function __construct(
        public ExpenseDate $from,
        public ExpenseDate $to,
        public ?CategoryFilter $category,
        public ?string $q,
        public int $limit,
        public ?ListCursor $cursor,
    ) {}

    /**
     * @param array<array-key, mixed> $query the parsed query string (its values are text, but a non-text value is refused, not trusted)
     *
     * @throws InvalidQuery
     */
    public static function fromQuery(array $query): self
    {
        foreach (array_keys($query) as $name) {
            if (!in_array($name, self::PARAMETERS, true)) {
                throw InvalidQuery::unknownParameter();
            }
        }
        $from = self::date($query, InvalidQuery::FROM, ExpenseDate::min());
        $to = self::date($query, InvalidQuery::TO, ExpenseDate::max());
        if ($from->isAfter($to)) {
            throw InvalidQuery::rangeReversed();
        }
        $rawCategory = self::text($query, InvalidQuery::CATEGORY);
        $category = $rawCategory === null ? null : CategoryFilter::parse($rawCategory) ?? throw InvalidQuery::invalid(InvalidQuery::CATEGORY);
        $q = self::foldedQ($query);
        $limit = self::limit($query);
        $hash = self::hashOf($from, $to, $category, $q);
        $rawCursor = self::text($query, InvalidQuery::CURSOR);
        $cursor = $rawCursor === null ? null : ListCursor::decode($rawCursor, $from, $to, $hash);

        return new self($from, $to, $category, $q, $limit, $cursor);
    }

    /** `from|to|category|q` after defaults and normalisation (`q` folded); `limit` and `cursor` are not in it. */
    public function canonical(): string
    {
        return self::canonicalOf($this->from, $this->to, $this->category, $this->q);
    }

    /** SHA-256 hex of {@see self::canonical()}: what a cursor must carry to be valid for this filter. */
    public function hash(): string
    {
        return self::hashOf($this->from, $this->to, $this->category, $this->q);
    }

    /** Whether some filter runs in PHP, so a batch can hold non-matches and must be read in full. */
    public function hasPhpFilters(): bool
    {
        return $this->category !== null || $this->q !== null;
    }

    /**
     * @throws InvalidArgumentException when the resolver's principal id is not a lower-case UUID
     * @throws StorageFailure from the resolver; it ends the request, so it is never caught per item
     */
    public function matches(Expense $expense, CategoryResolver $categories): bool
    {
        if ($this->q !== null && !str_contains(FoldedText::of($expense->description->value()), $this->q)) {
            return false;
        }

        return $this->category === null || $this->category->matches($expense->categoryId, $categories);
    }

    /** The cursor that continues after `$examined`, the last item the lister looked at. */
    public function cursorAfter(Expense $examined): string
    {
        return ListCursor::after($examined, $this->hash())->encode();
    }

    private static function hashOf(ExpenseDate $from, ExpenseDate $to, ?CategoryFilter $category, ?string $q): string
    {
        return hash('sha256', self::canonicalOf($from, $to, $category, $q));
    }

    private static function canonicalOf(ExpenseDate $from, ExpenseDate $to, ?CategoryFilter $category, ?string $q): string
    {
        return $from->value() . '|' . $to->value() . '|' . ($category?->value() ?? '') . '|' . ($q ?? '');
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidQuery
     */
    private static function text(array $query, string $name): ?string
    {
        $value = $query[$name] ?? null;
        if ($value !== null && !is_string($value)) {
            throw InvalidQuery::invalid($name);
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidQuery
     */
    private static function date(array $query, string $name, ExpenseDate $default): ExpenseDate
    {
        $value = self::text($query, $name);

        return $value === null ? $default : ExpenseDate::parse($value) ?? throw InvalidQuery::invalid($name);
    }

    /**
     * B4 trimming and refusals, then folding. An empty `q` (or one that is only spaces) means no `q`.
     *
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidQuery
     */
    private static function foldedQ(array $query): ?string
    {
        $value = self::text($query, InvalidQuery::Q);
        if ($value === null) {
            return null;
        }
        $text = PlainText::parse($value, self::Q_MAX_CHARS);
        if ($text === TextViolation::Empty) {
            return null;
        }

        if ($text instanceof TextViolation) {
            throw InvalidQuery::invalid(InvalidQuery::Q);
        }
        $folded = FoldedText::of($text);

        return $folded === '' ? null : $folded;
    }

    /**
     * Plain decimal digits, no sign, no leading zero, no exponent: `1e2`, `050` and `-1` are refused, not rounded.
     *
     * @param array<array-key, mixed> $query
     *
     * @throws InvalidQuery
     */
    private static function limit(array $query): int
    {
        $value = self::text($query, InvalidQuery::LIMIT);
        if ($value === null) {
            return ExpenseLister::LIMIT_DEFAULT;
        }
        $limit = preg_match(self::LIMIT_FORMAT, $value) === 1 ? (int) $value : 0;

        return $limit >= 1 && $limit <= ExpenseLister::LIMIT_MAX ? $limit : throw InvalidQuery::invalid(InvalidQuery::LIMIT);
    }
}
