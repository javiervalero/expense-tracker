<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Listing;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryResolver;
use App\Expenses\Listing\ExpenseFilter;
use App\Expenses\Listing\InvalidQuery;
use App\Tests\Support\CountingCategoryLister;
use App\Tests\Support\ExpenseBuilder;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parsing and matching of the list filters (B17, B19). Values are invented; a refused value must never be echoed.
 */
final class ExpenseFilterTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const UNKNOWN_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';

    private static function lister(): CountingCategoryLister
    {
        $name = CategoryName::parse('Food');
        self::assertInstanceOf(CategoryName::class, $name);

        return new CountingCategoryLister([Category::new(self::FOOD_ID, $name, new DateTimeImmutable('2026-10-23T10:00:00+00:00'))]);
    }

    private static function resolver(): CategoryResolver
    {
        return new CategoryResolver(self::lister(), new Principal(self::USER, str_repeat('a', 64)));
    }

    /** @param array<array-key, mixed> $query */
    private static function filter(array $query): ExpenseFilter
    {
        return ExpenseFilter::fromQuery($query);
    }

    public function testNoParametersMeansEverythingNewestFirstWithTheDefaultLimit(): void
    {
        $filter = self::filter([]);

        self::assertSame('2000-01-01', $filter->from->value());
        self::assertSame('2099-12-31', $filter->to->value());
        self::assertNull($filter->category);
        self::assertNull($filter->q);
        self::assertSame(50, $filter->limit);
        self::assertNull($filter->cursor);
        self::assertFalse($filter->hasPhpFilters());
        self::assertSame('2000-01-01|2099-12-31||', $filter->canonical());
    }

    public function testADateRangeIsKeptAndABoundaryDayIsAllowed(): void
    {
        $filter = self::filter(['from' => '2026-10-20', 'to' => '2026-10-20']);

        self::assertSame('2026-10-20|2026-10-20||', $filter->canonical());
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function refusedValues(): array
    {
        $secret = 'zq9-private-value';

        return [
            'from after to' => [['from' => '2026-10-21', 'to' => '2026-10-20'], 'from'],
            'from not a date' => [['from' => '2026-02-30'], 'from'],
            'from without zero padding' => [['from' => '2026-1-1'], 'from'],
            'from empty' => [['from' => ''], 'from'],
            'from is text' => [['from' => $secret], 'from'],
            'from out of range' => [['from' => '1999-12-31'], 'from'],
            'to not a date' => [['to' => '20261020'], 'to'],
            'to is text' => [['to' => $secret], 'to'],
            'category in upper case' => [['category' => strtoupper(self::FOOD_ID)], 'category'],
            'category NONE' => [['category' => 'NONE'], 'category'],
            'category empty' => [['category' => ''], 'category'],
            'category is text' => [['category' => $secret], 'category'],
            'category with a padded uuid' => [['category' => ' ' . self::FOOD_ID], 'category'],
            'q of 101 characters' => [['q' => str_repeat('a', 101)], 'q'],
            'q with a right-to-left override' => [['q' => "caf\u{202E}e"], 'q'],
            'q with a control character' => [['q' => "a\x07b"], 'q'],
            'q with an invisible character' => [['q' => "a\u{2060}b"], 'q'],
            'q with invalid UTF-8' => [['q' => "a\xFFb"], 'q'],
            'limit 0' => [['limit' => '0'], 'limit'],
            'limit 101' => [['limit' => '101'], 'limit'],
            'limit 1e2' => [['limit' => '1e2'], 'limit'],
            'limit 050' => [['limit' => '050'], 'limit'],
            'limit -1' => [['limit' => '-1'], 'limit'],
            'limit +5' => [['limit' => '+5'], 'limit'],
            'limit empty' => [['limit' => ''], 'limit'],
            'limit with a decimal' => [['limit' => '1.5'], 'limit'],
            'limit with a space' => [['limit' => '5 '], 'limit'],
            'limit with a trailing newline' => [['limit' => "5\n"], 'limit'],
            'limit in a very long digit string' => [['limit' => str_repeat('9', 400)], 'limit'],
            'limit is text' => [['limit' => $secret], 'limit'],
            'limit in full-width digits' => [['limit' => "\u{FF15}"], 'limit'],
            'cursor empty' => [['cursor' => ''], 'cursor'],
        ];
    }

    /** @param array<string, string> $query */
    #[DataProvider('refusedValues')]
    public function testARefusedValueIsAnInvalidQueryThatNamesTheParameterButNotTheValue(array $query, string $parameter): void
    {
        try {
            self::filter($query);
            self::fail('The query was accepted.');
        } catch (InvalidQuery $refused) {
            self::assertSame($parameter, $refused->parameter);
            self::assertStringContainsString($parameter, $refused->getMessage());
            foreach (array_filter($query, static fn(string $value): bool => $value !== '') as $value) {
                self::assertStringNotContainsString($value, $refused->getMessage(), 'the detail must not echo a value');
            }
        }
    }

    public function testAReversedRangeHasItsOwnMessage(): void
    {
        try {
            self::filter(['from' => '2026-10-21', 'to' => '2026-10-20']);
            self::fail('The query was accepted.');
        } catch (InvalidQuery $refused) {
            self::assertSame('The from parameter must not be after the to parameter.', $refused->getMessage());
        }
    }

    /** @return array<string, array{array<array-key, mixed>}> */
    public static function unsupportedShapes(): array
    {
        return [
            'a typo' => [['catgory' => 'none']],
            'a name in other case' => [['Limit' => '5']],
            'an unknown name beside valid ones' => [['limit' => '5', 'sort' => 'asc']],
            'a numeric name' => [[7 => 'x']],
            'an array value' => [['limit' => ['5']]],
            'an array value for q' => [['q' => ['a']]],
        ];
    }

    /** @param array<array-key, mixed> $query */
    #[DataProvider('unsupportedShapes')]
    public function testAnUnknownParameterOrAValueThatIsNotTextIsAnInvalidQuery(array $query): void
    {
        $this->expectException(InvalidQuery::class);

        self::filter($query);
    }

    public function testAnUnknownParameterIsNotEchoed(): void
    {
        try {
            self::filter(['catgory' => 'none']);
            self::fail('The query was accepted.');
        } catch (InvalidQuery $refused) {
            self::assertStringNotContainsString('catgory', $refused->getMessage());
            self::assertSame('', $refused->parameter);
        }
    }

    public function testLimitsFromOneToOneHundredAreAccepted(): void
    {
        self::assertSame(1, self::filter(['limit' => '1'])->limit);
        self::assertSame(7, self::filter(['limit' => '7'])->limit);
        self::assertSame(100, self::filter(['limit' => '100'])->limit);
    }

    public function testCategoryNoneIsAFilter(): void
    {
        $filter = self::filter(['category' => 'none']);

        self::assertNotNull($filter->category);
        self::assertTrue($filter->hasPhpFilters());
        self::assertSame('2000-01-01|2099-12-31|none|', $filter->canonical());
    }

    public function testACategoryOfTheCallerIsAFilter(): void
    {
        $filter = self::filter(['category' => self::FOOD_ID]);

        self::assertSame('2000-01-01|2099-12-31|' . self::FOOD_ID . '|', $filter->canonical());
    }

    public function testAnyWellFormedCategoryUuidIsRecordedWithoutBeingChecked(): void
    {
        $filter = self::filter(['category' => self::UNKNOWN_ID]);

        self::assertSame(self::UNKNOWN_ID, $filter->category?->id, 'ownership is the lister\'s check, not the parser\'s');
    }

    public function testQIsTrimmedAndCaseFolded(): void
    {
        self::assertSame('strasse', self::filter(['q' => "  Stra\u{00DF}e\t"])->q);
        self::assertSame('a b', self::filter(['q' => "A\u{00A0}\u{00A0}B"])->q);
        self::assertTrue(self::filter(['q' => 'x'])->hasPhpFilters());
    }

    public function testAQThatHasNothingVisibleMeansNoQ(): void
    {
        $filter = self::filter(['q' => "\u{200B}"]);

        self::assertNull($filter->q);
        self::assertFalse($filter->hasPhpFilters());
        self::assertSame(self::filter([])->hash(), $filter->hash());
    }

    public function testQOfOneHundredCharactersIsAccepted(): void
    {
        self::assertSame(str_repeat('a', 100), self::filter(['q' => str_repeat('A', 100)])->q);
    }

    public function testAnEmptyQOrOneOfOnlySpacesMeansNoQ(): void
    {
        self::assertNull(self::filter(['q' => ''])->q);
        self::assertNull(self::filter(['q' => "  \t "])->q);
        self::assertFalse(self::filter(['q' => ''])->hasPhpFilters());
        self::assertSame(self::filter([])->hash(), self::filter(['q' => ' '])->hash());
    }

    public function testEqualCanonicalFormsHashEqually(): void
    {
        $plain = self::filter(['q' => 'food']);

        self::assertSame($plain->hash(), self::filter(['q' => '  FOOD '])->hash());
        self::assertSame(self::filter([])->hash(), self::filter(['from' => '2000-01-01', 'to' => '2099-12-31'])->hash());
        self::assertSame($plain->hash(), self::filter(['q' => 'food', 'limit' => '7'])->hash(), 'the page size is not part of the hash');
        self::assertSame(hash('sha256', $plain->canonical()), $plain->hash());
        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $plain->hash());
    }

    public function testDifferentFiltersHashDifferently(): void
    {
        $hashes = [
            self::filter([])->hash(),
            self::filter(['q' => 'food'])->hash(),
            self::filter(['category' => 'none'])->hash(),
            self::filter(['category' => self::FOOD_ID])->hash(),
            self::filter(['from' => '2026-01-01'])->hash(),
            self::filter(['to' => '2026-01-01'])->hash(),
        ];

        self::assertCount(count($hashes), array_unique($hashes));
    }

    public function testAQThatContainsThePipeCannotMimicAnotherFilter(): void
    {
        self::assertNotSame(
            self::filter(['q' => 'a|b'])->hash(),
            self::filter(['category' => 'none', 'q' => 'b'])->hash(),
        );
    }

    public function testQMatchesAFoldedSubstringOfTheDescription(): void
    {
        $filter = self::filter(['q' => "CAF\u{00C9}"]);
        $resolver = self::resolver();

        self::assertTrue($filter->matches(ExpenseBuilder::expense(1, text: "Lunch at the caf\u{00E9} today"), $resolver));
        self::assertTrue(self::filter(['q' => 'strasse'])->matches(ExpenseBuilder::expense(1, text: "Stra\u{00DF}e"), $resolver));
        self::assertTrue(self::filter(['q' => 'a b'])->matches(ExpenseBuilder::expense(1, text: "x A\u{00A0}\u{00A0}B y"), $resolver));
        self::assertFalse($filter->matches(ExpenseBuilder::expense(1, text: 'Lunch'), $resolver));
    }

    public function testNoFilterMatchesEverything(): void
    {
        self::assertTrue(self::filter([])->matches(ExpenseBuilder::expense(1), self::resolver()));
    }

    public function testCategoryNoneMatchesUncategorizedAndLeftoverIdsButNotARealCategory(): void
    {
        $filter = self::filter(['category' => 'none']);
        $resolver = self::resolver();

        self::assertTrue($filter->matches(ExpenseBuilder::expense(1), $resolver));
        self::assertTrue($filter->matches(ExpenseBuilder::expense(2, categoryId: self::UNKNOWN_ID), $resolver), 'a deleted category counts as none');
        self::assertFalse($filter->matches(ExpenseBuilder::expense(3, categoryId: self::FOOD_ID), $resolver));
    }

    public function testACategoryIdMatchesOnlyItsOwnExpenses(): void
    {
        $filter = self::filter(['category' => self::FOOD_ID]);
        $resolver = self::resolver();

        self::assertTrue($filter->matches(ExpenseBuilder::expense(1, categoryId: self::FOOD_ID), $resolver));
        self::assertFalse($filter->matches(ExpenseBuilder::expense(2), $resolver));
        self::assertFalse($filter->matches(ExpenseBuilder::expense(3, categoryId: self::UNKNOWN_ID), $resolver));
    }

    public function testCategoryAndQMustBothMatch(): void
    {
        $filter = self::filter(['category' => self::FOOD_ID, 'q' => 'lunch']);
        $resolver = self::resolver();

        self::assertTrue($filter->matches(ExpenseBuilder::expense(1, text: 'Lunch', categoryId: self::FOOD_ID), $resolver));
        self::assertFalse($filter->matches(ExpenseBuilder::expense(2, text: 'Lunch'), $resolver));
        self::assertFalse($filter->matches(ExpenseBuilder::expense(3, text: 'Dinner', categoryId: self::FOOD_ID), $resolver));
    }

    public function testACursorAfterAnExpenseIsValidForTheSameFilter(): void
    {
        $filter = self::filter(['q' => 'lunch']);

        $raw = $filter->cursorAfter(ExpenseBuilder::expense(5, '2026-10-15'));
        $next = self::filter(['q' => 'LUNCH', 'cursor' => $raw, 'limit' => '3']);

        self::assertSame('EXP#2026-10-15#' . ExpenseBuilder::uuid(5), $next->cursor?->startKey()['LSI1SK']);
        $this->expectException(InvalidQuery::class);
        self::filter(['q' => 'dinner', 'cursor' => $raw]);
    }
}
