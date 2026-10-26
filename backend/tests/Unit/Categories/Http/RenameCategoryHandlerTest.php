<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\RenameCategoryHandler;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RenameCategoryHandlerTest extends TestCase
{
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const OTHER_ID = '1b2c3d4e-5f60-4718-9a2b-3c4d5e6f7081';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function handler(): RenameCategoryHandler
    {
        return new RenameCategoryHandler($this->store->repository(), new FrozenClock('2026-10-23T11:00:00+00:00'));
    }

    private function patch(string $id, string $body, string $contentType = 'application/json'): Response
    {
        return $this->store->serve($this->handler(), $this->request($id, $body, $contentType));
    }

    private function request(string $id, string $body, string $contentType = 'application/json'): \App\Http\Request
    {
        return Store::request('PATCH', '/api/v1/categories/' . $id, $body, Store::owner(), ['id' => $id], $contentType);
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

    public function testARenameAnswers200WithTheNextVersion(): void
    {
        $this->store->answer(Store::found(Store::category(self::FOOD_ID, 'Food', 2)), Store::ok());

        $response = $this->patch(self::FOOD_ID, '{"name":"  Groceries ","version":2}');

        self::assertSame(200, $response->status);
        self::assertSame(['id', 'name', 'version', 'createdAt', 'updatedAt'], array_keys(self::decoded($response)));
        self::assertSame('Groceries', self::decoded($response)['name']);
        self::assertSame(3, self::decoded($response)['version']);
        self::assertSame('2026-10-23T11:00:00Z', self::decoded($response)['updatedAt']);
        self::assertSame('2026-10-23T10:00:00Z', self::decoded($response)['createdAt']);
        self::assertSame(['GetItem', 'TransactWriteItems'], $this->store->sent());
    }

    public function testAStaleVersionIsA409AndNothingIsWritten(): void
    {
        $this->store->answer(Store::found(Store::category(self::FOOD_ID, 'Food', 3)));

        $response = $this->patch(self::FOOD_ID, '{"name":"secret-marker","version":2}');

        self::assertSame(409, $response->status);
        self::assertSame('The category changed since you loaded it. Reload it and try again.', self::decoded($response)['detail']);
        self::assertStringNotContainsString('secret-marker', $response->body);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAVersionRaceDetectedByTheWriteIsA409(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food', 2)),
            Store::cancelled(['ConditionalCheckFailed', 'None', 'None']),
            Store::found(Store::category(self::FOOD_ID, 'Food', 3)),
        );

        $response = $this->patch(self::FOOD_ID, '{"name":"Groceries","version":2}');

        self::assertSame(409, $response->status);
        self::assertSame(['GetItem', 'TransactWriteItems', 'GetItem'], $this->store->sent());
    }

    public function testANameThatIsTakenIsA409AndNeverEchoesNameIdOrVersion(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food', 7)),
            Store::cancelled(['None', 'ConditionalCheckFailed', 'None']),
            Store::lockHeldBy(self::OTHER_ID),
            Store::found(Store::category(self::OTHER_ID, 'Travel')),
        );

        $response = $this->patch(self::FOOD_ID, '{"name":"Travel","version":7}');

        self::assertSame(409, $response->status);
        self::assertSame('A category with this name already exists.', self::decoded($response)['detail']);
        self::assertStringNotContainsString('Travel', $response->body);
        self::assertStringNotContainsString(self::FOOD_ID, $response->body);
        self::assertIsString(self::decoded($response)['detail']);
        self::assertStringNotContainsString('7', self::decoded($response)['detail']);
    }

    public function testACategoryDeletedDuringTheRenameIsA404(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food', 1)),
            Store::cancelled(['ConditionalCheckFailed', 'None', 'None']),
            Store::missing(),
        );

        $response = $this->patch(self::FOOD_ID, '{"name":"Groceries","version":1}');

        self::assertSame(404, $response->status);
        self::assertSame(RouteId::NOT_FOUND_DETAIL, self::decoded($response)['detail']);
    }

    public function testAnUnknownIdIsTheGeneric404(): void
    {
        $this->store->answer(Store::missing());

        $response = $this->patch(self::FOOD_ID, '{"name":"Groceries","version":1}');

        self::assertSame(404, $response->status);
        self::assertSame(RouteId::NOT_FOUND_DETAIL, self::decoded($response)['detail']);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAMalformedIdIsTheSame404BodyWithZeroReadsEvenForABadBody(): void
    {
        $this->store->answer(Store::missing());
        $unknown = $this->patch(self::FOOD_ID, '{"name":"x","version":1}');

        $malformed = new Store();
        $handler = new RenameCategoryHandler($malformed->repository(), new FrozenClock());
        $responses = [];
        foreach (['not-a-uuid', strtoupper(self::FOOD_ID), self::FOOD_ID . '#', ''] as $id) {
            $responses[] = $malformed->serve($handler, Store::request('PATCH', '/x', '{"name":5}', Store::owner(), ['id' => $id], 'text/plain'));
        }

        foreach ($responses as $response) {
            self::assertSame(404, $response->status);
            self::assertSame($unknown->body, $response->body);
        }
        self::assertSame([], $malformed->sent());
    }

    public function testTheBodyIsCheckedBeforeTheCategoryIsRead(): void
    {
        self::assertSame(415, $this->patch(self::FOOD_ID, '{"name":"x","version":1}', 'text/plain')->status);
        self::assertSame(400, $this->patch(self::FOOD_ID, '{nope')->status);
        self::assertSame(422, $this->patch(self::FOOD_ID, '{"name":5,"version":1}')->status);
        self::assertSame([], $this->store->sent());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'a number name' => ['{"name":5,"version":1}', '/name'];
        yield 'a null name' => ['{"name":null,"version":1}', '/name'];
        yield 'a string version' => ['{"name":"x","version":"1"}', '/version'];
        yield 'a float version' => ['{"name":"x","version":1.0}', '/version'];
        yield 'a zero version' => ['{"name":"x","version":0}', '/version'];
        yield 'a missing version' => ['{"name":"x"}', '/version'];
        yield 'an unknown member' => ['{"name":"x","version":1,"id":"y"}', '/id'];
    }

    #[DataProvider('invalidBodies')]
    public function testAnInvalidBodyIs422AtTheRightPointerWithZeroReads(string $body, string $pointer): void
    {
        $response = $this->patch(self::FOOD_ID, $body);

        self::assertSame(422, $response->status);
        $errors = self::decoded($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame($pointer, $errors[0]['pointer'] ?? null);
        self::assertSame([], $this->store->sent());
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        $request = Store::request('PATCH', '/x', '{"name":"x","version":1}', null, ['id' => self::FOOD_ID]);

        try {
            $this->handler()->handle($request);
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }

    public function testOverloadOnTheWriteIsA503(): void
    {
        $this->store->answer(Store::found(Store::category(self::FOOD_ID, 'Food')), Store::cancelled(['ThrottlingError', 'None', 'None']));

        $response = $this->patch(self::FOOD_ID, '{"name":"Groceries","version":1}');

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
    }

    public function testOverloadOnTheReadIsA503(): void
    {
        $this->store->answerThrottled();

        self::assertSame(503, $this->patch(self::FOOD_ID, '{"name":"Groceries","version":1}')->status);
    }

    public function testARawConditionalCheckFailedCancellationStaysA500NeverA409(): void
    {
        // A cancellation that is no name or version outcome (here a failed condition at a position the rename never uses).
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food')),
            Store::cancelled(['None', 'None', 'None', 'ConditionalCheckFailed']),
        );

        $response = $this->patch(self::FOOD_ID, '{"name":"Groceries","version":1}');

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('already', $response->body);
        self::assertStringNotContainsString('changed', $response->body);
    }

    public function testTheRawCancellationReachesTheMiddlewareUnchanged(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food')),
            Store::cancelled(['None', 'None', 'None', 'ConditionalCheckFailed']),
        );

        try {
            $this->handler()->handle($this->request(self::FOOD_ID, '{"name":"Groceries","version":1}'));
            self::fail('expected a TransactionCancelled');
        } catch (TransactionCancelled $cancelled) {
            self::assertContains('ConditionalCheckFailed', $cancelled->reasons);
        }
    }
}
