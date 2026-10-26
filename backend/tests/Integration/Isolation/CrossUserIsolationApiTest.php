<?php

declare(strict_types=1);

namespace App\Tests\Integration\Isolation;

use App\Tests\Support\Api\ApiTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * @phpstan-type World array{a: string, b: string, aNames: list<string>, aCategories: list<string>, aExpenses: list<string>, bCategory: string, bExpenses: list<string>, needle: string, snapshot: array<string, string>}
 *
 * Cross-user isolation end to end (nginx -> php-fpm -> DynamoDB Local), every call through requestAs(), so each
 * response is also checked against the bundled contract. User A owns 2 categories and 5 expenses; user B, a second
 * account, tries to reach them by id, by body reference, by filter and by cursor. Each test builds its own two users.
 * Run through `make test-integration`.
 *
 * {@see self::SECURED_CALLS} lists the secured operations this suite covers; CrossUserCoverageTest keeps it equal to
 * the secured operations of the bundled contract (operationId, method and path template), so a new secured operation
 * fails CI until it is covered here. That check guarantees an entry here, not the depth of the coverage: the foreign-id
 * attempts are {@see self::ID_ATTEMPTS}, and the body-reference and filter coverage (a category id of another user in a
 * body, in `category=`, in a cursor) lives in the other tests of this class and in the list and expense tests.
 */
final class CrossUserIsolationApiTest extends ApiTestCase
{
    /**
     * The secured operations as [method, path template], with the ids of user A where the path has one.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const array SECURED_CALLS = [
        'createCategory' => ['POST', '/categories'],
        'createExpense' => ['POST', '/expenses'],
        'deleteCategory' => ['DELETE', '/categories/{id}'],
        'deleteExpense' => ['DELETE', '/expenses/{id}'],
        'getCurrentUser' => ['GET', '/auth/me'],
        'getExpense' => ['GET', '/expenses/{id}'],
        'listCategories' => ['GET', '/categories'],
        'listExpenses' => ['GET', '/expenses'],
        'renameCategory' => ['PATCH', '/categories/{id}'],
        'updateExpense' => ['PATCH', '/expenses/{id}'],
    ];

    /**
     * The operations that take a route id, with the foreign id attempted by user B in
     * {@see self::testAnotherUsersIdGivesTheSame404AsAnUnknownIdAndAMalformedId()}. Every SECURED_CALLS row with an
     * `{id}` must be here with the same method and template (checked below).
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const array ID_ATTEMPTS = [
        'getExpense' => ['GET', '/expenses/{id}'],
        'updateExpense' => ['PATCH', '/expenses/{id}'],
        'deleteExpense' => ['DELETE', '/expenses/{id}'],
        'renameCategory' => ['PATCH', '/categories/{id}'],
        'deleteCategory' => ['DELETE', '/categories/{id}'],
    ];

    /**
     * The headers left out of the byte-identical comparison of two 404 answers: `Date` changes every second. Nothing
     * else varies per request today (the API sends no request id header), so every other header is compared; if one is
     * ever added, this comparison fails loudly and the header is listed here with a reason.
     */
    private const array PER_REQUEST_HEADERS = ['date'];

    private const string PASSWORD = 'correct horse staple 42';
    private const array JSON = ['Content-Type' => 'application/json'];
    private const int PAGE_SIZE = 2;
    private const int STALE_VERSION = 99;
    private const string DATE_RANGE = '?from=2026-10-01&to=2026-10-31';
    private const string GARBAGE_COOKIE = 'garbage-session-value';

    public function testTheTablesOfCallsAreConsistent(): void
    {
        foreach (self::SECURED_CALLS as $operationId => [$method, $template]) {
            if (self::hasRouteId($template)) {
                self::assertSame([$method, $template], self::ID_ATTEMPTS[$operationId] ?? null, "$operationId takes a route id but has no matching ID_ATTEMPTS row");
            }
        }
        foreach (self::ID_ATTEMPTS as $operationId => $call) {
            self::assertSame($call, self::SECURED_CALLS[$operationId], "ID_ATTEMPTS row $operationId is not a SECURED_CALLS row");
        }
    }

