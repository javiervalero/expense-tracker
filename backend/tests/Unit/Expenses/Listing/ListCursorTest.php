<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Listing;

use App\Auth\Session\Principal;
use App\Categories\CategoryResolver;
use App\Expenses\ExpenseDate;
use App\Expenses\Listing\ExpenseFilter;
use App\Expenses\Listing\ExpenseLister;
use App\Expenses\Listing\InvalidQuery;
use App\Expenses\Listing\ListCursor;
use App\Tests\Support\CountingCategoryLister;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FakeExpenseRangeReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The opaque cursor (B18): what it carries, and every way a client-made one is refused. Ids and dates are invented.
 */
final class ListCursorTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const FROM = '2026-10-10';
    private const TO = '2026-10-20';

    private static function date(string $text): ExpenseDate
    {
        $date = ExpenseDate::parse($text);
        self::assertInstanceOf(ExpenseDate::class, $date);

        return $date;
    }

    private static function hash(): string
    {
        return ExpenseFilter::fromQuery(['from' => self::FROM, 'to' => self::TO, 'category' => self::FOOD_ID])->hash();
    }

    /** @param array<array-key, mixed> $payload */
    private static function raw(array $payload): string
    {
        return self::base64url(self::json($payload));
    }

    /** @param array<array-key, mixed> $payload */
    private static function json(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * A cursor that decodes to a valid payload but is not what encode() writes: the JSON is padded with blanks until the
     * last base64 group is partial, and a bit that must be zero is set.
     *
     * @param array<array-key, mixed> $payload
     */
    private static function withStrayTrailingBits(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        while (strlen($json) % 3 !== 1) {
            $json .= ' ';
        }
        $canonical = self::base64url($json);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
        $last = strpos($alphabet, substr($canonical, -1));
        self::assertIsInt($last);

        return substr($canonical, 0, -1) . $alphabet[$last | 1];
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return array{v: int, sk: string, lsi: string, f: string} */
    private static function payload(int $n = 5, string $date = '2026-10-15'): array
    {
        $id = ExpenseBuilder::uuid($n);

        return ['v' => 1, 'sk' => 'EXP#' . $id, 'lsi' => 'EXP#' . $date . '#' . $id, 'f' => self::hash()];
    }

    private static function decode(string $raw): ListCursor
    {
        return ListCursor::decode($raw, self::date(self::FROM), self::date(self::TO), self::hash());
    }

    public function testACursorRoundTripsToTheStartKeyOfTheExaminedItem(): void
    {
        $examined = ExpenseBuilder::expense(5, '2026-10-15');

        $encoded = ListCursor::after($examined, self::hash())->encode();
        $decoded = self::decode($encoded);

        self::assertSame(
            ['SK' => 'EXP#' . ExpenseBuilder::uuid(5), 'LSI1SK' => 'EXP#2026-10-15#' . ExpenseBuilder::uuid(5)],
            $decoded->startKey(),
        );
    }

    public function testAnEncodedCursorIsUnpaddedBase64urlJsonWithExactlyTheFourMembers(): void
    {
        $encoded = ListCursor::after(ExpenseBuilder::expense(5, '2026-10-15'), self::hash())->encode();

        self::assertMatchesRegularExpression('/\A[A-Za-z0-9_-]+\z/', $encoded);
        self::assertLessThanOrEqual(512, strlen($encoded));
        $json = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', (4 - strlen($encoded) % 4) % 4), true);
        self::assertIsString($json);
        self::assertSame(self::payload(), json_decode($json, true, 4, JSON_THROW_ON_ERROR));
        self::assertSame('{"v":1,"sk":', substr($json, 0, 12));
    }

    /** @return array<string, array{string}> */
    public static function refusedCursors(): array
    {
        $valid = self::raw(self::payload());
        $other = ExpenseBuilder::uuid(6);
        $profile = self::payload();
        $profile['sk'] = 'PROFILE';
        $category = self::payload();
        $category['sk'] = 'CAT#' . ExpenseBuilder::uuid(5);

        return [
            'empty' => [''],
            'padding' => [$valid . '='],
            'plus' => [substr($valid, 0, 8) . '+' . substr($valid, 9)],
            'slash' => [substr($valid, 0, 8) . '/' . substr($valid, 9)],
            '513 characters' => [str_repeat('A', 513)],
            'not base64 at all' => ['not a cursor!'],
            'impossible base64 length' => ['AAAAA'],
            'trailing bits that the canonical encoding never has' => [self::withStrayTrailingBits(self::payload())],
            'not JSON' => [self::base64url('this is not json')],
            'invalid UTF-8 inside' => [self::base64url("{\"v\":1,\"sk\":\"\xff\"}")],
            'JSON null' => [self::base64url('null')],
            'JSON string' => [self::base64url('"EXP#"')],
            'JSON list' => [self::raw(array_values(self::payload()))],
            'extra key' => [self::raw(self::payload() + ['x' => 'y'])],
            'missing key' => [self::raw(array_diff_key(self::payload(), ['f' => 1]))],
            'renamed key' => [self::raw(['v' => 1, 'sk' => self::payload()['sk'], 'lsi' => self::payload()['lsi'], 'g' => self::hash()])],
            'v is 2' => [self::raw(['v' => 2] + self::payload())],
            'v is the string 1' => [self::raw(['v' => '1'] + self::payload())],
            'v is true' => [self::raw(['v' => true] + self::payload())],
            'sk is a number' => [self::raw(['sk' => 5] + self::payload())],
            'sk is an array' => [self::raw(['sk' => ['EXP#' . ExpenseBuilder::uuid(5)]] + self::payload())],
            'hash is an array' => [self::raw(['f' => [self::hash()]] + self::payload())],
            'hash is null' => [self::raw(['f' => null] + self::payload())],
            'nested deeper than two levels' => [self::raw(['sk' => ['a' => ['b' => 1]]] + self::payload())],
            'sk and lsi hold different uuids' => [self::raw(['lsi' => 'EXP#2026-10-15#' . $other] + self::payload())],
            'sk is a category' => [self::raw($category)],
            'sk is the profile' => [self::raw($profile)],
            'sk has no prefix' => [self::raw(['sk' => ExpenseBuilder::uuid(5)] + self::payload())],
            'uuid in upper case' => [self::raw(['sk' => 'EXP#' . strtoupper(ExpenseBuilder::uuid(10)), 'lsi' => 'EXP#2026-10-15#' . strtoupper(ExpenseBuilder::uuid(10))] + self::payload())],
            'uuid with a suffix' => [self::raw(['sk' => 'EXP#' . ExpenseBuilder::uuid(5) . '#x'] + self::payload())],
            'lsi without a date' => [self::raw(['lsi' => 'EXP#' . ExpenseBuilder::uuid(5)] + self::payload())],
            'lsi with a bad date' => [self::raw(['lsi' => 'EXP#2026-02-30#' . ExpenseBuilder::uuid(5)] + self::payload())],
            'date after the range' => [self::raw(self::payload(5, '2026-10-21'))],
            'date before the range' => [self::raw(self::payload(5, '2026-10-09'))],
            'a duplicated sk member' => [self::base64url(str_replace('"lsi":', '"sk":"' . self::payload()['sk'] . '","lsi":', self::json(self::payload())))],
            'members in another order' => [self::raw(['sk' => self::payload()['sk'], 'v' => 1, 'lsi' => self::payload()['lsi'], 'f' => self::hash()])],
            'JSON with blanks' => [self::base64url(str_replace(',', ', ', self::json(self::payload())))],
            'JSON with a trailing blank' => [self::base64url(self::json(self::payload()) . ' ')],
            'a foreign hash' => [self::raw(['f' => hash('sha256', 'another filter')] + self::payload())],
            'a hash in upper case' => [self::raw(['f' => strtoupper(self::hash())] + self::payload())],
            'a hash of the filter with another category' => [self::raw(['f' => hash('sha256', self::FROM . '|' . self::TO . '|none|')] + self::payload())],
        ];
    }

    #[DataProvider('refusedCursors')]
    public function testARefusedCursorIsAnInvalidQueryWithTheFixedMessage(string $raw): void
    {
        try {
            ListCursor::decode($raw, self::date(self::FROM), self::date(self::TO), self::hash());
            self::fail('The cursor was accepted.');
        } catch (InvalidQuery $refused) {
            self::assertSame('The cursor is not valid for these filters.', $refused->getMessage());
            self::assertSame('cursor', $refused->parameter);
        }
    }

    public function testACursorHashedOverTheUnfoldedQIsRefused(): void
    {
        $unfolded = hash('sha256', self::FROM . '|' . self::TO . '||Food');
        $raw = ListCursor::after(ExpenseBuilder::expense(5, '2026-10-15'), $unfolded)->encode();

        $this->expectException(InvalidQuery::class);

        ExpenseFilter::fromQuery(['from' => self::FROM, 'to' => self::TO, 'q' => 'Food', 'cursor' => $raw]);
    }

    public function testACursorWhoseHashIsOfTheFoldedQIsAcceptedForAnyCasingOfQ(): void
    {
        $folded = ExpenseFilter::fromQuery(['from' => self::FROM, 'to' => self::TO, 'q' => 'food']);
        $raw = ListCursor::after(ExpenseBuilder::expense(5, '2026-10-15'), $folded->hash())->encode();

        $filter = ExpenseFilter::fromQuery(['from' => self::FROM, 'to' => self::TO, 'q' => '  FOOD ', 'cursor' => $raw]);

        self::assertNotNull($filter->cursor);
    }

    public function testALongCursorIsRefusedEvenWhenEverythingElseIsValid(): void
    {
        $longHash = str_repeat('a', 600);
        $raw = self::raw(array_replace(self::payload(), ['f' => $longHash]));

        self::assertGreaterThan(512, strlen($raw));
        $this->expectException(InvalidQuery::class);

        ListCursor::decode($raw, self::date(self::FROM), self::date(self::TO), $longHash);
    }

    public function testACursorOfExactlyFiveHundredAndTwelveCharactersIsAccepted(): void
    {
        // A canonical cursor is about 270 characters, so a longer hash stands in to reach the cap.
        $json = json_encode(self::payload(), JSON_THROW_ON_ERROR);
        $hash = str_repeat('a', 64 + 384 - strlen($json));
        $raw = self::raw(array_replace(self::payload(), ['f' => $hash]));

        self::assertSame(512, strlen($raw));
        self::assertSame(
            self::payload()['lsi'],
            ListCursor::decode($raw, self::date(self::FROM), self::date(self::TO), $hash)->startKey()['LSI1SK'],
        );
    }

    public function testACursorOnTheFirstAndLastDayOfTheRangeIsAccepted(): void
    {
        self::assertSame('EXP#2026-10-10#' . ExpenseBuilder::uuid(5), self::decode(self::raw(self::payload(5, '2026-10-10')))->startKey()['LSI1SK']);
        self::assertSame('EXP#2026-10-20#' . ExpenseBuilder::uuid(5), self::decode(self::raw(self::payload(5, '2026-10-20')))->startKey()['LSI1SK']);
    }

    #[DataProvider('refusedCursors')]
    public function testARefusedCursorMakesNoStoreCallAtAll(string $raw): void
    {
        $categories = new CountingCategoryLister([]);
        $reader = new FakeExpenseRangeReader([]);
        $owner = new Principal(self::USER, str_repeat('a', 64));

        try {
            $filter = ExpenseFilter::fromQuery(['from' => self::FROM, 'to' => self::TO, 'category' => self::FOOD_ID, 'cursor' => $raw]);
            (new ExpenseLister($reader))->list($owner, $filter, new CategoryResolver($categories, $owner));
            self::fail('The query was accepted.');
        } catch (InvalidQuery $refused) {
            self::assertSame('cursor', $refused->parameter);
        }
        self::assertSame(0, $categories->calls, 'a refused cursor must not cost a category read');
        self::assertSame([], $reader->calls, 'nor an expense read');
    }
}
