<?php

declare(strict_types=1);

namespace App\Tests\Unit\Categories\Http;

use App\Categories\Http\DeleteCategoryHandler;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DeleteCategoryHandlerTest extends TestCase
{
    private const FOOD_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';

    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function delete(string $id): Response
    {
        return $this->store->serve(
            new DeleteCategoryHandler($this->store->repository()),
            Store::request('DELETE', '/api/v1/categories/' . $id, principal: Store::owner(), routeParams: ['id' => $id]),
        );
    }

    public function testADeleteAnswers204WithAnEmptyBody(): void
    {
        $this->store->answer(Store::found(Store::category(self::FOOD_ID, 'Food')), Store::ok());

        $response = $this->delete(self::FOOD_ID);

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(['GetItem', 'TransactWriteItems'], $this->store->sent());
    }

    public function testASecondDeleteIsTheGeneric404(): void
    {
        $this->store->answer(Store::missing());

        $response = $this->delete(self::FOOD_ID);

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame(['GetItem'], $this->store->sent());
    }

    public function testAMalformedIdIsTheSame404WithZeroReads(): void
    {
        $this->store->answer(Store::missing());
        $unknown = $this->delete(self::FOOD_ID);
        $other = new Store();

        $response = $other->serve(
            new DeleteCategoryHandler($other->repository()),
            Store::request('DELETE', '/x', principal: Store::owner(), routeParams: ['id' => 'nope']),
        );

        self::assertSame(404, $response->status);
        self::assertSame($unknown->body, $response->body);
        self::assertSame([], $other->sent());
    }

    public function testACategoryDeletedByAnotherRequestDuringTheDeleteIsA404(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food')),
            Store::cancelled(['ConditionalCheckFailed', 'None']),
            Store::missing(),
        );

        $response = $this->delete(self::FOOD_ID);

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
    }

    public function testOverloadOnTheWriteIsA503(): void
    {
        $this->store->answer(Store::found(Store::category(self::FOOD_ID, 'Food')), Store::cancelled(['ThrottlingError', 'None']));

        $response = $this->delete(self::FOOD_ID);

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
    }

    public function testOverloadOnTheReadIsA503(): void
    {
        $this->store->answerThrottled();

        self::assertSame(503, $this->delete(self::FOOD_ID)->status);
    }

    public function testACategoryThatKeepsChangingIsAGeneric500NotAnUndocumented409(): void
    {
        $this->store->answer(
            Store::found(Store::category(self::FOOD_ID, 'Food', 1)),
            Store::cancelled(['ConditionalCheckFailed', 'None']),
            Store::found(Store::category(self::FOOD_ID, 'Food', 2)),
            Store::cancelled(['ConditionalCheckFailed', 'None']),
            Store::found(Store::category(self::FOOD_ID, 'Food', 3)),
        );

        self::assertSame(500, $this->delete(self::FOOD_ID)->status);
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsRead(): void
    {
        $handler = new DeleteCategoryHandler($this->store->repository());

        try {
            $handler->handle(Store::request('DELETE', '/x', routeParams: ['id' => self::FOOD_ID]));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }
}
