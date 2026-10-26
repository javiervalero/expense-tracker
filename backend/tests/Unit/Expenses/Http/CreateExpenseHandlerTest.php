<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\CreateExpenseHandler;
use App\Http\JsonBody;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Id\IdGenerator;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateExpenseHandlerTest extends TestCase
{
    private const NEW_ID = '7e3a1b94-6c2d-4f05-8b17-2d9e4a6c8f10';
    private const FOOD = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const PATH = '/api/v1/expenses';
    private const VALID = '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function handler(): CreateExpenseHandler
    {
        $ids = new class (self::NEW_ID) implements IdGenerator {
            public function __construct(private readonly string $id) {}

            public function generate(): string
            {
                return $this->id;
            }
        };

        return new CreateExpenseHandler($this->store->expenses(), $this->store->repository(), $ids, new FrozenClock('2026-10-23T11:00:00+00:00'));
    }

    private function post(string $body = self::VALID, string $contentType = 'application/json'): Response
    {
        return $this->store->serve($this->handler(), Store::request('POST', self::PATH, $body, Store::owner(), contentType: $contentType));
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

    public function testAnUncategorizedExpenseIsWrittenOnceAndAnswers201WithLocation(): void
    {
        $this->store->answer(Store::ok());

        $response = $this->post();

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/expenses/' . self::NEW_ID], $response->headerValues('Location'));
        self::assertSame(['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'], array_keys(self::decoded($response)));
        self::assertSame(
            ['id' => self::NEW_ID, 'date' => '2026-10-20', 'amountCents' => 1250, 'description' => 'Lunch', 'categoryId' => null, 'version' => 1, 'createdAt' => '2026-10-23T11:00:00Z', 'updatedAt' => '2026-10-23T11:00:00Z'],
            self::decoded($response),
        );
        self::assertSame(['PutItem'], $this->store->sent());
        /** @var array{Item: array<string, mixed>} $put */
        $put = $this->store->command(0)->toArray();
        self::assertArrayNotHasKey('categoryId', $put['Item']);
    }

    public function testAnExplicitNullCategoryIsUncategorizedWithoutAnyCategoryRead(): void
    {
        $this->store->answer(Store::ok());

        $response = $this->post('{"date":"2026-10-20","amountCents":1,"description":"x","categoryId":null}');

        self::assertSame(201, $response->status);
        self::assertSame(['PutItem'], $this->store->sent());
    }

    public function testACategoryOfTheCallerIsStoredAndEchoedWithOneCategoryRead(): void
    {
        $this->store->answer(Store::listing([Store::category(self::FOOD, 'Food')]), Store::ok());

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . self::FOOD . '"}');

        self::assertSame(201, $response->status);
        self::assertSame(self::FOOD, self::decoded($response)['categoryId']);
        // The response goes through the resolver, whose list is already loaded: no second category read.
        self::assertSame(['Query', 'PutItem'], $this->store->sent());
        /** @var array{Item: array{categoryId: array{S: string}}} $put */
        $put = $this->store->command(1)->toArray();
        self::assertSame(self::FOOD, $put['Item']['categoryId']['S']);
    }

    public function testTheCategoryReadAndTheWriteUseTheCallersPartition(): void
    {
        $this->store->answer(Store::listing([Store::category(self::FOOD, 'Food')]), Store::ok());

        $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . self::FOOD . '"}');

        /** @var array{ExpressionAttributeValues: array{':pk': array{S: string}}} $query */
        $query = $this->store->command(0)->toArray();
        /** @var array{Item: array{PK: array{S: string}}} $put */
        $put = $this->store->command(1)->toArray();
        self::assertSame('USER#' . Store::USER, $query['ExpressionAttributeValues'][':pk']['S']);
        self::assertSame('USER#' . Store::USER, $put['Item']['PK']['S']);
    }

    public function testACategoryTheCallerDoesNotHaveIs422AtCategoryIdAndNothingIsWritten(): void
    {
        // Unknown, deleted and another user's ids look the same to the resolver: not in the caller's list.
        $this->store->answer(Store::listing([Store::category('1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081', 'Travel')]));

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . self::FOOD . '"}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertStringNotContainsString(self::FOOD, $response->body);
        self::assertSame(['Query'], $this->store->sent());
    }

    public function testAMalformedCategoryIdIs422AtCategoryIdWithNoWriteAndNoRead(): void
    {
        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"not-a-uuid"}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertSame([], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonStringCategories(): iterable
    {
        yield 'a number' => ['5'];
        yield 'a list' => ['["' . self::FOOD . '"]'];
        yield 'an empty object' => ['{}'];
        yield 'true' => ['true'];
        yield 'false' => ['false'];
    }

    #[DataProvider('nonStringCategories')]
    public function testANonStringCategoryIdIs422BeforeAnyStorageCall(string $json): void
    {
        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":' . $json . '}');

        self::assertSame(422, $response->status);
        self::assertSame('/categoryId', self::pointerOf($response));
        self::assertSame([], $this->store->sent());
    }

    public function testAStorageFailureFromTheCategoryReadEndsTheRequestAsA503AndNothingIsWritten(): void
    {
        $this->store->answerThrottled();

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . self::FOOD . '"}');

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
        self::assertNotContains('PutItem', $this->store->sent());
    }

    public function testAStorageFailureFromTheCategoryReadReachesTheMiddlewareAsAnException(): void
    {
        $this->store->answerThrottled();

        $this->expectException(StorageFailure::class);

        $this->handler()->handle(Store::request(
            'POST',
            self::PATH,
            '{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . self::FOOD . '"}',
            Store::owner(),
        ));
    }

    public function testAnIdCollisionWithADifferentStoredExpenseIsA500WithNoLocationAndNoEcho(): void
    {
        $this->store->answer(Store::conditionFailed(), Store::expenseFound(ExpenseBuilder::expense(1, '2026-10-20', 'another-description', null, self::NEW_ID)));

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"secret-marker"}');

        self::assertSame(500, $response->status);
        self::assertSame([], $response->headerValues('Location'));
        self::assertStringNotContainsString('secret-marker', $response->body);
        self::assertStringNotContainsString('another-description', $response->body);
        self::assertSame(['PutItem', 'GetItem'], $this->store->sent());
    }

    public function testARetriedWriteOfTheSameContentIs201(): void
    {
        $same = ExpenseBuilder::expense(1, '2026-10-20', 'Lunch', null, self::NEW_ID, 1250, '2026-10-23T11:00:00+00:00');
        $this->store->answer(Store::conditionFailed(), Store::expenseFound($same));

        $response = $this->post();

        self::assertSame(201, $response->status);
        self::assertSame(['/api/v1/expenses/' . self::NEW_ID], $response->headerValues('Location'));
        self::assertSame(['PutItem', 'GetItem'], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'amount as a float' => ['{"date":"2026-10-20","amountCents":12.5,"description":"x"}', '/amountCents'];
        yield 'date as a number' => ['{"date":20261020,"amountCents":1,"description":"x"}', '/date'];
        yield 'description missing' => ['{"date":"2026-10-20","amountCents":1}', '/description'];
        yield 'unknown member' => ['{"date":"2026-10-20","amountCents":1,"description":"x","colour":"red"}', '/colour'];
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs422AtTheRightPointerWithZeroStorageCalls(string $body, string $pointer): void
    {
        $response = $this->post($body);

        self::assertSame(422, $response->status);
        self::assertSame($pointer, self::pointerOf($response));
        self::assertSame([], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'wrong media type' => [self::VALID, 'text/plain', 415];
        yield 'not JSON' => ['{nope', 'application/json', 400];
        yield 'not an object' => ['[]', 'application/json', 400];
        yield 'too large' => ['{"description":"' . str_repeat('a', JsonBody::MAX_BYTES) . '"}', 'application/json', 413];
    }

    #[DataProvider('unreadableBodies')]
    public function testTheBodyIsCheckedBeforeAnythingIsRead(string $body, string $contentType, int $status): void
    {
        $response = $this->post($body, $contentType);

        self::assertSame($status, $response->status);
        self::assertSame([], $this->store->sent());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        try {
            $this->handler()->handle(Store::request('POST', self::PATH, self::VALID));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }
}
