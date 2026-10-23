<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Category;
use App\Categories\Http\CreateCategoryHandler;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\IdGenerator;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateCategoryHandlerTest extends TestCase
{
    private const NEW_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const OTHER_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const PATH = '/api/v1/categories';
    private const LIMIT_DETAIL = 'You already have the maximum of 100 categories. Delete one first.';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function handler(): CreateCategoryHandler
    {
        $ids = new class (self::NEW_ID) implements IdGenerator {
            public function __construct(private readonly string $id) {}

            public function generate(): string
            {
                return $this->id;
            }
        };

        return new CreateCategoryHandler($this->store->repository(), $ids, new FrozenClock('2026-10-23T11:00:00+00:00'));
    }

    private function post(string $body = '{"name":"Food"}', string $contentType = 'application/json'): \App\Http\Response
    {
        return $this->store->serve($this->handler(), Store::request('POST', self::PATH, $body, Store::owner(), contentType: $contentType));
    }

    /**
     * @return list<Category> `$count` categories with invented, distinct ids and names
     */
    private static function categories(int $count): array
    {
        $list = [];
        for ($number = 1; $number <= $count; ++$number) {
            $list[] = Store::category(sprintf('00000000-0000-4000-8000-%012d', $number), 'Category ' . $number);
        }

        return $list;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(\App\Http\Response $response): array
    {
        $body = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    public function testUnderTheCapItCreatesAndAnswers201WithLocation(): void
    {
        $this->store->answer(Store::listing(self::categories(5)), Store::ok(), Store::listing(self::categories(6)));

        $response = $this->post();

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/categories/' . self::NEW_ID], $response->headerValues('Location'));
        self::assertSame(['id', 'name', 'version', 'createdAt', 'updatedAt'], array_keys(self::decoded($response)));
        self::assertSame(self::NEW_ID, self::decoded($response)['id']);
        self::assertSame(1, self::decoded($response)['version']);
        self::assertSame('2026-10-23T11:00:00Z', self::decoded($response)['createdAt']);
        self::assertSame(['Query', 'TransactWriteItems', 'Query'], $this->store->sent());
    }

    public function testTheHundredthCategoryIsStillAllowed(): void
    {
        $this->store->answer(Store::listing(self::categories(99)), Store::ok(), Store::listing(self::categories(100)));

        self::assertSame(201, $this->post()->status);
        self::assertSame(['Query', 'TransactWriteItems', 'Query'], $this->store->sent());
    }

    public function testAtTheCapItIsA409AndNothingIsWritten(): void
    {
        $this->store->answer(Store::listing(self::categories(100)));

        $response = $this->post('{"name":"secret-marker"}');

        self::assertSame(409, $response->status);
        self::assertSame(self::LIMIT_DETAIL, self::decoded($response)['detail']);
        self::assertStringNotContainsString('secret-marker', $response->body);
        self::assertSame(['Query'], $this->store->sent());
    }

    public function testWhenARaceLeavesMoreThanTheCapItBacksOutWithExactlyOneDeleteOfItsOwnCategory(): void
    {
        // The first read sees 99; by the second read parallel creates have made 101 (ours included).
        $this->store->answer(Store::listing(self::categories(99)), Store::ok(), Store::listing(self::categories(101)), Store::ok());

        $response = $this->post();

        self::assertSame(409, $response->status);
        self::assertSame(self::LIMIT_DETAIL, self::decoded($response)['detail']);
        self::assertArrayNotHasKey('Location', $response->headers);
        self::assertSame(['Query', 'TransactWriteItems', 'Query', 'TransactWriteItems'], $this->store->sent());
        /** @var list<array{Delete?: array{Key: array{SK: array{S: string}}}}> $items */
        $items = $this->store->command(3)['TransactItems'];
        self::assertCount(2, $items);
        self::assertSame('CAT#' . self::NEW_ID, $items[0]['Delete']['Key']['SK']['S'] ?? null);
    }

    public function testTheSecondCountIsAFreshReadNotTheMemoisedList(): void
    {
        // The first list has 99 items, the recount 101: a memoised recount would count 99 and answer 201.
        $this->store->answer(Store::listing(self::categories(99)), Store::ok(), Store::listing(self::categories(101)), Store::ok());

        $response = $this->post();

        self::assertSame(409, $response->status);
        self::assertSame(['Query', 'TransactWriteItems', 'Query', 'TransactWriteItems'], $this->store->sent());
        /** @var array{':prefix': array{S: string}} $values */
        $values = $this->store->command(2)['ExpressionAttributeValues'];
        self::assertSame('CAT#', $values[':prefix']['S']);
    }

    public function testTheCompensatingDeleteIsConditionalOnTheVersionAndReleasesTheLockOfTheCreatedName(): void
    {
        $this->store->answer(Store::listing(self::categories(99)), Store::ok(), Store::listing(self::categories(101)), Store::ok());
        $nameKey = Store::category(self::NEW_ID, 'Food')->nameKey;

        $this->post('{"name":"Food"}');

        /** @var list<array{Delete: array{ConditionExpression: string, Key: array{SK: array{S: string}}, ExpressionAttributeValues: array<string, array{S?: string, N?: string}>}}> $items */
        $items = $this->store->command(3)['TransactItems'];
        self::assertCount(2, $items);
        self::assertStringContainsString('#version = :version', $items[0]['Delete']['ConditionExpression']);
        self::assertSame('1', $items[0]['Delete']['ExpressionAttributeValues'][':version']['N'] ?? null);
        self::assertSame('CATNAME#' . $nameKey, $items[1]['Delete']['Key']['SK']['S']);
        self::assertSame(self::NEW_ID, $items[1]['Delete']['ExpressionAttributeValues'][':id']['S'] ?? null);
    }

    public function testIfTheCreatedCategoryIsAlreadyGoneTheBackOutIsStillA409(): void
    {
        $this->store->answer(
            Store::listing(self::categories(99)),
            Store::ok(),
            Store::listing(self::categories(101)),
            Store::cancelled(['ConditionalCheckFailed', 'None']),
            Store::missing(),
        );

        $response = $this->post();

        self::assertSame(409, $response->status);
        self::assertSame(self::LIMIT_DETAIL, self::decoded($response)['detail']);
    }

    public function testIfTheCompensatingDeleteFailsTheFailureIsNotSwallowed(): void
    {
        $this->store->answer(
            Store::listing(self::categories(99)),
            Store::ok(),
            Store::listing(self::categories(101)),
            Store::cancelled(['TransactionConflict', 'None']),
        );

        $response = $this->post();

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
    }

    public function testACompensatingDeleteFailureThatIsNotOverloadIsAGeneric500(): void
    {
        $this->store->answer(
            Store::listing(self::categories(99)),
            Store::ok(),
            Store::listing(self::categories(101)),
            Store::cancelled(['ValidationError', 'None']),
        );

        $response = $this->post();

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('Cancelled', $response->body);
    }

    public function testTheCompensatingDeleteExceptionReachesTheCaller(): void
    {
        $this->store->answer(Store::listing(self::categories(99)), Store::ok(), Store::listing(self::categories(101)), Store::cancelled(['ThrottlingError', 'None']));

        $this->expectException(TransactionCancelled::class);

        $this->handler()->handle(Store::request('POST', self::PATH, '{"name":"Food"}', Store::owner()));
    }

    public function testAFailedSecondCountIsNotSwallowed(): void
    {
        $this->store->answer(Store::listing(self::categories(3)), Store::ok());
        $this->store->answerThrottled();

        self::assertSame(503, $this->post()->status);
    }

    public function testANameThatIsTakenIsA409AndNeverEchoesTheName(): void
    {
        $this->store->answer(
            Store::listing(self::categories(2)),
            Store::cancelled(['None', 'ConditionalCheckFailed']),
            Store::lockHeldBy(self::OTHER_ID),
            Store::found(Store::category(self::OTHER_ID, 'food')),
        );

        $response = $this->post('{"name":"Food"}');

        self::assertSame(409, $response->status);
        self::assertSame('A category with this name already exists.', self::decoded($response)['detail']);
        self::assertStringNotContainsString('Food', $response->body);
        self::assertStringNotContainsString(self::NEW_ID, $response->body);
    }

    public function testThrottlingOnTheCreateTransactionIsA503(): void
    {
        $this->store->answer(Store::listing([]), Store::cancelled(['ThrottlingError', 'None']));

        $response = $this->post();

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
        self::assertSame(['Query', 'TransactWriteItems'], $this->store->sent());
    }

    public function testThrottlingOnTheFirstCountIsA503AndNothingIsWritten(): void
    {
        $this->store->answerThrottled();

        self::assertSame(503, $this->post()->status);
        self::assertNotContains('TransactWriteItems', $this->store->sent());
    }

    public function testAConditionFailureThatIsNotTheNameLockIsNotAConflict(): void
    {
        $this->store->answer(Store::listing([]), Store::cancelled(['ConditionalCheckFailed', 'None']));

        $response = $this->post();

        self::assertSame(500, $response->status);
        self::assertSame(['Query', 'TransactWriteItems'], $this->store->sent());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        try {
            $this->handler()->handle(Store::request('POST', self::PATH, '{"name":"Food"}'));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }

    public function testTheBodyIsCheckedBeforeAnythingIsRead(): void
    {
        $unsupported = $this->post('{"name":"Food"}', 'text/plain');
        $notJson = $this->post('{nope');
        $notAnObject = $this->post('[]');

        self::assertSame(415, $unsupported->status);
        self::assertSame(400, $notJson->status);
        self::assertSame(400, $notAnObject->status);
        self::assertSame([], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'a number' => ['{"name":5}', '/name'];
        yield 'null' => ['{"name":null}', '/name'];
        yield 'missing' => ['{}', '/name'];
        yield 'blank' => ['{"name":"  "}', '/name'];
        yield 'too long' => ['{"name":"' . str_repeat('a', 51) . '"}', '/name'];
        yield 'unknown member' => ['{"name":"Food","colour":"red"}', '/colour'];
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs422AtTheRightPointerWithZeroReads(string $body, string $pointer): void
    {
        $response = $this->post($body);

        self::assertSame(422, $response->status);
        $errors = self::decoded($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame($pointer, $errors[0]['pointer'] ?? null);
        self::assertSame([], $this->store->sent());
    }
}
