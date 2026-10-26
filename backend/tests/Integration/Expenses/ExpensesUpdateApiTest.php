<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Auth\Session\Principal;
use App\Expenses\Expense;
use App\Expenses\ExpenseDate;
use App\Expenses\ExpenseRepository;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\LocalAws;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * `PATCH` and `DELETE /expenses/{id}` end to end (nginx -> php-fpm -> DynamoDB Local), every call through request() or
 * requestAs(), so each response is also checked against the bundled contract. `GET /expenses` arrives in a later work
 * package, so "the expense moved in the date range" is checked with {@see ExpenseRepository::pageByDate()} on the
 * same table. Each test registers its own users. Run through `make test-integration`.
 */
final class ExpensesUpdateApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const UNKNOWN_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const JSON = ['Content-Type' => 'application/json'];
    private const VALID = '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}';
    private const RACERS = 2;

    private function signedIn(): string
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'upd-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);

        return $cookie;
    }

    /**
     * @return array<mixed>
     */
    private function created(string $rawBody = self::VALID): array
    {
        $response = $this->request('POST', '/expenses', ['headers' => self::JSON, 'body' => $rawBody]);
        self::assertSame(201, $response->getStatusCode());

        return $this->problemBody($response);
    }

    private function createdId(string $rawBody = self::VALID): string
    {
        $id = $this->created($rawBody)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function patch(string $id, string $rawBody): ResponseInterface
    {
        return $this->requestAs('PATCH', '/expenses/{id}', 'PATCH', '/api/v1/expenses/' . $id, ['headers' => self::JSON, 'body' => $rawBody]);
    }

    private function read(string $id): ResponseInterface
    {
        return $this->requestAs('GET', '/expenses/{id}', 'GET', '/api/v1/expenses/' . $id);
    }

    private function remove(string $id): ResponseInterface
    {
        return $this->requestAs('DELETE', '/expenses/{id}', 'DELETE', '/api/v1/expenses/' . $id);
    }

    private function createCategory(string $name): string
    {
        $response = $this->request('POST', '/categories', ['headers' => self::JSON, 'body' => json_encode(['name' => $name], JSON_THROW_ON_ERROR)]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
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

    private static function items(): ItemStore
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);
        self::assertNotSame('', $table);

        return new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table);
    }

    /** @return list<Expense> the signed-in user's expenses from `$from` to `$to`, newest first, straight from the table */
    private function storedBetween(string $from, string $to): array
    {
        $me = $this->problemBody($this->request('GET', '/auth/me'));
        self::assertIsString($me['id'] ?? null);
        $first = ExpenseDate::parse($from);
        $last = ExpenseDate::parse($to);
        self::assertInstanceOf(ExpenseDate::class, $first);
        self::assertInstanceOf(ExpenseDate::class, $last);

        return (new ExpenseRepository(self::items()))->pageByDate(new Principal($me['id'], str_repeat('a', 64)), $first, $last, null, 100)->items;
    }

    public function testAPatchAnswers200WithTheNextVersionAndAGetReadsTheSameBody(): void
    {
        $this->signedIn();
        $id = $this->createdId();

        $response = $this->patch($id, '{"version":1,"amountCents":990,"description":"  Dinner "}');
        $body = $this->problemBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'], array_keys($body));
        self::assertSame(990, $body['amountCents']);
        self::assertSame('Dinner', $body['description']);
        self::assertSame('2026-10-20', $body['date']);
        self::assertSame(2, $body['version']);
        self::assertSame($body, $this->problemBody($this->read($id)));
    }

    public function testACategoryCanBeSetKeptAndRemoved(): void
    {
        $this->signedIn();
        $food = $this->createCategory('Food');
        $id = $this->createdId();

        $set = $this->problemBody($this->patch($id, '{"version":1,"categoryId":"' . $food . '"}'));
        $kept = $this->problemBody($this->patch($id, '{"version":2,"amountCents":5}'));
        $removed = $this->problemBody($this->patch($id, '{"version":3,"categoryId":null}'));

        self::assertSame($food, $set['categoryId']);
        self::assertSame($food, $kept['categoryId']);
        self::assertNull($removed['categoryId']);
        self::assertNull($this->problemBody($this->read($id))['categoryId']);
        self::assertSame(4, $removed['version']);
    }

    public function testALeftoverCategoryIdIsReplacedByNullOnTheNextPatch(): void
    {
        $this->signedIn();
        $food = $this->createCategory('Food');
        $id = $this->createdId('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . $food . '"}');
        self::assertSame(204, $this->requestAs('DELETE', '/categories/{id}', 'DELETE', '/api/v1/categories/' . $food)->getStatusCode());

        $patched = $this->problemBody($this->patch($id, '{"version":1,"amountCents":5}'));

        self::assertNull($patched['categoryId']);
        self::assertNull($this->problemBody($this->read($id))['categoryId']);
    }

    public function testADateChangeMovesTheExpenseInTheDateRange(): void
    {
        $this->signedIn();
        $id = $this->createdId();
        $before = array_map(static fn(Expense $e): string => $e->id, $this->storedBetween('2026-10-01', '2026-10-31'));
        self::assertSame([$id], $before);

        $response = $this->patch($id, '{"version":1,"date":"2026-11-02"}');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $this->storedBetween('2026-10-01', '2026-10-31'));
        $after = $this->storedBetween('2026-11-01', '2026-11-30');
        self::assertCount(1, $after);
        self::assertSame($id, $after[0]->id);
        self::assertSame('2026-11-02', $after[0]->date->value());
        self::assertSame(2, $after[0]->version);
    }

    public function testTwoPatchesWithTheSameVersionGiveOne200AndOne409AndTheVersionGrowsByOne(): void
    {
        $this->signedIn();
        $id = $this->createdId();
        $calls = [];
        foreach ([111, 222] as $cents) {
            $calls[] = ['PATCH', '/expenses/' . $id, ['headers' => self::JSON, 'body' => '{"version":1,"amountCents":' . $cents . '}']];
        }

        $responses = $this->requestConcurrently($calls, self::RACERS);

        $statuses = array_map(static fn(ResponseInterface $r): int => $r->getStatusCode(), $responses);
        sort($statuses);
        self::assertSame([200, 409], $statuses);
        $stored = $this->problemBody($this->read($id));
        self::assertSame(2, $stored['version']);
        foreach ($responses as $response) {
            if ($response->getStatusCode() === 200) {
                self::assertSame($stored, $this->problemBody($response));
            }
        }
    }

    public function testAStaleVersionIs409AndChangesNothing(): void
    {
        $this->signedIn();
        $id = $this->createdId();
        self::assertSame(200, $this->patch($id, '{"version":1,"amountCents":5}')->getStatusCode());

        $stale = $this->patch($id, '{"version":1,"amountCents":9}');

        self::assertSame(409, $stale->getStatusCode());
        $stored = $this->problemBody($this->read($id));
        self::assertSame(5, $stored['amountCents']);
        self::assertSame(2, $stored['version']);
    }

    public function testDeleteAnswers204ThenGetAndASecondDeleteAnd404(): void
    {
        $this->signedIn();
        $id = $this->createdId();

        $deleted = $this->remove($id);

        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', (string) $deleted->getBody());
        self::assertSame(404, $this->read($id)->getStatusCode());
        self::assertSame(404, $this->remove($id)->getStatusCode());
        self::assertSame(404, $this->patch($id, '{"version":1,"amountCents":5}')->getStatusCode());
    }

    public function testAnotherUsersCategoryIs422AtCategoryIdAndNothingChanges(): void
    {
        $this->signedIn();
        $foreign = $this->createCategory('Food');
        $this->signedIn();
        $id = $this->createdId();

        $response = $this->patch($id, '{"version":1,"categoryId":"' . $foreign . '"}');

        self::assertSame(['/categoryId'], $this->pointersOf($response));
        self::assertStringNotContainsString($foreign, (string) $response->getBody());
        self::assertSame(1, $this->problemBody($this->read($id))['version']);
    }

    public function testAnotherUsersExpenseCannotBePatchedOrDeleted(): void
    {
        $ownerCookie = $this->signedIn();
        $mine = $this->createdId();
        $this->signedIn();
        $unknown = $this->patch(self::UNKNOWN_ID, '{"version":1,"amountCents":5}');

        $patched = $this->patch($mine, '{"version":1,"amountCents":5}');
        $deleted = $this->remove($mine);
        $unknownDelete = $this->remove(self::UNKNOWN_ID);

        self::assertSame(404, $patched->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $patched->getBody());
        self::assertSame(404, $deleted->getStatusCode());
        self::assertSame((string) $unknownDelete->getBody(), (string) $deleted->getBody());
        $this->withSessionCookie($ownerCookie);
        $stored = $this->read($mine);
        self::assertSame(200, $stored->getStatusCode());
        self::assertSame(1, $this->problemBody($stored)['version']);
    }

    public function testAMalformedIdIsTheSame404ForPatchAndDelete(): void
    {
        $this->signedIn();
        $unknown = $this->remove(self::UNKNOWN_ID);

        $patched = $this->patch('not-a-uuid', '{}');
        $deleted = $this->remove('not-a-uuid');

        self::assertSame(404, $patched->getStatusCode());
        self::assertSame(404, $deleted->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $deleted->getBody());
        self::assertSame((string) $unknown->getBody(), (string) $patched->getBody());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badPatches(): iterable
    {
        yield 'version alone' => ['{"version":1}', ''];
        yield 'description null' => ['{"version":1,"description":null}', '/description'];
        yield 'date null' => ['{"version":1,"date":null}', '/date'];
        yield 'amount null' => ['{"version":1,"amountCents":null}', '/amountCents'];
        yield 'amount float' => ['{"version":1,"amountCents":12.0}', '/amountCents'];
        yield 'amount beyond 64 bits' => ['{"version":1,"amountCents":99999999999999999999}', '/amountCents'];
        yield 'version missing' => ['{"amountCents":5}', '/version'];
        yield 'version zero' => ['{"version":0,"amountCents":5}', '/version'];
        yield 'version as a string' => ['{"version":"1","amountCents":5}', '/version'];
        yield 'category as a number' => ['{"version":1,"categoryId":5}', '/categoryId'];
        yield 'unknown member' => ['{"version":1,"amountCents":5,"id":"x"}', '/id'];
    }

    #[DataProvider('badPatches')]
    public function testABadPatchIs422AtTheRightPointerAndChangesNothing(string $body, string $pointer): void
    {
        $this->signedIn();
        $id = $this->createdId();

        $response = $this->patch($id, $body);

        self::assertSame([$pointer], $this->pointersOf($response));
        self::assertSame(1, $this->problemBody($this->read($id))['version']);
    }

    public function testAnEmptyObjectIs422AtVersionAndAtTheRoot(): void
    {
        $this->signedIn();
        $id = $this->createdId();

        self::assertSame(['/version', ''], $this->pointersOf($this->patch($id, '{}')));
    }

    public function testATextPlainBodyIs415AndANonObjectIs400(): void
    {
        $this->signedIn();
        $id = $this->createdId();

        $plain = $this->requestAs('PATCH', '/expenses/{id}', 'PATCH', '/api/v1/expenses/' . $id, ['headers' => ['Content-Type' => 'text/plain'], 'body' => '{"version":1,"amountCents":5}']);

        self::assertSame(415, $plain->getStatusCode());
        self::assertSame(400, $this->patch($id, '[]')->getStatusCode());
    }

    public function testWithoutACookieBothOperationsAre401EvenForAMalformedId(): void
    {
        $this->withSessionCookie(null);

        self::assertSame(401, $this->patch(self::UNKNOWN_ID, '{"version":1,"amountCents":5}')->getStatusCode());
        self::assertSame(401, $this->patch('not-a-uuid', '{}')->getStatusCode());
        self::assertSame(401, $this->remove(self::UNKNOWN_ID)->getStatusCode());
        self::assertSame(401, $this->remove('not-a-uuid')->getStatusCode());
    }

    public function testWithoutRequestedWithBothOperationsAre403(): void
    {
        $this->signedIn();
        $id = $this->createdId();
        $this->withoutRequestedWith();

        self::assertSame(403, $this->patch($id, '{"version":1,"amountCents":5}')->getStatusCode());
        self::assertSame(403, $this->remove($id)->getStatusCode());
    }
}
