<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\DeleteExpenseHandler;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DeleteExpenseHandlerTest extends TestCase
{
    private Store $store;

    protected function setUp(): void
    {
        $this->store = new Store();
    }

    private function delete(string $id): Response
    {
        return $this->store->serve(
            new DeleteExpenseHandler($this->store->expenses()),
            Store::request('DELETE', '/api/v1/expenses/' . $id, principal: Store::owner(), routeParams: ['id' => $id]),
        );
    }

    public function testADeleteAnswers204WithAnEmptyBodyAfterOneTransaction(): void
    {
        $this->store->answer(Store::ok());

        $response = $this->delete(ExpenseBuilder::uuid(3));

        self::assertSame(204, $response->status);
        self::assertSame('', $response->body);
        self::assertSame(['TransactWriteItems'], $this->store->sent());
    }

    public function testTheTransactionUsesTheCallersPartition(): void
    {
        $this->store->answer(Store::ok());

        $this->delete(ExpenseBuilder::uuid(3));

        /** @var array{TransactItems: list<array{Delete: array{Key: array{PK: array{S: string}, SK: array{S: string}}}}>} $command */
        $command = $this->store->command(0)->toArray();
        self::assertSame('USER#' . Store::USER, $command['TransactItems'][0]['Delete']['Key']['PK']['S']);
        self::assertSame('EXP#' . ExpenseBuilder::uuid(3), $command['TransactItems'][0]['Delete']['Key']['SK']['S']);
    }

    public function testAnUnknownOrAlreadyDeletedExpenseIsTheGeneric404(): void
    {
        $this->store->answer(Store::cancelled(['ConditionalCheckFailed', 'None']));

        $response = $this->delete(ExpenseBuilder::uuid(9));

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
    }

    public function testAMalformedIdIsTheSame404BodyWithZeroStorageCalls(): void
    {
        $this->store->answer(Store::cancelled(['ConditionalCheckFailed', 'None']));
        $unknown = $this->delete(ExpenseBuilder::uuid(9));
        $other = new Store();

        $response = $other->serve(
            new DeleteExpenseHandler($other->expenses()),
            Store::request('DELETE', '/x', principal: Store::owner(), routeParams: ['id' => 'nope']),
        );

        self::assertSame(404, $response->status);
        self::assertSame($unknown->body, $response->body);
        self::assertSame([], $other->sent());
    }

    public function testOverloadOnTheWriteIsA503(): void
    {
        $this->store->answer(Store::cancelled(['ThrottlingError', 'None']));

        $response = $this->delete(ExpenseBuilder::uuid(3));

        self::assertSame(503, $response->status);
        self::assertSame(['1'], $response->headerValues('Retry-After'));
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingIsSent(): void
    {
        $handler = new DeleteExpenseHandler($this->store->expenses());

        try {
            $handler->handle(Store::request('DELETE', '/x', routeParams: ['id' => ExpenseBuilder::uuid(3)]));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
        }
    }
}
