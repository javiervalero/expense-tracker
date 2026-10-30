<?php

declare(strict_types=1);

namespace App\Tests\Unit\Expenses\Http;

use App\Expenses\Http\DeleteExpenseHandler;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Receipts\ReceiptObjectCleaner;
use App\Tests\Support\ExpenseBuilder;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\S3\InMemoryObjectStore;
use App\Tests\Support\S3\TestS3Settings;
use App\Tests\Support\ScriptedCategoryStore as Store;
use LogicException;
use PHPUnit\Framework\TestCase;

final class DeleteExpenseHandlerTest extends TestCase
{
    private Store $store;

    private InMemoryObjectStore $objects;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->store = new Store();
        $this->objects = new InMemoryObjectStore();
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function handler(Store $store): DeleteExpenseHandler
    {
        $s3 = TestS3Settings::create();
        $cleaner = new ReceiptObjectCleaner(new S3OriginalObjects($this->objects, $s3), new S3ThumbnailObjects($this->objects, $s3), new JsonLogger($this->log), $s3, new FrozenClock());

        return new DeleteExpenseHandler($store->expenses(), $cleaner);
    }

    /**
     * @param array<string, string> $query
     */
    private function delete(string $id, array $query = []): Response
    {
        return $this->store->serve(
            $this->handler($this->store),
            Store::request('DELETE', '/api/v1/expenses/' . $id, principal: Store::owner(), routeParams: ['id' => $id], query: $query),
        );
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
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
            $this->handler($other),
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
        $handler = $this->handler($this->store);

        try {
            $handler->handle(Store::request('DELETE', '/x', routeParams: ['id' => ExpenseBuilder::uuid(3)]));
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->store->sent());
            self::assertSame([], $this->objects->calls);
        }
    }

    public function testAfterTheTransactionBothFoldersOfThisExpenseAreRemovedAndNoOtherObject(): void
    {
        $id = ExpenseBuilder::uuid(3);
        $other = ExpenseBuilder::uuid(4);
        $this->objects->objects = [
            'rcp' => [
                'receipts/' . Store::USER . '/' . $id . '/r1' => 'a',
                'receipts/' . Store::USER . '/' . $id . '/stray' => 'b',
                'receipts/' . Store::USER . '/' . $other . '/r2' => 'keep',
            ],
            'thumbs' => [
                Store::USER . '/' . $id . '/r1.jpg' => 't',
                Store::USER . '/' . $other . '/r2.jpg' => 'keep',
            ],
        ];
        $this->store->answer(Store::ok());

        $response = $this->delete($id);

        self::assertSame(204, $response->status);
        self::assertSame(['receipts/' . Store::USER . '/' . $other . '/r2'], $this->objects->keys('rcp'));
        self::assertSame([Store::USER . '/' . $other . '/r2.jpg'], $this->objects->keys('thumbs'));
    }

    public function testTheCleanedUserIsTheSessionsNeverOneFromTheRequest(): void
    {
        $this->store->answer(Store::ok());
        $id = ExpenseBuilder::uuid(3);
        $intruder = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';

        $this->delete($id, ['userId' => $intruder, 'user' => $intruder]);

        self::assertSame(
            [
                'deletePrefix rcp receipts/' . Store::USER . '/' . $id . '/',
                'deletePrefix thumbs ' . Store::USER . '/' . $id . '/',
            ],
            $this->objects->calls,
        );
    }

    public function testNothingIsCleanedWhenTheExpenseWasNotFound(): void
    {
        $this->store->answer(Store::cancelled(['ConditionalCheckFailed', 'None']));

        $this->delete(ExpenseBuilder::uuid(9));

        self::assertSame([], $this->objects->calls);
    }

    public function testNothingIsCleanedWhenTheTransactionFails(): void
    {
        $this->store->answer(Store::cancelled(['ThrottlingError', 'None']));

        $this->delete(ExpenseBuilder::uuid(3));

        self::assertSame([], $this->objects->calls);
    }

    public function testAnS3FailureIsStill204WithTheCleanersErrorLog(): void
    {
        $this->store->answer(Store::ok());
        $this->objects->failing = true;

        $response = $this->delete(ExpenseBuilder::uuid(3));

        self::assertSame(204, $response->status);
        self::assertStringContainsString('receipt cleanup failed', $this->logged());
        foreach (['http://', 'https://', 'X-Amz', 'Signature', 'Policy'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $this->logged());
        }
    }
}
