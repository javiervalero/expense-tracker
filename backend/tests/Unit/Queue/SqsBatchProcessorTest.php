<?php

declare(strict_types=1);

namespace App\Tests\Unit\Queue;

use App\Infrastructure\Logging\JsonLogger;
use App\Queue\MalformedSqsEvent;
use App\Queue\RecordProcessor;
use App\Queue\SqsBatchProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SqsBatchProcessorTest extends TestCase
{
    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->logStream = $stream;
    }

    private function batch(RecordProcessor $processor): SqsBatchProcessor
    {
        return new SqsBatchProcessor($processor, new JsonLogger($this->logStream));
    }

    private function logOutput(): string
    {
        rewind($this->logStream);

        return (string) stream_get_contents($this->logStream);
    }

    /**
     * @param list<string> $ids
     *
     * @return array{Records: list<array{messageId: string, body: string}>}
     */
    private static function eventWith(array $ids): array
    {
        return ['Records' => array_map(static fn(string $id): array => ['messageId' => $id, 'body' => 'body of ' . $id], $ids)];
    }

    public function testReportsNoFailuresWhenEveryRecordSucceeds(): void
    {
        $result = $this->batch($processor = new RecordingProcessor([]))->handle(self::eventWith(['m1', 'm2', 'm3']));

        self::assertSame(['batchItemFailures' => []], $result);
        self::assertSame(['m1', 'm2', 'm3'], $processor->seen(), 'one call per record, in order');
        self::assertSame('', $this->logOutput(), 'nothing to report, nothing logged');
    }

    public function testReportsOnlyTheRecordThatFailedAndStillProcessesTheRest(): void
    {
        $result = $this->batch($processor = new RecordingProcessor(['m2']))->handle(self::eventWith(['m1', 'm2', 'm3']));

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm2']]], $result);
        self::assertSame(['m1', 'm2', 'm3'], $processor->seen(), 'a failure does not stop the later records');
    }

    public function testReportsEveryRecordWhenAllFail(): void
    {
        $result = $this->batch($processor = new RecordingProcessor(['m1', 'm2']))->handle(self::eventWith(['m1', 'm2']));

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm1'], ['itemIdentifier' => 'm2']]], $result);
    }

    public function testAnErrorThrowableIsAlsoAFailureOfThatRecordOnly(): void
    {
        $processor = new class implements RecordProcessor {
            public function process(array $record): void
            {
                if ($record['messageId'] === 'm1') {
                    throw new \TypeError('wrong type');
                }
            }
        };

        $result = $this->batch($processor)->handle(self::eventWith(['m1', 'm2']));

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => 'm1']]], $result);
    }

    public function testAnEmptyRecordsListIsAnEmptyBatchNotAnError(): void
    {
        $result = $this->batch($processor = new RecordingProcessor([]))->handle(['Records' => []]);

        self::assertSame(['batchItemFailures' => []], $result);
        self::assertSame([], $processor->seen());
    }

    /**
     * @param array<array-key, mixed> $event
     */
    #[DataProvider('malformedEvents')]
    public function testAnEventThatIsNotAnSqsBatchFailsTheWholeInvocation(array $event): void
    {
        try {
            $this->batch($processor = new RecordingProcessor([]))->handle($event);
            self::fail('expected MalformedSqsEvent');
        } catch (MalformedSqsEvent) {
            self::assertSame([], $processor->seen(), 'no record is processed from a malformed event');
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function malformedEvents(): iterable
    {
        yield 'no Records key' => [['foo' => 'bar']];
        yield 'empty event' => [[]];
        yield 'Records is not a list' => [['Records' => 'nope']];
        yield 'Records is an object' => [['Records' => ['a' => ['messageId' => 'm1']]]];
        yield 'a record is not an object' => [['Records' => ['text']]];
        yield 'a record has no messageId' => [['Records' => [['body' => 'x']]]];
        yield 'a record has an empty messageId' => [['Records' => [['messageId' => '', 'body' => 'x']]]];
        yield 'a record has a non-string messageId' => [['Records' => [['messageId' => 7, 'body' => 'x']]]];
        yield 'a bad record after a good one' => [['Records' => [['messageId' => 'm1'], ['body' => 'no id']]]];
        yield 'a duplicate messageId' => [['Records' => [['messageId' => 'm1'], ['messageId' => 'm2'], ['messageId' => 'm1']]]];
        yield 'a duplicate numeric messageId' => [['Records' => [['messageId' => '123'], ['messageId' => '123']]]];
    }

    public function testTheDuplicateMessageIdIsNamedInTheError(): void
    {
        $this->expectException(MalformedSqsEvent::class);
        $this->expectExceptionMessage('duplicate messageId');

        $this->batch(new RecordingProcessor([]))->handle(self::eventWith(['m1', 'm1']));
    }

    public function testANumericStringMessageIdStaysAStringInTheFailures(): void
    {
        $result = $this->batch($processor = new RecordingProcessor(['123']))->handle(self::eventWith(['123', '456']));

        self::assertSame(['batchItemFailures' => [['itemIdentifier' => '123']]], $result);
        self::assertSame(['123', '456'], $processor->seen());
    }

    public function testDoesNotLogTheMessageOfAnErrorFromOutsideTheProject(): void
    {
        $processor = new class implements RecordProcessor {
            public function process(array $record): void
            {
                throw new \TypeError('bad value: {"merchant":"secret-payload"}');
            }
        };

        $this->batch($processor)->handle(self::eventWith(['m1']));

        $output = $this->logOutput();
        self::assertStringNotContainsString('secret-payload', $output);
        $entry = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertSame(\TypeError::class, $entry['errorClass']);
        self::assertArrayNotHasKey('errorMessage', $entry);
        self::assertSame(0, $entry['errorCode']);
    }

    public function testLogsTheFailedMessageIdAndErrorButNotTheBody(): void
    {
        $this->batch($processor = new RecordingProcessor(['m2']))->handle(self::eventWith(['m1', 'm2']));

        $output = $this->logOutput();
        $lines = explode("\n", trim($output));
        self::assertCount(1, $lines);
        $entry = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($entry);
        self::assertSame('error', $entry['level']);
        self::assertSame('m2', $entry['messageId']);
        self::assertSame(RecordRejected::class, $entry['errorClass']);
        self::assertSame('cannot process m2', $entry['errorMessage']);
        self::assertStringNotContainsString('body of m2', $output);
    }
}