    public function testAnotherUsersIdGivesTheSame404AsAnUnknownIdAndAMalformedId(): void
    {
        $world = $this->world();
        $this->actAs($world['b']);

        // The second round sends a stale version (and A's category id) too: the 404 must come before any 409 or 422.
        foreach ([false, true] as $stale) {
            foreach (self::ID_ATTEMPTS as $operationId => [$method, $template]) {
                $body = $this->attemptBody($operationId, $stale, $world['aCategories'][0]);
                $ownerId = str_contains($template, '/categories/') ? $world['aCategories'][0] : $world['aExpenses'][0];
                $foreign = $this->fingerprint($this->call($method, $template, str_replace('{id}', $ownerId, $template), $body));
                $unknown = $this->fingerprint($this->call($method, $template, str_replace('{id}', self::randomUuid(), $template), $body));
                $malformed = $this->fingerprint($this->call($method, $template, str_replace('{id}', 'not-a-uuid', $template), $body));

                self::assertSame(404, $foreign['status'], $operationId);
                self::assertSame($unknown, $foreign, "$operationId: another user's id differs from an unknown id");
                self::assertSame($malformed, $foreign, "$operationId: another user's id differs from a malformed id");
            }
        }
        $this->assertUnchanged($world);
    }

    public function testACategoryIdOfAnotherUserIsRefusedLikeAnUnknownOneAt422CategoryId(): void
    {
        $world = $this->world();
        $this->actAs($world['b']);
        $listBefore = (string) $this->listing('')->getBody();
        $create = fn(string $categoryId): ResponseInterface => $this->call('POST', '/expenses', '/expenses', [
            'date' => '2026-09-10', 'amountCents' => 100, 'description' => 'Sneaky', 'categoryId' => $categoryId,
        ]);
        $patch = fn(string $categoryId): ResponseInterface => $this->call('PATCH', '/expenses/{id}', '/expenses/' . $world['bExpenses'][0], [
            'version' => 1, 'categoryId' => $categoryId,
        ]);

        foreach ([$create, $patch] as $attempt) {
            $foreign = $attempt($world['aCategories'][0]);

            self::assertSame(['/categoryId'], $this->pointersOf($foreign));
            self::assertSame($this->fingerprint($attempt(self::randomUuid())), $this->fingerprint($foreign));
        }
        self::assertSame($listBefore, (string) $this->listing('')->getBody(), "B's own expenses changed or grew");
        $this->assertUnchanged($world);
    }

    public function testListsOfAnotherUserContainNoneOfTheOwnersIds(): void
    {
        $world = $this->world();
        $needle = $world['needle'];
        $this->actAs($world['a']);
        // Controls: as A the same filters do match, so an empty answer for B is the isolation and not a dead filter.
        self::assertSame([$world['aExpenses'][0]], $this->listedIds('?category=' . $world['aCategories'][0] . '&q=' . $needle));
        self::assertSame([$world['aExpenses'][4]], $this->listedIds('?category=none'));

        $this->actAs($world['b']);
        $foreign = [...$world['aCategories'], ...$world['aExpenses']];
        $queries = [
            '' => $world['bExpenses'],
            '?category=none' => [$world['bExpenses'][2]],
            '?q=' . $needle => [$world['bExpenses'][0]],
            '?category=' . $world['aCategories'][0] => [],
            '?category=' . $world['aCategories'][1] . '&q=' . $needle => [],
            '?category=' . $world['aCategories'][0] . '&q=' . $needle => [],
        ];

        self::assertSame([$world['bCategory']], $this->idsOf($this->call('GET', '/categories', '/categories', null), 'items'));
        foreach ($queries as $query => $expected) {
            $ids = $this->listedIds($query);
            self::assertSame([], array_values(array_intersect($ids, $foreign)), "GET /expenses$query leaks an id of the other user");
            self::assertEqualsCanonicalizing($expected, $ids, "GET /expenses$query");
        }
        self::assertSame(['items' => [], 'nextCursor' => null], $this->problemBody($this->listing(self::DATE_RANGE)), "A's October dates match nothing for B");
        $this->actAs($world['a']);
        self::assertEqualsCanonicalizing($world['aExpenses'], $this->listedIds(self::DATE_RANGE), 'The date window matches all of A, so the empty page of B means something');
        $this->actAs($world['b']);
        foreach (['', '&q=' . $needle] as $extra) {
            $foreignFilter = $this->fingerprint($this->listing('?category=' . $world['aCategories'][0] . $extra));
            $unknownFilter = $this->fingerprint($this->listing('?category=' . self::randomUuid() . $extra));
            self::assertSame($unknownFilter, $foreignFilter, "category=<A's> differs from category=<unknown> (with '$extra')");
        }
        $this->assertUnchanged($world);
    }

