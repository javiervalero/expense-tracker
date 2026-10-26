<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Tests\Support\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /expenses` end to end (nginx -> php-fpm -> DynamoDB Local), every call through requestAs(), so each response is
 * also checked against the bundled contract. Each test registers its own user, so no test depends on the item counts
 * of another. Run through `make test-integration`.
 */
final class ExpenseListApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const JSON = ['Content-Type' => 'application/json'];
    private const UNKNOWN_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const HIGHEST_ID = 'ffffffff-ffff-4fff-bfff-ffffffffffff';
    private const MAX_WALK_PAGES = 20;
    private const EXPENSE_FIELDS = ['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'];

    private function signedIn(): void
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'lst-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
    }

    private function add(string $date, string $description, ?string $categoryId = null): string
    {
        $body = ['date' => $date, 'amountCents' => 1250, 'description' => $description];
        if ($categoryId !== null) {
            $body['categoryId'] = $categoryId;
        }
        $response = $this->request('POST', '/expenses', ['headers' => self::JSON, 'body' => json_encode($body, JSON_THROW_ON_ERROR)]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function addCategory(string $name): string
    {
        $response = $this->request('POST', '/categories', ['headers' => self::JSON, 'body' => json_encode(['name' => $name], JSON_THROW_ON_ERROR)]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, string> $query
     */
    private function listing(array $query = []): ResponseInterface
    {
        $string = $query === [] ? '' : '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $this->requestAs('GET', '/expenses', 'GET', '/api/v1/expenses' . $string);
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{items: list<array<string, mixed>>, nextCursor: string|null}
     */
    private function page(array $query = []): array
    {
        $response = $this->listing($query);
        self::assertSame(200, $response->getStatusCode());
        $body = $this->problemBody($response);
        self::assertSame(['items', 'nextCursor'], array_keys($body));
        self::assertIsArray($body['items']);
        self::assertTrue($body['nextCursor'] === null || is_string($body['nextCursor']));

        /** @var array{items: list<array<string, mixed>>, nextCursor: string|null} $body */
        return $body;
    }

    /**
     * Follows nextCursor until it is null and returns every id seen, in order.
     *
     * @param array<string, string> $query
     *
     * @return list<string>
     */
    private function walk(array $query): array
    {
        $seen = [];
        for ($pages = 1; $pages <= self::MAX_WALK_PAGES; ++$pages) {
            $page = $this->page($query);
            $seen = [...$seen, ...array_map(static fn(array $item): string => is_string($item['id']) ? $item['id'] : '', $page['items'])];
            if ($page['nextCursor'] === null) {
                return $seen;
            }
            $query['cursor'] = $page['nextCursor'];
        }

        self::fail('The walk did not reach a null nextCursor in ' . self::MAX_WALK_PAGES . ' pages.');
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<string> the ids of one page
     */
    private function ids(array $query = []): array
    {
        return array_map(static fn(array $item): string => is_string($item['id']) ? $item['id'] : '', $this->page($query)['items']);
    }

    private function assertRefused(ResponseInterface $response, string $detail, string $case = ''): void
    {
        self::assertSame(400, $response->getStatusCode(), $case);
        self::assertSame($detail, $this->problemBody($response)['detail'] ?? null, $case);
    }

    public function testItListsNewestFirstWithTheEightFieldsAndNoCursorWhenEverythingFits(): void
    {
        $this->signedIn();
        $old = $this->add('2026-10-01', 'Old');
        $new = $this->add('2026-10-20', 'New');
        $mid = $this->add('2026-10-10', 'Mid');

        $page = $this->page();

        self::assertSame([$new, $mid, $old], array_column($page['items'], 'id'));
        self::assertSame(self::EXPENSE_FIELDS, array_keys($page['items'][0]));
        self::assertNull($page['nextCursor']);
    }

    public function testAnEmptyAccountGivesAnEmptyPage(): void
    {
        $this->signedIn();

        self::assertSame(['items' => [], 'nextCursor' => null], $this->page());
    }

    public function testFromAndToAreInclusiveBounds(): void
    {
        $this->signedIn();
        $this->add('2026-09-30', 'Before');
        $first = $this->add('2026-10-01', 'First day');
        $inside = $this->add('2026-10-15', 'Inside');
        $last = $this->add('2026-10-31', 'Last day');
        $this->add('2026-11-01', 'After');

        self::assertSame([$last, $inside, $first], $this->ids(['from' => '2026-10-01', 'to' => '2026-10-31']));
        self::assertSame([$first], $this->ids(['from' => '2026-10-01', 'to' => '2026-10-01']));
    }

    public function testTheCategoryFilterTakesAnOwnIdOrNoneAndNoneIncludesALeftoverId(): void
    {
        $this->signedIn();
        $food = $this->addCategory('Food');
        $gone = $this->addCategory('Gone');
        $fed = $this->add('2026-10-05', 'Lunch', $food);
        $leftover = $this->add('2026-10-04', 'Taxi', $gone);
        $plain = $this->add('2026-10-03', 'Gift');
        self::assertSame(204, $this->requestAs('DELETE', '/categories/{id}', 'DELETE', '/api/v1/categories/' . $gone)->getStatusCode());

        self::assertSame([$fed], $this->ids(['category' => $food]));
        self::assertSame([$leftover, $plain], $this->ids(['category' => 'none']));
        self::assertSame([], $this->ids(['category' => $gone]));
        self::assertSame($food, $this->page(['category' => $food])['items'][0]['categoryId']);
        self::assertNull($this->page()['items'][1]['categoryId']);
    }

    public function testACategoryIdThatIsNotTheCallersGivesAnEmptyPage(): void
    {
        $this->signedIn();
        $this->add('2026-10-05', 'Lunch');

        self::assertSame(['items' => [], 'nextCursor' => null], $this->page(['category' => self::UNKNOWN_ID]));
    }

    public function testQMatchesWithoutRegardToCaseAndSharpSMatchesDoubleS(): void
    {
        $this->signedIn();
        $coffee = $this->add('2026-10-03', 'Morning Coffee');
        $street = $this->add('2026-10-02', 'Straße toll');
        $this->add('2026-10-01', 'Lunch');

        self::assertSame([$coffee], $this->ids(['q' => 'COFFEE']));
        self::assertSame([$coffee], $this->ids(['q' => '  morning cof  ']));
        self::assertSame([$street], $this->ids(['q' => 'STRASSE']));
        self::assertSame([$street], $this->ids(['q' => 'straße']));
        self::assertSame([], $this->ids(['q' => 'nothing like it']));
        self::assertCount(3, $this->ids(['q' => '']));
    }

    public function testWalkingWithLimitTwoVisitsEveryItemOnceAndEndsAtANullCursor(): void
    {
        $this->signedIn();
        $expected = [];
        foreach (['2026-10-07', '2026-10-06', '2026-10-05', '2026-10-04', '2026-10-03'] as $date) {
            $expected[] = $this->add($date, 'Item ' . $date);
        }

        self::assertSame($expected, $this->walk(['limit' => '2']));
    }

    public function testAWalkAcrossOneBusyDayVisitsEveryItemExactlyOnce(): void
    {
        $this->signedIn();
        $created = [$this->add('2026-10-09', 'Later'), $this->add('2026-10-01', 'Earlier')];
        for ($n = 1; $n <= 5; ++$n) {
            $created[] = $this->add('2026-10-05', 'Same day ' . $n);
        }

        $seen = $this->walk(['limit' => '2']);

        self::assertCount(7, $seen);
        self::assertSame(array_values(array_unique($seen)), $seen);
        $sorted = $created;
        sort($sorted);
        $seenSorted = $seen;
        sort($seenSorted);
        self::assertSame($sorted, $seenSorted);
    }

    public function testAFilteredWalkAlsoVisitsEveryMatchOnce(): void
    {
        $this->signedIn();
        $food = $this->addCategory('Food');
        $expected = [];
        foreach (['2026-10-07', '2026-10-06', '2026-10-05', '2026-10-04', '2026-10-03'] as $index => $date) {
            $id = $this->add($date, 'Item ' . $date, $index % 2 === 0 ? $food : null);
            if ($index % 2 === 0) {
                $expected[] = $id;
            }
        }

        self::assertSame($expected, $this->walk(['limit' => '2', 'category' => $food]));
    }

    public function testTheLimitBoundariesOneAndOneHundredAreAccepted(): void
    {
        $this->signedIn();
        $this->add('2026-10-05', 'Lunch');
        $this->add('2026-10-04', 'Dinner');

        self::assertCount(1, $this->page(['limit' => '1'])['items']);
        self::assertNotNull($this->page(['limit' => '1'])['nextCursor']);
        self::assertCount(2, $this->page(['limit' => '100'])['items']);
    }

    public function testACursorReplayedWithAnotherQIs400(): void
    {
        $this->signedIn();
        foreach (['2026-10-03', '2026-10-02', '2026-10-01'] as $date) {
            $this->add($date, 'Lunch ' . $date);
        }
        $cursor = $this->page(['q' => 'lunch', 'limit' => '1'])['nextCursor'];
        self::assertNotNull($cursor);

        $this->assertRefused($this->listing(['q' => 'dinner', 'limit' => '1', 'cursor' => $cursor]), 'The cursor is not valid for these filters.');
        self::assertSame(200, $this->listing(['q' => 'lunch', 'limit' => '2', 'cursor' => $cursor])->getStatusCode());
    }

    private static function encodeCursor(mixed $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeCursor(string $cursor): array
    {
        $decoded = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['sk'] ?? null);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function cursorAfterFirstOfThree(): string
    {
        foreach (['2026-10-03', '2026-10-02', '2026-10-01'] as $date) {
            $this->add($date, 'Lunch');
        }
        $cursor = $this->page(['limit' => '1'])['nextCursor'];
        self::assertNotNull($cursor);

        return $cursor;
    }

    public function testACursorThatEncodeCouldNotHaveProducedOrThatNamesTwoExpensesOrAnotherFilterIs400(): void
    {
        $this->signedIn();
        $cursor = $this->cursorAfterFirstOfThree();
        $decoded = self::decodeCursor($cursor);
        $mismatched = array_replace($decoded, ['lsi' => 'EXP#2026-10-02#' . self::UNKNOWN_ID]);
        $rehashed = array_replace($decoded, ['f' => hash('sha256', 'another filter')]);
        $edited = [
            'sk and lsi of different expenses' => self::encodeCursor($mismatched),
            'another filter hash' => self::encodeCursor($rehashed),
            'not base64' => 'not a cursor!',
            'a changed first character' => ($cursor[0] === 'e' ? 'f' : 'e') . substr($cursor, 1),
            'too long' => str_repeat('A', 513),
        ];

        foreach ($edited as $case => $value) {
            $this->assertRefused($this->listing(['limit' => '1', 'cursor' => $value]), 'The cursor is not valid for these filters.', $case);
        }
    }

    public function testAWellFormedForgedPositionIsAcceptedAndStaysInsideTheCallersOwnExpenses(): void
    {
        $this->signedIn();
        $cursor = $this->cursorAfterFirstOfThree();
        $own = $this->ids();
        $decoded = self::decodeCursor($cursor);
        // The highest valid v4 id, so every other expense of the day sorts below the forged position.
        $moved = array_replace($decoded, ['sk' => 'EXP#' . self::HIGHEST_ID, 'lsi' => 'EXP#2026-10-02#' . self::HIGHEST_ID]);
        $unknown = array_replace($decoded, ['sk' => 'EXP#' . self::UNKNOWN_ID, 'lsi' => 'EXP#2026-10-02#' . self::UNKNOWN_ID]);

        $afterMoved = $this->page(['cursor' => self::encodeCursor($moved)]);
        $afterUnknown = $this->page(['cursor' => self::encodeCursor($unknown)]);

        foreach ([$afterMoved, $afterUnknown] as $page) {
            foreach ($page['items'] as $item) {
                self::assertContains($item['id'], $own);
                self::assertLessThanOrEqual('2026-10-02', $item['date']);
            }
        }
        self::assertSame([$own[1], $own[2]], array_column($afterMoved['items'], 'id'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function refusedQueries(): iterable
    {
        yield 'unknown parameter' => [['catgory' => 'none'], 'The query string has a parameter this operation does not accept.'];
        yield 'limit above the maximum' => [['limit' => '101'], 'The limit parameter is not valid.'];
        yield 'limit zero' => [['limit' => '0'], 'The limit parameter is not valid.'];
        yield 'from after to' => [['from' => '2026-10-02', 'to' => '2026-10-01'], 'The from parameter must not be after the to parameter.'];
        yield 'bad from' => [['from' => '2026-02-30'], 'The from parameter is not valid.'];
        yield 'bad category' => [['category' => 'None'], 'The category parameter is not valid.'];
        yield 'q with a bidi override' => [['q' => "a\u{202E}b"], 'The q parameter is not valid.'];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('refusedQueries')]
    public function testARefusedQueryIs400WithADetailThatNamesTheParameter(array $query, string $detail): void
    {
        $this->signedIn();
        $this->add('2026-10-05', 'Lunch');

        $this->assertRefused($this->listing($query), $detail);
    }

    public function testADateChangeMovesTheExpenseFromOneRangeToAnother(): void
    {
        $this->signedIn();
        $id = $this->add('2026-10-20', 'Lunch');
        self::assertSame([$id], $this->ids(['from' => '2026-10-01', 'to' => '2026-10-31']));

        $patched = $this->requestAs('PATCH', '/expenses/{id}', 'PATCH', '/api/v1/expenses/' . $id, ['headers' => self::JSON, 'body' => '{"version":1,"date":"2026-11-02"}']);

        self::assertSame(200, $patched->getStatusCode());
        self::assertSame([], $this->ids(['from' => '2026-10-01', 'to' => '2026-10-31']));
        self::assertSame([$id], $this->ids(['from' => '2026-11-01', 'to' => '2026-11-30']));
    }

    public function testAnotherUsersExpensesNeverAppear(): void
    {
        $this->signedIn();
        $mine = $this->add('2026-10-05', 'Shared words');
        $this->signedIn();
        $theirs = $this->add('2026-10-06', 'Theirs');

        self::assertSame([$theirs], $this->ids());
        self::assertSame([], $this->ids(['q' => 'shared words']));
        self::assertNotContains($mine, $this->ids(['q' => 'the']));
    }

    public function testWithoutASessionItIs401(): void
    {
        $this->withSessionCookie(null);

        self::assertSame(401, $this->listing()->getStatusCode());
        foreach ([['catgory' => 'x'], ['limit' => '0'], ['cursor' => 'garbage']] as $query) {
            self::assertSame(401, $this->listing($query)->getStatusCode());
        }
    }
}
