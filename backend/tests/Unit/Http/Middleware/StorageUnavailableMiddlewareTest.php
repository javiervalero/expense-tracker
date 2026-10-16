<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Logging\JsonLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class StorageUnavailableMiddlewareTest extends TestCase
{
    private const PRIVATE_NOTE = 'private-note-xyz-123';

    /** @var resource */
    private $stream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->stream = $stream;
    }

    private function logged(): string
    {
        rewind($this->stream);

        return (string) stream_get_contents($this->stream);
    }

    private function through(Throwable $thrown): Response
    {
        $next = new class ($thrown) implements Handler {
            public function __construct(private Throwable $thrown) {}

            public function handle(Request $request): Response
            {
                throw $this->thrown;
            }
        };

        return (new StorageUnavailableMiddleware(new JsonLogger($this->stream)))->process(new Request('GET', '/x', [], [], ''), $next);
    }

    private function assertServiceUnavailable(Throwable $thrown): HttpProblem
    {
        try {
            $this->through($thrown);
        } catch (HttpProblem $problem) {
            self::assertSame(503, $problem->problem->status);
            self::assertSame(['Retry-After' => ['1']], $problem->headers);

            return $problem;
        }
        self::fail('Expected a 503 HttpProblem.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function throttlingCodes(): iterable
    {
        yield 'provisioned throughput' => ['ProvisionedThroughputExceededException'];
        yield 'throttling' => ['ThrottlingException'];
        yield 'request limit' => ['RequestLimitExceeded'];
    }

    #[DataProvider('throttlingCodes')]
    public function testEachThrottlingCodeBecomesA503WithRetryAfter(string $code): void
    {
        $this->assertServiceUnavailable(new StorageFailure('GetItem', $code, 'REQ-1', 400));
    }

    public function testTheLogLineHasOperationCodeAndRequestIdOnly(): void
    {
        $this->assertServiceUnavailable(new StorageFailure('PutItem', 'ThrottlingException', 'REQ-42', 400));

        $line = json_decode(trim($this->logged()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        self::assertSame('storage unavailable', $line['message']);
        self::assertSame('PutItem', $line['operation']);
        self::assertSame('ThrottlingException', $line['code']);
        self::assertSame('REQ-42', $line['awsRequestId']);
    }

    public function testTheLogLineOfACancelledTransactionNamesTheReasonsAndNoItemData(): void
    {
        $this->assertServiceUnavailable(new TransactionCancelled(['None', 'ThrottlingError'], 'REQ-7', 400));

        $line = json_decode(trim($this->logged()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($line);
        self::assertSame('storage unavailable', $line['message']);
        self::assertSame('TransactWriteItems', $line['operation']);
        self::assertSame('None,ThrottlingError', $line['code']);
        self::assertSame('REQ-7', $line['awsRequestId']);
        self::assertStringNotContainsString(self::PRIVATE_NOTE, $this->logged());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function overloadedCancellations(): iterable
    {
        yield 'none then throttling' => [['None', 'ThrottlingError']];
        yield 'provisioned throughput' => [['ProvisionedThroughputExceeded']];
        yield 'transaction conflict' => [['TransactionConflict', 'None']];
        yield 'all three kinds' => [['TransactionConflict', 'ThrottlingError', 'ProvisionedThroughputExceeded']];
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('overloadedCancellations')]
    public function testACancellationOfOnlyOverloadReasonsBecomesA503(array $reasons): void
    {
        $this->assertServiceUnavailable(new TransactionCancelled($reasons));
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function otherCancellations(): iterable
    {
        yield 'conditional check failed' => [['ConditionalCheckFailed', 'None']];
        yield 'conditional check with throttling' => [['ThrottlingError', 'ConditionalCheckFailed']];
        yield 'validation error' => [['ValidationError']];
        yield 'validation error with throttling' => [['None', 'ValidationError', 'ThrottlingError']];
        yield 'only none' => [['None', 'None']];
        yield 'no reasons' => [[]];
        yield 'an unknown reason' => [['ItemCollectionSizeLimitExceeded']];
    }

    /**
     * @param list<string> $reasons
     */
    #[DataProvider('otherCancellations')]
    public function testAnyOtherCancellationIsRethrownUnchanged(array $reasons): void
    {
        $cancelled = new TransactionCancelled($reasons);

        try {
            $this->through($cancelled);
            self::fail('Expected the cancellation to be rethrown.');
        } catch (TransactionCancelled $thrown) {
            self::assertSame($cancelled, $thrown);
        }
        self::assertSame('', $this->logged());
    }

    /**
     * @return iterable<string, array{Throwable}>
     */
    public static function unrelatedFailures(): iterable
    {
        yield 'access denied' => [new StorageFailure('GetItem', 'AccessDeniedException')];
        yield 'validation' => [new StorageFailure('PutItem', 'ValidationException')];
        yield 'client error' => [new StorageFailure('GetItem', 'ClientError')];
        yield 'no code' => [new StorageFailure('GetItem', null)];
        yield 'a problem already decided' => [HttpProblem::of(404)];
        yield 'a runtime error' => [new RuntimeException('boom')];
    }

    #[DataProvider('unrelatedFailures')]
    public function testEverythingElseIsRethrownUnchangedAndNotLogged(Throwable $failure): void
    {
        try {
            $this->through($failure);
            self::fail('Expected the failure to be rethrown.');
        } catch (Throwable $thrown) {
            self::assertSame($failure, $thrown);
        }
        self::assertSame('', $this->logged());
    }

    public function testAResponseIsReturnedUntouched(): void
    {
        $response = Response::json(200, ['ok' => true]);
        $next = new class ($response) implements Handler {
            public function __construct(private Response $response) {}

            public function handle(Request $request): Response
            {
                return $this->response;
            }
        };

        $result = (new StorageUnavailableMiddleware(new JsonLogger($this->stream)))->process(new Request('GET', '/x', [], [], ''), $next);

        self::assertSame($response, $result);
    }
}