    public function testReplayingCursorsAcrossUsersStaysInTheCallersData(): void
    {
        $world = $this->world();
        $this->actAs($world['a']);
        $cursor = $this->cursorOf('?limit=' . self::PAGE_SIZE);
        $filteredCursor = $this->cursorOf('?category=' . $world['aCategories'][0] . '&limit=1');

        // The same filters (none) and the same limit, so the filter hash matches and only the session decides whose data it is.
        $this->actAs($world['b']);
        $replayed = $this->listing('?limit=' . self::PAGE_SIZE . '&cursor=' . rawurlencode($cursor));
        self::assertSame(200, $replayed->getStatusCode());
        // B's expenses are older than A's, so they all sort after A's cursor position: B's two newest come back.
        self::assertSame(array_slice($world['bExpenses'], 0, self::PAGE_SIZE), $this->idsOf($replayed, 'items'));

        // A cursor of A's filtered list, replayed by B with the same filters: an empty page, like the same filter without a cursor.
        $filtered = $this->listing('?category=' . $world['aCategories'][0] . '&limit=1&cursor=' . rawurlencode($filteredCursor));
        self::assertSame(200, $filtered->getStatusCode());
        self::assertSame(['items' => [], 'nextCursor' => null], $this->problemBody($filtered));
        self::assertSame($this->fingerprint($this->listing('?category=' . self::randomUuid() . '&limit=1')), $this->fingerprint($filtered));

        // B's own cursor does work: it reaches B's next item (so the replay above is a real position, not an ignored cursor).
        $own = $this->cursorOf('?limit=1');
        self::assertSame([$world['bExpenses'][1]], $this->idsOf($this->listing('?limit=1&cursor=' . rawurlencode($own)), 'items'));
        $this->assertUnchanged($world);
    }

    public function testCategoryNamesAreLockedPerUser(): void
    {
        $world = $this->world();
        $this->actAs($world['b']);
        [$first, $second] = $world['aNames'];
        $post = fn(string $name): ResponseInterface => $this->call('POST', '/categories', '/categories', ['name' => $name]);

        $sameName = $this->problemBody($post($first));
        $upperCase = $this->problemBody($post(mb_strtoupper($second)));
        self::assertSame(204, $this->call('DELETE', '/categories/{id}', '/categories/' . self::idOf($upperCase), null)->getStatusCode());
        $fresh = self::idOf($this->problemBody($post('B temp ' . bin2hex(random_bytes(4)))));
        $renamed = $this->call('PATCH', '/categories/{id}', '/categories/' . $fresh, ['name' => $second, 'version' => 1]);
        self::assertSame(200, $renamed->getStatusCode());
        // Move B's category away from A's name again: the release of the old lock must not touch A's lock.
        $away = $this->call('PATCH', '/categories/{id}', '/categories/' . $fresh, ['name' => 'B away ' . bin2hex(random_bytes(4)), 'version' => 2]);
        self::assertSame(200, $away->getStatusCode());
        foreach ([self::idOf($sameName), $fresh] as $id) {
            self::assertSame(204, $this->call('DELETE', '/categories/{id}', '/categories/' . $id, null)->getStatusCode());
        }

        // B's use and removal of the same names did not touch A's locks.
        $this->actAs($world['a']);
        self::assertSame(409, $post($first)->getStatusCode());
        self::assertSame(409, $post($second)->getStatusCode());
        $this->assertUnchanged($world);
    }

