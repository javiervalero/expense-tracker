<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts;

use App\Receipts\InvalidS3Event;
use App\Receipts\S3EventParser;
use App\Receipts\S3ObjectRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class S3EventParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/s3-events';

    private static function fixture(string $name): string
    {
        $json = file_get_contents(self::FIXTURES . '/' . $name);
        self::assertIsString($json);

        return $json;
    }

    /**
     * @param array<string, mixed> $object
     */
    private static function eventWithObject(array $object, string $bucket = 'receipts'): string
    {
        return json_encode(['Records' => [['s3' => ['bucket' => ['name' => $bucket], 'object' => $object]]]], JSON_THROW_ON_ERROR);
    }

    public function testParsesTheAwsFixture(): void
    {
        $objects = (new S3EventParser())->parse(self::fixture('aws-object-created-post.json'));

        self::assertEquals([new S3ObjectRef('amzn-s3-demo-bucket', 'HappyFace.jpg')], $objects);
    }

    public function testParsesTheEmulatorFixtureWithItsPercentEncodedKey(): void
    {
        $objects = (new S3EventParser())->parse(self::fixture('silo-object-created-post.json'));

        self::assertEquals([new S3ObjectRef('receipts', 'receipts/user-fe70a955/expense-fe70a955/receipt-fe70a955')], $objects);
    }

    public function testTheSameLogicalObjectInTheAwsAndTheEmulatorShapeGivesTheSameReference(): void
    {
        $key = 'receipts/user-1/expense 2/receipt+3';
        $aws = self::eventWithObject(['key' => 'receipts/user-1/expense+2/receipt%2B3']);
        $silo = self::eventWithObject(['key' => 'receipts%2Fuser-1%2Fexpense+2%2Freceipt%2B3']);
        $parser = new S3EventParser();

        self::assertEquals($parser->parse($aws), $parser->parse($silo));
        self::assertSame($key, $parser->parse($aws)[0]->key);
    }

    #[DataProvider('encodedKeys')]
    public function testDecodesTheKey(string $encoded, string $expected): void
    {
        $objects = (new S3EventParser())->parse(self::eventWithObject(['key' => $encoded]));

        self::assertSame($expected, $objects[0]->key);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function encodedKeys(): iterable
    {
        yield 'plus is a space' => ['my+receipt.jpg', 'my receipt.jpg'];
        yield 'encoded slash' => ['a%2Fb%2Fc', 'a/b/c'];
        yield 'encoded plus stays a plus' => ['a%2Bb', 'a+b'];
        yield 'percent sign' => ['100%25', '100%'];
        yield 'utf-8' => ['caf%C3%A9+%F0%9F%A7%BE', 'café 🧾'];
        yield 'already plain' => ['plain/key.png', 'plain/key.png'];
    }

    public function testParsesEveryRecordOfTheEvent(): void
    {
        $body = json_encode(['Records' => [
            ['s3' => ['bucket' => ['name' => 'receipts'], 'object' => ['key' => 'a']]],
            ['s3' => ['bucket' => ['name' => 'receipts'], 'object' => ['key' => 'b%2Fc']]],
        ]], JSON_THROW_ON_ERROR);

        self::assertEquals(
            [new S3ObjectRef('receipts', 'a'), new S3ObjectRef('receipts', 'b/c')],
            (new S3EventParser())->parse($body),
        );
    }

    public function testNeverReadsTheEventSourceOrTheEventName(): void
    {
        $body = json_encode(['Records' => [[
            'eventSource' => 'something:else',
            'eventName' => 'ObjectRemoved:Delete',
            's3' => ['bucket' => ['name' => 'receipts'], 'object' => ['key' => 'k']],
        ]]], JSON_THROW_ON_ERROR);

        self::assertEquals([new S3ObjectRef('receipts', 'k')], (new S3EventParser())->parse($body));
    }

    public function testTheS3TestEventHasNoObjects(): void
    {
        self::assertSame([], (new S3EventParser())->parse(self::fixture('aws-s3-test-event.json')));
    }

    #[DataProvider('invalidBodies')]
    public function testRejectsABodyThatIsNotAnS3Event(string $body): void
    {
        $this->expectException(InvalidS3Event::class);

        (new S3EventParser())->parse($body);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBodies(): iterable
    {
        yield 'empty string' => [''];
        yield 'not json' => ['this is not json'];
        yield 'json scalar' => ['"text"'];
        yield 'json number' => ['42'];
        yield 'empty object' => ['{}'];
        yield 'empty list' => ['[]'];
        yield 'Records empty' => ['{"Records":[]}'];
        yield 'Records not a list' => ['{"Records":"x"}'];
        yield 'Records is an object' => ['{"Records":{"a":{}}}'];
        yield 'record not an object' => ['{"Records":["x"]}'];
        yield 'record without s3' => ['{"Records":[{}]}'];
        yield 's3 without bucket' => ['{"Records":[{"s3":{"object":{"key":"k"}}}]}'];
        yield 's3 without object' => ['{"Records":[{"s3":{"bucket":{"name":"b"}}}]}'];
        yield 'bucket without name' => ['{"Records":[{"s3":{"bucket":{},"object":{"key":"k"}}}]}'];
        yield 'bucket name not a string' => ['{"Records":[{"s3":{"bucket":{"name":5},"object":{"key":"k"}}}]}'];
        yield 'bucket name empty' => ['{"Records":[{"s3":{"bucket":{"name":""},"object":{"key":"k"}}}]}'];
        yield 'object without key' => ['{"Records":[{"s3":{"bucket":{"name":"b"},"object":{}}}]}'];
        yield 'key not a string' => ['{"Records":[{"s3":{"bucket":{"name":"b"},"object":{"key":["k"]}}}]}'];
        yield 'key empty' => ['{"Records":[{"s3":{"bucket":{"name":"b"},"object":{"key":""}}}]}'];
        yield 'one good record and one bad' => ['{"Records":[{"s3":{"bucket":{"name":"b"},"object":{"key":"k"}}},{"s3":{}}]}'];
        yield 'test event marker with a wrong type' => ['{"Event":5}'];
        yield 'another event name without Records' => ['{"Event":"s3:ObjectCreated:Put"}'];
    }

    #[DataProvider('nonObjectBodies')]
    public function testNamesTheJsonTypeWhenTheBodyIsNotAnObject(string $body, string $type): void
    {
        try {
            (new S3EventParser())->parse($body);
            self::fail('expected InvalidS3Event');
        } catch (InvalidS3Event $e) {
            self::assertSame(\sprintf('The message body is not an S3 event notification (decoded to %s).', $type), $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function nonObjectBodies(): iterable
    {
        yield 'string' => ['"text"', 'string'];
        yield 'int' => ['42', 'int'];
        yield 'float' => ['1.5', 'float'];
        yield 'bool' => ['true', 'bool'];
        yield 'null' => ['null', 'null'];
        yield 'list' => ['[1,2]', 'list'];
    }

    public function testTheErrorMessageDoesNotEchoTheBody(): void
    {
        try {
            (new S3EventParser())->parse('{"secret":"do-not-leak"}');
            self::fail('expected InvalidS3Event');
        } catch (InvalidS3Event $e) {
            self::assertStringNotContainsString('do-not-leak', $e->getMessage());
        }
    }
}
