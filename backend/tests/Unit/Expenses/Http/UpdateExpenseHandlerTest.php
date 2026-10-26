<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Expense;
use App\Expenses\Http\UpdateExpenseHandler;
use App\Http\JsonBody;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\ScriptedCategoryStore as Store;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpdateExpenseHandlerTest extends TestCase
{
    private const FOOD = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const TRAVEL = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';
    private const NOW = '2026-10-23T11:00:00+00:00';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function handler(): UpdateExpenseHandler
    {
        return new UpdateExpenseHandler($this->store->expenses(), $this->store->repository(), new FrozenClock(self::NOW));
    }

    private function patch(string $body, ?string $id = null, string $contentType = 'application/json'): Response
    {
        $id ??= ExpenseBuilder::uuid(3);

        return $this->store->serve(
            $this->handler(),
            Store::request('PATCH', '/api/v1/expenses/' . $id, $body, Store::owner(), ['id' => $id], $contentType),
        );
    }

    private static function current(?string $categoryId = null): Expense
    {
        return ExpenseBuilder::expense(3, '2026-10-20', 'Lunch', $categoryId, null, 1250);
    }

    /** What the repository would answer for a change of the current expense: its next version. */
    private static function next(Expense $current, string $date, int $cents, string $text, ?string $categoryId): Expense
    {
        $changes = ExpenseBuilder::expense(1, $date, $text, null, null, $cents);

        return $current->updated($changes->date, $changes->amount, $changes->description, $categoryId, new DateTimeImmutable(self::NOW));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function decoded(Response $response): array
    {
        $body = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        return $body;
    }

    private static function pointerOf(Response $response): mixed
    {
        $errors = self::decoded($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);

        return $errors[0]['pointer'] ?? null;
    }

    /**
     * @return array{UpdateExpression: string, ExpressionAttributeValues: array<string, array<string, string>>}
     */
    private function update(int $index): array
    {
        /** @var array{UpdateExpression: string, ExpressionAttributeValues: array<string, array<string, string>>} $command */
        $command = $this->store->command($index)->toArray();

        return $command;
    }

    public function testAnAmountOnlyPatchKeepsTheOtherFieldsAndAnswersTheNextVersion(): void
    {
        $current = self::current();
        $this->store->answer(Store::expenseFound($current), Store::expenseUpdated(self::next($current, '2026-10-20', 77, 'Lunch', null)));

        $response = $this->patch('{"version":1,"amountCents":77}');

        self::assertSame(200, $response->status);
        self::assertSame(
            ['id' => ExpenseBuilder::uuid(3), 'date' => '2026-10-20', 'amountCents' => 77, 'description' => 'Lunch', 'categoryId' => null, 'version' => 2, 'createdAt' => '2026-10-23T10:00:00Z', 'updatedAt' => '2026-10-23T11:00:00Z'],
            self::decoded($response),
        );
        self::assertSame(['GetItem', 'UpdateItem'], $this->store->sent());
        $values = $this->update(1)['ExpressionAttributeValues'];
        self::assertSame(['N' => '77'], $values[':amountCents']);
        self::assertSame(['S' => '2026-10-20'], $values[':date']);
        self::assertSame(['S' => 'Lunch'], $values[':description']);
        self::assertSame(['N' => '1'], $values[':expected']);
        self::assertSame(['N' => '2'], $values[':next']);
    }

    public function testADateChangeWritesTheNewDateAndTheNewIndexKey(): void
    {
        $current = self::current();
        $this->store->answer(Store::expenseFound($current), Store::expenseUpdated(self::next($current, '2026-11-02', 1250, 'Lunch', null)));

        $response = $this->patch('{"version":1,"date":"2026-11-02"}');

        self::assertSame(200, $response->status);
        $values = $this->update(1)['ExpressionAttributeValues'];
        self::assertSame(['S' => '2026-11-02'], $values[':date']);
        self::assertSame(['S' => 'EXP#2026-11-02#' . ExpenseBuilder::uuid(3)], $values[':lsi1sk']);
    }

    public function testADescriptionIsTrimmedAndWritten(): void
    {
        $current = self::current();
        $this->store->answer(Store::expenseFound($current), Store::expenseUpdated(self::next($current, '2026-10-20', 1250, 'Dinner', null)));

        $this->patch('{"version":1,"description":"  Dinner "}');

        self::assertSame(['S' => 'Dinner'], $this->update(1)['ExpressionAttributeValues'][':description']);
    }

    public function testSettingACategoryOfTheCallerChecksItOnceAndEchoesIt(): void
    {
        $current = self::current();
        $this->store->answer(
            Store::expenseFound($current),
            Store::listing([Store::category(self::FOOD, 'Food')]),
            Store::expenseUpdated(self::next($current, '2026-10-20', 1250, 'Lunch', self::FOOD)),
        );

        $response = $this->patch('{"version":1,"categoryId":"' . self::FOOD . '"}');

        self::assertSame(200, $response->status);
        self::assertSame(self::FOOD, self::decoded($response)['categoryId']);
        self::assertSame(['GetItem', 'Query', 'UpdateItem'], $this->store->sent());
        self::assertSame(['S' => self::FOOD], $this->update(2)['ExpressionAttributeValues'][':categoryId']);
    }

    public function testANullCategoryRemovesTheCategoryWithoutAnyCategoryRead(): void
    {
        $current = self::current(self::FOOD);
        $this->store->answer(Store::expenseFound($current), Store::expenseUpdated(self::next($current, '2026-10-20', 1250, 'Lunch', null)));

        $response = $this->patch('{"version":1,"categoryId":null}');

        self::assertSame(200, $response->status);
        self::assertNull(self::decoded($response)['categoryId']);
        self::assertSame(['GetItem', 'UpdateItem'], $this->store->sent());
        self::assertStringContainsString('REMOVE #categoryId', $this->update(1)['UpdateExpression']);
    }

    public function testAnExistingCategoryIsKeptWhenThePatchDoesNotSetOne(): void
    {
        $current = self::current(self::FOOD);
        $this->store->answer(
            Store::expenseFound($current),
            Store::listing([Store::category(self::FOOD, 'Food')]),
            Store::expenseUpdated(self::next($current, '2026-10-20', 5, 'Lunch', self::FOOD)),
        );

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(self::FOOD, self::decoded($response)['categoryId']);
        self::assertSame(['S' => self::FOOD], $this->update(2)['ExpressionAttributeValues'][':categoryId']);
    }

    public function testALeftoverCategoryIdIsReplacedByNullWhenThePatchDoesNotSetOne(): void
    {
        $current = self::current(self::FOOD);
        $this->store->answer(
            Store::expenseFound($current),
            Store::listing([Store::category(self::TRAVEL, 'Travel')]),
            Store::expenseUpdated(self::next($current, '2026-10-20', 5, 'Lunch', null)),
        );

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(200, $response->status);
        self::assertNull(self::decoded($response)['categoryId']);
        $update = $this->update(2);
        self::assertStringContainsString('REMOVE #categoryId', $update['UpdateExpression']);
        self::assertArrayNotHasKey(':categoryId', $update['ExpressionAttributeValues']);
    }

    public function testACategoryTheCallerDoesNotHaveIs422AtCategoryIdWithNoWriteAndNoEcho(): void
    {
        $this->store->answer(Store::expenseFound(self::current()), Store::listing([Store::category(self::TRAVEL, 'Travel')]));

        $response = $this->patch('{"version":1,"categoryId":"' . self::FOOD . '"}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertStringNotContainsString(self::FOOD, $response->body);
        self::assertSame(['GetItem', 'Query'], $this->store->sent());
    }

    public function testAMalformedCategoryIdIs422AtCategoryIdWithNoCategoryReadAndNoWrite(): void
    {
        $this->store->answer(Store::expenseFound(self::current()));

        $response = $this->patch('{"version":1,"categoryId":"not-a-uuid"}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertSame(['GetItem'], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonStringCategories(): iterable
    {
        yield 'a number' => ['5'];
        yield 'a list' => ['["' . self::FOOD . '"]'];
        yield 'true' => ['true'];
        yield 'an object' => ['{}'];
    }

    #[DataProvider('nonStringCategories')]
    public function testANonStringCategoryIdIs422BeforeAnyStorageCall(string $json): void
    {
        $response = $this->patch('{"version":1,"categoryId":' . $json . '}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertSame([], $this->store->sent());
    }

    public function testAStorageFailureFromTheCategoryReadEndsTheRequestWithNoWrite(): void
    {
        $this->store->answer(Store::expenseFound(self::current()));
        $this->store->answerThrottled();

        $response = $this->patch('{"version":1,"categoryId":"' . self::FOOD . '"}');

        self::assertSame(503, $response->status);
        self::assertNotContains('UpdateItem', $this->store->sent());
    }

    public function testAStorageFailureFromTheLeftoverCheckEndsTheRequestWithNoWrite(): void
    {
        $this->store->answer(Store::expenseFound(self::current(self::FOOD)));
        $this->store->answerThrottled();

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(503, $response->status);
        self::assertNotContains('UpdateItem', $this->store->sent());
    }

    public function testAStorageFailureReachesTheMiddlewareAsAnException(): void
    {
        $this->store->answer(Store::expenseFound(self::current(self::FOOD)));
        $this->store->answerThrottled();

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(Store::request('PATCH', '/x', '{"version":1,"amountCents":5}', Store::owner(), ['id' => ExpenseBuilder::uuid(3)]));
    }

    private static function atVersion3(?string $categoryId = null): Expense
    {
        $once = self::current($categoryId)->updated(self::current()->date, self::current()->amount, self::current()->description, $categoryId, new DateTimeImmutable(self::NOW));

        return $once->updated($once->date, $once->amount, $once->description, $categoryId, new DateTimeImmutable(self::NOW));
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function staleVersions(): iterable
    {
        yield 'behind' => [2];
        yield 'ahead' => [4];
    }

    #[DataProvider('staleVersions')]
    public function testAStaleVersionIs409WithNoWriteAndNoEcho(int $sent): void
    {
        $this->store->answer(Store::expenseFound(self::atVersion3()));

        $response = $this->patch('{"version":' . $sent . ',"amountCents":5}');

        self::assertSame(409, $response->status);
        self::assertStringContainsString('The expense changed since you loaded it. Reload it and try again.', $response->body);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAStaleVersionIs409BeforeAnyCategoryReadEvenWhenTheExpenseHasACategory(): void
    {
        $this->store->answer(Store::expenseFound(self::atVersion3(self::FOOD)));

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(409, $response->status);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAStaleVersionWithAForeignCategoryIdIs409NotA422(): void
    {
        $this->store->answer(Store::expenseFound(self::atVersion3()));

        $response = $this->patch('{"version":1,"categoryId":"' . self::TRAVEL . '"}');

        self::assertSame(409, $response->status);
        self::assertStringNotContainsString(self::TRAVEL, $response->body);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAnUpperCaseCategoryIdWithTheRightVersionIs422AtCategoryIdWithNoWrite(): void
    {
        $this->store->answer(Store::expenseFound(self::current()));

        $response = $this->patch('{"version":1,"categoryId":"' . strtoupper(self::FOOD) . '"}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertNotContains('UpdateItem', $this->store->sent());
    }

    public function testTheWriteCarriesTheClockTimeAndTheAnswerIsWhatWasSaved(): void
    {
        $current = self::current();
        $changes = ExpenseBuilder::expense(1, '2026-10-20', 'Lunch', null, null, 77);
        $saved = $current->updated($changes->date, $changes->amount, $changes->description, null, new DateTimeImmutable('2026-10-23T12:30:00+00:00'));
        $this->store->answer(Store::expenseFound($current), Store::expenseUpdated($saved));

        $response = $this->patch('{"version":1,"amountCents":77}');

        self::assertSame(['S' => '2026-10-23T11:00:00Z'], $this->update(1)['ExpressionAttributeValues'][':updatedAt']);
        self::assertSame('2026-10-23T12:30:00Z', self::decoded($response)['updatedAt']);
    }

    public function testARaceLostAtTheConditionalWriteIs409(): void
    {
        $current = self::current();
        $winner = self::next($current, '2026-10-20', 5555, 'Winner', null);
        $this->store->answer(Store::expenseFound($current), Store::conditionFailed(), Store::expenseFound($winner));

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(409, $response->status);
        self::assertStringContainsString('The expense changed since you loaded it. Reload it and try again.', $response->body);
        self::assertStringNotContainsString('Winner', $response->body);
        self::assertSame(['GetItem', 'UpdateItem', 'GetItem'], $this->store->sent());
    }

    public function testAnExpenseDeletedDuringTheWriteIsTheGeneric404(): void
    {
        $this->store->answer(Store::expenseFound(self::current()), Store::conditionFailed(), Store::missing());

        $response = $this->patch('{"version":1,"amountCents":5}');

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
    }

    public function testAnUnknownIdIsTheGeneric404AndAMalformedOneIsTheSameBodyWithZeroReads(): void
    {
        $this->store->answer(Store::missing());
        $unknown = $this->patch('{"version":1,"amountCents":5}', ExpenseBuilder::uuid(9));
        $other = new Store();

        $malformed = $other->serve(
            new UpdateExpenseHandler($other->expenses(), $other->repository(), new FrozenClock(self::NOW)),
            Store::request('PATCH', '/x', '{}', Store::owner(), ['id' => 'nope']),
        );

        self::assertSame(404, $unknown->status);
        self::assertSame(['GetItem'], $this->store->sent());
        self::assertSame(404, $malformed->status);
        self::assertSame($unknown->body, $malformed->body);
        self::assertSame([], $other->sent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty object' => ['{}', '/version'];
        yield 'version alone' => ['{"version":1}', ''];
        yield 'description null' => ['{"version":1,"description":null}', '/description'];
        yield 'date null' => ['{"version":1,"date":null}', '/date'];
        yield 'amount null' => ['{"version":1,"amountCents":null}', '/amountCents'];
        yield 'amount as a float' => ['{"version":1,"amountCents":12.5}', '/amountCents'];
        yield 'date as a number' => ['{"version":1,"date":20261020}', '/date'];
        yield 'version missing' => ['{"amountCents":5}', '/version'];
        yield 'version as a string' => ['{"version":"1","amountCents":5}', '/version'];
        yield 'unknown member' => ['{"version":1,"amountCents":5,"colour":"red"}', '/colour'];
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs422AtTheRightPointerWithZeroStorageCalls(string $body, string $pointer): void
    {
        $response = $this->patch($body);

        self::assertSame(422, $response->status);
        self::assertSame($pointer, self::pointerOf($response));
        self::assertSame([], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'wrong media type' => ['{"version":1,"amountCents":5}', 'text/plain', 415];
        yield 'not JSON' => ['{nope', 'application/json', 400];
        yield 'not an object' => ['[]', 'application/json', 400];
        yield 'too large' => ['{"description":"' . str_repeat('a', JsonBody::MAX_BYTES) . '"}', 'application/json', 413];
    }

    #[DataProvider('unreadableBodies')]
    public function testTheBodyIsCheckedBeforeAnythingIsRead(string $body, string $contentType, int $status): void
    {
        $response = $this->patch($body, null, $contentType);

        self::assertSame($status, $response->status);
        self::assertSame([], $this->store->sent());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        try {
            $this->handler()->handle(Store::request('PATCH', '/x', '{"version":1,"amountCents":5}', null, ['id' => ExpenseBuilder::uuid(3)]));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }
}