    public function testWithoutAValidCookieEverySecuredOperationIs401BeforeAnyNotFound(): void
    {
        $world = $this->world();

        foreach ([null, self::GARBAGE_COOKIE] as $cookie) {
            $this->withSessionCookie($cookie);
            foreach (self::SECURED_CALLS as $operationId => [$method, $template]) {
                foreach (self::hasRouteId($template) ? [self::randomUuid(), 'not-a-uuid'] : [''] as $id) {
                    $body = $this->anonymousBody($operationId);
                    $response = $this->call($method, $template, str_replace('{id}', $id, $template), $body);

                    self::assertSame(401, $response->getStatusCode(), "$operationId ($id) with cookie " . var_export($cookie, true));
                }
            }
        }
        $this->actAs($world['a']);
        $this->assertUnchanged($world);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function attemptBody(string $operationId, bool $stale, string $foreignCategoryId): ?array
    {
        $version = $stale ? self::STALE_VERSION : 1;

        return match ($operationId) {
            'updateExpense' => ['version' => $version, 'amountCents' => 1] + ($stale ? ['categoryId' => $foreignCategoryId] : []),
            'renameCategory' => ['name' => 'Hijacked', 'version' => $version],
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function anonymousBody(string $operationId): ?array
    {
        return match ($operationId) {
            'createCategory' => ['name' => 'Anon'],
            'createExpense' => ['date' => '2026-10-01', 'amountCents' => 1, 'description' => 'Anon', 'categoryId' => self::randomUuid()],
            'renameCategory' => ['name' => 'Anon', 'version' => 1],
            'updateExpense' => ['version' => 1, 'amountCents' => 1],
            default => null,
        };
    }

    /**
     * Registers A and B and creates the data: A has 2 categories and 5 expenses (October 2026, the last one
     * Uncategorized), B has 1 category and 3 older expenses (September 2026, the last one Uncategorized). The first
     * expense of each carries the needle in its description. The expense lists are newest first.
     *
     * @return World
     */
    private function world(): array
    {
        $needle = 'iso-needle-' . bin2hex(random_bytes(8));
        $a = $this->register();
        $aNames = ['A food ' . bin2hex(random_bytes(4)), 'A travel ' . bin2hex(random_bytes(4))];
        $aCategories = array_map(fn(string $name): string => $this->createId('/categories', ['name' => $name]), $aNames);
        $aExpenses = [];
        foreach (['24', '23', '22', '21', '20'] as $index => $day) {
            $body = ['date' => '2026-10-' . $day, 'amountCents' => 1000 + $index, 'description' => $index === 0 ? "A lunch $needle" : "A item $index"];
            $aExpenses[] = $this->createId('/expenses', $index < 4 ? $body + ['categoryId' => $aCategories[$index % 2]] : $body);
        }
        $snapshot = $this->snapshot($aExpenses);

        $b = $this->register();
        $bCategory = $this->createId('/categories', ['name' => 'B only ' . bin2hex(random_bytes(4))]);
        $bExpenses = [];
        foreach (['03', '02', '01'] as $index => $day) {
            $body = ['date' => '2026-09-' . $day, 'amountCents' => 500 + $index, 'description' => $index === 0 ? "B lunch $needle" : "B item $index"];
            $bExpenses[] = $this->createId('/expenses', $index < 2 ? $body + ['categoryId' => $bCategory] : $body);
        }

        return [
            'a' => $a, 'b' => $b, 'aNames' => $aNames, 'aCategories' => $aCategories, 'aExpenses' => $aExpenses,
            'bCategory' => $bCategory, 'bExpenses' => $bExpenses, 'needle' => $needle, 'snapshot' => $snapshot,
        ];
    }

    /**
     * @param list<string> $expenses
     *
     * @return array<string, string> the raw bodies, by path
     */
    private function snapshot(array $expenses): array
    {
        $bodies = ['/categories' => (string) $this->call('GET', '/categories', '/categories', null)->getBody()];
        foreach ($expenses as $id) {
            $bodies['/expenses/' . $id] = (string) $this->call('GET', '/expenses/{id}', '/expenses/' . $id, null)->getBody();
        }
        $bodies['/expenses'] = (string) $this->listing('')->getBody();

        return $bodies;
    }

    /**
     * A's categories, expenses and lists are byte for byte what they were before B's attempts (so `version` too).
     *
     * @param World $world
     */
    private function assertUnchanged(array $world): void
    {
        $this->actAs($world['a']);
        foreach ($world['snapshot'] as $path => $before) {
            $template = str_starts_with($path, '/expenses/') ? '/expenses/{id}' : $path;
            self::assertSame($before, (string) $this->call('GET', $template, $path, null)->getBody(), "A's $path changed");
        }
    }

    private function register(): string
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'iso-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);

        return $cookie;
    }

    private function actAs(string $cookie): void
    {
        $this->withSessionCookie($cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createId(string $path, array $body): string
    {
        $response = $this->call('POST', $path, $path, $body);
        self::assertSame(201, $response->getStatusCode());

        return self::idOf($this->problemBody($response));
    }

    /**
     * @param array<mixed> $resource
     */
    private static function idOf(array $resource): string
    {
        self::assertIsString($resource['id'] ?? null);

        return $resource['id'];
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $template, string $path, ?array $body): ResponseInterface
    {
        $options = $body === null ? [] : ['headers' => self::JSON, 'body' => json_encode($body, JSON_THROW_ON_ERROR)];

        return $this->requestAs($method, explode('?', $template, 2)[0], $method, '/api/v1' . $path, $options);
    }

    /**
     * @param string $query empty, or `?a=b&c=d` with the values already encoded
     */
    private function listing(string $query): ResponseInterface
    {
        return $this->call('GET', '/expenses', '/expenses' . $query, null);
    }

    /**
     * @return list<string> the ids of one page of GET /expenses
     */
    private function listedIds(string $query): array
    {
        return $this->idsOf($this->listing($query), 'items');
    }

    private function cursorOf(string $query): string
    {
        $cursor = $this->problemBody($this->listing($query))['nextCursor'] ?? null;
        self::assertIsString($cursor, "GET /expenses$query has no next page");

        return $cursor;
    }

    /**
     * Status, headers (all but those in PER_REQUEST_HEADERS) and body of a response, for a byte-for-byte comparison.
     *
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     */
    private function fingerprint(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            if (!in_array(strtolower((string) $name), self::PER_REQUEST_HEADERS, true)) {
                $headers[strtolower((string) $name)] = array_values($values);
            }
        }
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'headers' => $headers, 'body' => (string) $response->getBody()];
    }

    /**
     * @return list<string> the pointers of a 422
     */
    private function pointersOf(ResponseInterface $response): array
    {
        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);

        return array_values(array_map(static fn(mixed $error): string => is_array($error) && is_string($error['pointer'] ?? null) ? $error['pointer'] : '', $errors));
    }

    /**
     * @return list<string> the ids of a 200 list under `$key`
     */
    private function idsOf(ResponseInterface $response, string $key): array
    {
        self::assertSame(200, $response->getStatusCode());
        $items = $this->problemBody($response)[$key] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(static fn(mixed $item): string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '', $items));
    }

    /**
     * Whether the path template takes a route id. A template with another parameter fails the test, so a new kind of
     * parameter is never skipped silently.
     */
    private static function hasRouteId(string $template): bool
    {
        preg_match_all('/\{([^}]+)\}/', $template, $matches);
        foreach ($matches[1] as $name) {
            if ($name !== 'id') {
                self::fail("Unsupported path parameter {{$name}} in $template: add it to ID_ATTEMPTS and to the substitution of the tests.");
            }
        }

        return $matches[1] !== [];
    }

    private static function randomUuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
