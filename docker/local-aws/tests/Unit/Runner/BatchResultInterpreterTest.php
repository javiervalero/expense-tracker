<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\BatchResultInterpreter;
use LocalAws\Runner\InvocationResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BatchResultInterpreterTest extends TestCase
{
    private const BATCH = ['m-1', 'm-2', 'm-3'];

    private static function interpret(int $status, string $body): \LocalAws\Runner\BatchVerdict
    {
        return (new BatchResultInterpreter())->interpret(self::BATCH, new InvocationResult($status, $body));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function successBodies(): iterable
    {
        yield 'empty batchItemFailures' => ['{"batchItemFailures":[]}'];
        yield 'null batchItemFailures' => ['{"batchItemFailures":null}'];
        yield 'no batchItemFailures key' => ['{}'];
        yield 'null response' => ['null'];
        yield 'whitespace around' => ["  {\"batchItemFailures\": []}\n"];
    }

    #[DataProvider('successBodies')]
    public function testACompleteSuccessFailsNothing(string $body): void
    {
        $verdict = self::interpret(200, $body);

        self::assertFalse($verdict->wholeBatchFailed);
        self::assertSame([], $verdict->failedMessageIds);
    }

    public function testReportedItemsAreTheOnlyOnesThatFail(): void
    {
        $verdict = self::interpret(200, '{"batchItemFailures":[{"itemIdentifier":"m-2"}]}');

        self::assertFalse($verdict->wholeBatchFailed);
        self::assertSame(['m-2'], $verdict->failedMessageIds);
    }

    public function testDuplicateReportsCountOnce(): void
    {
        $verdict = self::interpret(200, '{"batchItemFailures":[{"itemIdentifier":"m-3"},{"itemIdentifier":"m-1"},{"itemIdentifier":"m-3"}]}');

        self::assertFalse($verdict->wholeBatchFailed);
        self::assertSame(['m-3', 'm-1'], $verdict->failedMessageIds);
    }

    public function testEveryMessageReportedAsFailedIsNotAWholeBatchFailure(): void
    {
        $verdict = self::interpret(200, '{"batchItemFailures":[{"itemIdentifier":"m-1"},{"itemIdentifier":"m-2"},{"itemIdentifier":"m-3"}]}');

        self::assertFalse($verdict->wholeBatchFailed);
        self::assertSame(['m-1', 'm-2', 'm-3'], $verdict->failedMessageIds);
    }

    /**
     * @return iterable<string, array{int, string, string}> status, body, text the reason must contain
     */
    public static function wholeBatchFailures(): iterable
    {
        yield 'status 500' => [500, '{"batchItemFailures":[]}', '500'];
        yield 'status 502 empty body (BREF_RUNTIME unset)' => [502, '', '502'];
        yield 'status 204' => [204, '', '204'];
        yield 'invalid json' => [200, '{"batchItemFailures":', 'not valid JSON'];
        yield 'empty body' => [200, '', 'not valid JSON'];
        yield 'plain text' => [200, 'Task timed out', 'not valid JSON'];
        yield 'a json string' => [200, '"done"', 'not a JSON object'];
        yield 'a json number' => [200, '1', 'not a JSON object'];
        yield 'a non-empty json list' => [200, '[{"itemIdentifier":"m-1"}]', 'not a JSON object'];
        yield 'RIE error payload (HTTP 200, no header)' => [200, '{"errorType":"App\\\\Queue\\\\MalformedSqsEvent","errorMessage":"boom","stackTrace":[]}', 'errorType'];
        yield 'errorType next to batchItemFailures' => [200, '{"errorType":"X","batchItemFailures":[]}', 'errorType'];
        yield 'batchItemFailures not a list' => [200, '{"batchItemFailures":"m-1"}', 'batchItemFailures'];
        yield 'batchItemFailures an object' => [200, '{"batchItemFailures":{"itemIdentifier":"m-1"}}', 'batchItemFailures'];
        yield 'entry not an object' => [200, '{"batchItemFailures":["m-1"]}', 'itemIdentifier'];
        yield 'itemIdentifier missing' => [200, '{"batchItemFailures":[{"id":"m-1"}]}', 'itemIdentifier'];
        yield 'itemIdentifier empty' => [200, '{"batchItemFailures":[{"itemIdentifier":""}]}', 'itemIdentifier'];
        yield 'itemIdentifier null' => [200, '{"batchItemFailures":[{"itemIdentifier":null}]}', 'itemIdentifier'];
        yield 'itemIdentifier not a string' => [200, '{"batchItemFailures":[{"itemIdentifier":1}]}', 'itemIdentifier'];
        yield 'itemIdentifier not in the batch' => [200, '{"batchItemFailures":[{"itemIdentifier":"m-9"}]}', 'm-9'];
        yield 'one good and one unknown identifier' => [200, '{"batchItemFailures":[{"itemIdentifier":"m-1"},{"itemIdentifier":"nope"}]}', 'nope'];
    }

    #[DataProvider('wholeBatchFailures')]
    public function testAnythingElseFailsTheWholeBatch(int $status, string $body, string $reasonContains): void
    {
        $verdict = self::interpret($status, $body);

        self::assertTrue($verdict->wholeBatchFailed);
        self::assertStringContainsString($reasonContains, $verdict->reason);
    }

    public function testTheReasonNeverEchoesTheResponseBody(): void
    {
        $verdict = self::interpret(200, '{"errorType":"X","errorMessage":"secret receipt text"}');

        self::assertStringNotContainsString('secret receipt text', $verdict->reason);
    }
}
