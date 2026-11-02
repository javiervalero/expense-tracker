<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\Clock\Deadline;
use App\Infrastructure\S3\ObjectStoreFailure;
use App\Infrastructure\S3\S3ObjectStore;
use App\Tests\Support\ExpiringClock;
use App\Tests\Support\S3\RecordingS3;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Aws\Command;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class S3ObjectStoreTest extends TestCase
{
    private const BUCKET = 'receipts-bucket';

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'localdev', 'AWS_SECRET_ACCESS_KEY' => 'localdevsecret', 'AWS_EC2_METADATA_DISABLED' => 'true'] as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private static function error(string $operation, string $code, int $status = 403): S3Exception
    {
        return new S3Exception('raw SDK text with https://host/?X-Amz-Signature=abc', new Command($operation), ['code' => $code, 'request_id' => 'REQ1', 'response' => new \GuzzleHttp\Psr7\Response($status)]);
    }

    /**
     * @param list<string> $keys
     *
     * @return Result<string, mixed>
     */
    private static function page(array $keys, ?string $nextToken = null): Result
    {
        $result = ['Contents' => array_map(static fn(string $key): array => ['Key' => $key], $keys), 'IsTruncated' => $nextToken !== null];
        if ($nextToken !== null) {
            $result['NextContinuationToken'] = $nextToken;
        }

        return new Result($result);
    }

    private static function deletedObjects(RecordingS3 $s3, int $call): mixed
    {
        $delete = $s3->calls[$call]['args']['Delete'] ?? null;

        return is_array($delete) ? ($delete['Objects'] ?? null) : null;
    }

    public function testGetReturnsTheBody(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => \GuzzleHttp\Psr7\Utils::streamFor('bytes'), 'ContentLength' => 5])]);

        $body = (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b');

        self::assertSame('bytes', $body);
        self::assertSame(['GetObject'], $s3->operations());
        self::assertSame(['Bucket' => self::BUCKET, 'Key' => 'a/b'], array_intersect_key($s3->calls[0]['args'], ['Bucket' => 1, 'Key' => 1]));
    }

    public function testGetObjectReturnsTheBodyAndTheEtagWithoutItsQuotes(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => Utils::streamFor('bytes'), 'ContentLength' => 5, 'ETag' => '"5d41402abc4b2a76b9719d911017c592"'])]);

        $object = (new S3ObjectStore($s3->client()))->getObject(self::BUCKET, 'a/b', 100);

        self::assertNotNull($object);
        self::assertSame('bytes', $object->body);
        self::assertSame('5d41402abc4b2a76b9719d911017c592', $object->etag);
    }

    public function testGetObjectWithoutAnEtagInTheAnswerIsAFailureToRetry(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => Utils::streamFor('x'), 'ContentLength' => 1])]);

        try {
            (new S3ObjectStore($s3->client()))->getObject(self::BUCKET, 'a/b');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('GetObject', $failure->operation);
            self::assertSame('UnexpectedResponse', $failure->errorCode);
        }
    }

    public function testGetObjectOfAGoneKeyIsNullAndPlainGetNeedsNoEtag(): void
    {
        $s3 = new RecordingS3([self::error('GetObject', 'NoSuchKey', 404), new Result(['Body' => Utils::streamFor('x'), 'ContentLength' => 1])]);
        $store = new S3ObjectStore($s3->client());

        self::assertNull($store->getObject(self::BUCKET, 'a/b'));
        self::assertSame('x', $store->get(self::BUCKET, 'a/b'));
    }

    public function testEtagSendsOneHeadObjectAndReturnsTheEtagWithoutQuotes(): void
    {
        $s3 = new RecordingS3([new Result(['ETag' => '"abc-2"'])]);

        $etag = (new S3ObjectStore($s3->client()))->etag(self::BUCKET, 'a/b');

        self::assertSame('abc-2', $etag);
        self::assertSame(['HeadObject'], $s3->operations());
        self::assertSame(['Bucket' => self::BUCKET, 'Key' => 'a/b'], array_intersect_key($s3->calls[0]['args'], ['Bucket' => 1, 'Key' => 1]));
    }

    public function testEtagIsNullWhenTheKeyIsGone(): void
    {
        $s3 = new RecordingS3([self::error('HeadObject', 'NotFound', 404)]);

        self::assertNull((new S3ObjectStore($s3->client()))->etag(self::BUCKET, 'a/b'));
    }

    public function testEtagReportsOtherErrorsWithoutTheSdkText(): void
    {
        $s3 = new RecordingS3([self::error('HeadObject', 'AccessDenied')]);

        try {
            (new S3ObjectStore($s3->client()))->etag(self::BUCKET, 'a/b');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('HeadObject', $failure->operation);
            self::assertSame('AccessDenied', $failure->errorCode);
            self::assertStringNotContainsString('Signature', $failure->getMessage());
        }
    }

    public function testEtagFailsWhenTheAnswerHasNone(): void
    {
        $s3 = new RecordingS3([new Result([])]);

        $this->expectException(ObjectStoreFailure::class);

        (new S3ObjectStore($s3->client()))->etag(self::BUCKET, 'a/b');
    }

    public function testGetReturnsNullWhenTheKeyIsGone(): void
    {
        $s3 = new RecordingS3([self::error('GetObject', 'NoSuchKey', 404)]);

        self::assertNull((new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b'));
    }

    public function testGetReportsOtherErrorsWithoutTheSdkText(): void
    {
        $s3 = new RecordingS3([self::error('GetObject', 'AccessDenied')]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('GetObject', $failure->operation);
            self::assertSame('AccessDenied', $failure->errorCode);
            self::assertStringNotContainsString('http', $failure->getMessage());
            self::assertStringNotContainsString('Signature', $failure->getMessage());
            self::assertNull($failure->getPrevious());
        }
    }

    public function testPutSendsTheBodyAndTheType(): void
    {
        $s3 = new RecordingS3();

        (new S3ObjectStore($s3->client()))->put('thumbs-bucket', 'u/e/r.jpg', 'jpegbytes', 'image/jpeg');

        self::assertSame(['PutObject'], $s3->operations());
        $args = $s3->calls[0]['args'];
        self::assertSame('thumbs-bucket', $args['Bucket']);
        self::assertSame('u/e/r.jpg', $args['Key']);
        self::assertSame('jpegbytes', $args['Body']);
        self::assertSame('image/jpeg', $args['ContentType']);
    }

    public function testDeleteSendsOneDeleteObject(): void
    {
        $s3 = new RecordingS3();

        (new S3ObjectStore($s3->client()))->delete(self::BUCKET, 'a/b');

        self::assertSame(['DeleteObject'], $s3->operations());
        self::assertSame('a/b', $s3->calls[0]['args']['Key']);
    }

    public function testDeleteFailureIsAnObjectStoreFailure(): void
    {
        $s3 = new RecordingS3([self::error('DeleteObject', 'AccessDenied')]);

        $this->expectException(ObjectStoreFailure::class);

        (new S3ObjectStore($s3->client()))->delete(self::BUCKET, 'a/b');
    }

    public function testDeletePrefixListsAPageDeletesItAndFollowsTheContinuationToken(): void
    {
        $s3 = new RecordingS3([
            self::page(['u/e1/a', 'u/e1/b'], 'TOKEN-1'),
            new Result(['Deleted' => [['Key' => 'u/e1/a'], ['Key' => 'u/e1/b']]]),
            self::page(['u/e1/c']),
            new Result(['Deleted' => [['Key' => 'u/e1/c']]]),
        ]);

        $removed = (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');

        self::assertSame(3, $removed);
        self::assertSame(['ListObjectsV2', 'DeleteObjects', 'ListObjectsV2', 'DeleteObjects'], $s3->operations());
        self::assertSame('u/e1/', $s3->calls[0]['args']['Prefix']);
        self::assertSame(1000, $s3->calls[0]['args']['MaxKeys']);
        self::assertArrayNotHasKey('ContinuationToken', $s3->calls[0]['args']);
        self::assertSame([['Key' => 'u/e1/a'], ['Key' => 'u/e1/b']], self::deletedObjects($s3, 1));
        self::assertSame('TOKEN-1', $s3->calls[2]['args']['ContinuationToken']);
        self::assertSame('u/e1/', $s3->calls[2]['args']['Prefix']);
        self::assertSame([['Key' => 'u/e1/c']], self::deletedObjects($s3, 3));
    }

    public function testDeletePrefixSkipsDeleteObjectsWhenThePageIsEmpty(): void
    {
        $s3 = new RecordingS3([new Result(['IsTruncated' => false, 'KeyCount' => 0])]);

        $removed = (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');

        self::assertSame(0, $removed);
        self::assertSame(['ListObjectsV2'], $s3->operations());
    }

    public function testDeletePrefixSkipsAnEmptyPageButStillFollowsTheToken(): void
    {
        $s3 = new RecordingS3([self::page([], 'T'), self::page(['u/e1/a']), new Result(['Deleted' => [['Key' => 'u/e1/a']]])]);

        $removed = (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');

        self::assertSame(1, $removed);
        self::assertSame(['ListObjectsV2', 'ListObjectsV2', 'DeleteObjects'], $s3->operations());
    }

    public function testDeletePrefixFailsWhenDeleteObjectsReportsAnErrorInsideA200(): void
    {
        $s3 = new RecordingS3([self::page(['u/e1/a']), new Result(['Errors' => [['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'no']]])]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeleteObjects', $failure->operation);
            self::assertSame('AccessDenied', $failure->errorCode);
        }
    }

    public function testDeletePrefixStopsAfterTheLastAllowedPageAndReportsTheLeftover(): void
    {
        $answers = [];
        for ($page = 1; $page <= 3; ++$page) {
            $answers[] = self::page(['u/e1/k' . $page], 'T' . $page);
            $answers[] = new Result(['Deleted' => [['Key' => 'u/e1/k' . $page]]]);
        }
        // A fourth page exists, but one request must not page without end: it is never asked for.
        $answers[] = self::page(['u/e1/k4'], 'T4');
        $s3 = new RecordingS3($answers);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('ListObjectsV2', $failure->operation);
            self::assertSame('PageLimitReached', $failure->errorCode);
        }
        self::assertSame(['ListObjectsV2', 'DeleteObjects', 'ListObjectsV2', 'DeleteObjects', 'ListObjectsV2', 'DeleteObjects'], $s3->operations());
    }

    public function testDeletePrefixReportsBothPerKeyErrorsAndThePageLimit(): void
    {
        $answers = [self::page(['u/e1/a'], 'T1'), new Result(['Errors' => [['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'no']]])];
        for ($page = 2; $page <= 3; ++$page) {
            $answers[] = self::page(['u/e1/k' . $page], 'T' . $page);
            $answers[] = new Result(['Deleted' => [['Key' => 'u/e1/k' . $page]]]);
        }
        $s3 = new RecordingS3($answers);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeleteObjects', $failure->operation);
            self::assertSame('AccessDenied,PageLimitReached', $failure->errorCode);
            self::assertSame(1, $failure->errorCount);
        }
        self::assertCount(6, $s3->calls, 'three pages were handled, no fourth was listed');
    }

    public function testDeletePrefixStopsBeforeAPageOnceTheDeadlineHasPassed(): void
    {
        // Read 1 builds the deadline, read 2 is the check before page 1, read 3 the check after its listing; the check
        // before page 2 finds it passed.
        $deadline = Deadline::after(new ExpiringClock(3), 8);
        $s3 = new RecordingS3([self::page(['u/e1/a'], 'T1'), new Result(['Deleted' => [['Key' => 'u/e1/a']]]), self::page(['u/e1/b'])]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/', $deadline);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('ListObjectsV2', $failure->operation);
            self::assertSame('DeadlineReached', $failure->errorCode);
        }
        self::assertSame(['ListObjectsV2', 'DeleteObjects'], $s3->operations());
    }

    public function testDeletePrefixDoesNotSendDeleteObjectsWhenTheDeadlinePassesAfterTheListing(): void
    {
        // Read 1 builds the deadline, read 2 is the check before the listing, read 3 (after the listing) finds it passed.
        $deadline = Deadline::after(new ExpiringClock(2), 8);
        $s3 = new RecordingS3([self::page(['u/e1/a']), new Result(['Deleted' => [['Key' => 'u/e1/a']]])]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/', $deadline);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('ListObjectsV2', $failure->operation);
            self::assertSame('DeadlineReached', $failure->errorCode);
        }
        self::assertSame(['ListObjectsV2'], $s3->operations(), 'no DeleteObjects after the deadline');
    }

    public function testDeletePrefixWithAPassedDeadlineMakesNoCallAtAll(): void
    {
        $s3 = new RecordingS3([self::page(['u/e1/a'])]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/', Deadline::after(new ExpiringClock(1), 8));
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeadlineReached', $failure->errorCode);
        }
        self::assertSame([], $s3->operations());
    }

    public function testDeletePrefixReportsBothPerKeyErrorsAndTheDeadline(): void
    {
        $deadline = Deadline::after(new ExpiringClock(3), 8);
        $s3 = new RecordingS3([self::page(['u/e1/a'], 'T1'), new Result(['Errors' => [['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'no']]])]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/', $deadline);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeleteObjects', $failure->operation);
            self::assertSame('AccessDenied,DeadlineReached', $failure->errorCode);
        }
    }

    public function testDeletePrefixFinishesWithoutErrorWhenTheLastPageEndsJustAsTheDeadlinePasses(): void
    {
        // One page, no token: nothing is left, so a deadline that passes afterwards is not a failure.
        $deadline = Deadline::after(new ExpiringClock(3), 8);
        $s3 = new RecordingS3([self::page(['u/e1/a']), new Result(['Deleted' => [['Key' => 'u/e1/a']]])]);

        self::assertSame(1, (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/', $deadline));
    }

    public function testDeletePrefixFailsOnATruncatedListingWithoutAToken(): void
    {
        $s3 = new RecordingS3([new Result(['Contents' => [['Key' => 'u/e1/a']], 'IsTruncated' => true])]);

        $this->expectException(ObjectStoreFailure::class);

        (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
    }

    public function testDeletePrefixReportsAListingError(): void
    {
        $s3 = new RecordingS3([self::error('ListObjectsV2', 'AccessDenied')]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('ListObjectsV2', $failure->operation);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function prefixesWithoutATrailingSlash(): iterable
    {
        yield 'empty' => [''];
        yield 'bare slash' => ['/'];
        yield 'no trailing slash' => ['e1'];
        yield 'a full key' => ['receipts/u/e/r'];
        yield 'slash inside only' => ['/e1'];
        yield 'one segment only' => ['e1/'];
        yield 'two slashes in a row' => ['u//e1/'];
        yield 'ends with two slashes' => ['u/e1//'];
        yield 'leading empty segment' => ['/u/e1/'];
        yield 'no slash at the end, two segments' => ['u/e1'];
    }

    #[DataProvider('prefixesWithoutATrailingSlash')]
    public function testDeletePrefixRefusesAPrefixThatIsNotAFolder(string $prefix): void
    {
        $s3 = new RecordingS3();

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, $prefix);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $s3->calls, 'nothing is sent for a refused prefix');
        }
    }

    public function testPutFailureIsAnObjectStoreFailure(): void
    {
        $s3 = new RecordingS3([self::error('PutObject', 'AccessDenied')]);

        try {
            (new S3ObjectStore($s3->client()))->put('thumbs-bucket', 'k', 'x', 'image/jpeg');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('PutObject', $failure->operation);
        }
    }

    public function testAFailureThatIsNotAnS3ErrorHasNoCode(): void
    {
        $s3 = new RecordingS3([new \Aws\Exception\CredentialsException('Error retrieving credentials from the instance profile metadata service. http://169.254.169.254/x')]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertNull($failure->errorCode);
            self::assertStringNotContainsString('169', $failure->getMessage());
        }
    }

    public function testDeleteObjectsFailureIsAnObjectStoreFailure(): void
    {
        $s3 = new RecordingS3([self::page(['u/e1/a']), self::error('DeleteObjects', 'InternalError', 400)]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeleteObjects', $failure->operation);
        }
    }

    public function testAResultWithoutABodyKeepsItsOwnCode(): void
    {
        $s3 = new RecordingS3([new Result(['ContentLength' => 5])]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('GetObject', $failure->operation);
            self::assertSame('UnexpectedResponse', $failure->errorCode);
        }
    }

    public function testGetRefusesAnObjectOverTheLimitBeforeReadingItsBody(): void
    {
        $body = FnStream::decorate(Utils::streamFor('x'), [
            'getContents' => static fn() => throw new \LogicException('the body must not be read'),
            '__toString' => static fn() => throw new \LogicException('the body must not be read'),
            'read' => static fn() => throw new \LogicException('the body must not be read'),
        ]);
        $s3 = new RecordingS3([new Result(['Body' => $body, 'ContentLength' => 11])]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('TooLarge', $failure->errorCode);
        }
    }

    public function testGetAcceptsAnObjectExactlyAtTheLimit(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => Utils::streamFor('0123456789'), 'ContentLength' => 10])]);

        self::assertSame('0123456789', (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10));
    }

    public function testGetRefusesABodyOverTheLimitEvenWithoutAContentLength(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => Utils::streamFor('0123456789A')])]);

        $this->expectException(ObjectStoreFailure::class);

        (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10);
    }

    public function testAnS3ErrorKeepsStatusRequestIdAndClassButNotTheMessage(): void
    {
        $s3 = new RecordingS3([self::error('PutObject', 'AccessDenied', 403)]);

        try {
            (new S3ObjectStore($s3->client()))->put(self::BUCKET, 'k', 'x', 'image/jpeg');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame(403, $failure->statusCode);
            self::assertSame('REQ1', $failure->requestId);
            self::assertSame(S3Exception::class, $failure->exceptionClass);
            self::assertSame(1, $failure->errorCount);
        }
    }

    public function testAFailureThatIsNotAnS3ErrorHasAClassAndNoStatus(): void
    {
        $s3 = new RecordingS3([new \Aws\Exception\CredentialsException('boom http://169.254.169.254/x')]);

        try {
            (new S3ObjectStore($s3->client()))->delete(self::BUCKET, 'k');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertNull($failure->errorCode);
            self::assertNull($failure->statusCode);
            self::assertNull($failure->requestId);
            self::assertSame(\Aws\Exception\CredentialsException::class, $failure->exceptionClass);
        }
    }

    public function testNoFailureOfARealSdkCallHoldsAUrlASignatureOrAPolicy(): void
    {
        $leaky = static fn(string $operation): S3Exception => new S3Exception(
            'Error executing "' . $operation . '" on "https://b.s3.amazonaws.com/k?X-Amz-Signature=abc&Policy=zz"',
            new Command($operation),
            ['code' => 'AccessDenied', 'response' => new \GuzzleHttp\Psr7\Response(403)],
        );
        $calls = [
            'put' => [[$leaky('PutObject')], static fn(S3ObjectStore $store) => $store->put(self::BUCKET, 'k', 'x', 'image/jpeg')],
            'delete' => [[$leaky('DeleteObject')], static fn(S3ObjectStore $store) => $store->delete(self::BUCKET, 'k')],
            'get' => [[$leaky('GetObject')], static fn(S3ObjectStore $store) => $store->get(self::BUCKET, 'k')],
            'list' => [[$leaky('ListObjectsV2')], static fn(S3ObjectStore $store) => $store->deletePrefix(self::BUCKET, 'u/e1/')],
            'delete many' => [[$leaky('DeleteObjects')], static fn(S3ObjectStore $store) => $store->deletePrefix(self::BUCKET, 'u/e1/')],
            'errors in a 200' => [
                [self::page(['u/e1/a']), new Result(['Errors' => [['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'https://x/?X-Amz-Signature=abc']]])],
                static fn(S3ObjectStore $store) => $store->deletePrefix(self::BUCKET, 'u/e1/'),
            ],
        ];

        foreach ($calls as $name => [$answers, $call]) {
            $answers = $name === 'delete many' ? [self::page(['u/e1/a']), $answers[0]] : $answers;
            try {
                $call(new S3ObjectStore((new RecordingS3($answers))->client()));
                self::fail("Expected ObjectStoreFailure for {$name}");
            } catch (ObjectStoreFailure $failure) {
                $text = $failure->getMessage() . json_encode([$failure->operation, $failure->errorCode, $failure->exceptionClass, $failure->requestId]);
                foreach (['http', 'Signature', 'Policy'] as $forbidden) {
                    self::assertStringNotContainsStringIgnoringCase($forbidden, $text, $name);
                }
                self::assertNull($failure->getPrevious(), $name);
            }
        }
    }

    public function testDeletePrefixKeepsGoingAfterErrorsInA200AndThrowsOnceAtTheEnd(): void
    {
        $s3 = new RecordingS3([
            self::page(['u/e1/a', 'u/e1/b'], 'T'),
            new Result(['Errors' => [['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'no']]]),
            self::page(['u/e1/c']),
            new Result(['Deleted' => [['Key' => 'u/e1/c']]]),
        ]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('DeleteObjects', $failure->operation);
            self::assertSame('AccessDenied', $failure->errorCode);
            self::assertSame(1, $failure->errorCount);
        }

        self::assertSame(['ListObjectsV2', 'DeleteObjects', 'ListObjectsV2', 'DeleteObjects'], $s3->operations(), 'page 2 is still deleted');
        self::assertSame([['Key' => 'u/e1/c']], self::deletedObjects($s3, 3));
    }

    public function testDeletePrefixReportsTheCountAndTheDistinctCodes(): void
    {
        $s3 = new RecordingS3([
            self::page(['u/e1/a', 'u/e1/b', 'u/e1/c']),
            new Result(['Errors' => [
                ['Key' => 'u/e1/a', 'Code' => 'AccessDenied', 'Message' => 'x'],
                ['Key' => 'u/e1/b', 'Code' => 'AccessDenied', 'Message' => 'x'],
                ['Key' => 'u/e1/c', 'Code' => 'InternalError', 'Message' => 'x'],
            ]]),
        ]);

        try {
            (new S3ObjectStore($s3->client()))->deletePrefix(self::BUCKET, 'u/e1/');
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame(3, $failure->errorCount);
            self::assertSame('AccessDenied,InternalError', $failure->errorCode);
        }
    }

    /**
     * A stream that counts the bytes read from it and notes when it is closed.
     */
    private static function spyStream(string $content, int &$bytesRead, bool &$closed, bool $failOnRead = false): \Psr\Http\Message\StreamInterface
    {
        $inner = Utils::streamFor($content);

        return FnStream::decorate($inner, [
            'read' => static function (int $length) use ($inner, &$bytesRead, $failOnRead): string {
                if ($failOnRead) {
                    throw new \RuntimeException('connection reset while reading https://b.s3.amazonaws.com/k?X-Amz-Signature=abc');
                }
                $chunk = $inner->read($length);
                $bytesRead += strlen($chunk);

                return $chunk;
            },
            'getContents' => static function () use ($inner, $failOnRead): string {
                if ($failOnRead) {
                    throw new \RuntimeException('connection reset while reading https://b.s3.amazonaws.com/k?X-Amz-Signature=abc');
                }

                return $inner->getContents();
            },
            'close' => static function () use ($inner, &$closed): void {
                $closed = true;
                $inner->close();
            },
        ]);
    }

    public function testABodyOverTheLimitWithoutAContentLengthIsReadOnlyUpToTheLimitPlusOne(): void
    {
        $bytesRead = 0;
        $closed = false;
        $s3 = new RecordingS3([new Result(['Body' => self::spyStream(str_repeat('x', 1_000_000), $bytesRead, $closed)])]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('TooLarge', $failure->errorCode);
        }

        self::assertLessThanOrEqual(11, $bytesRead, 'a decompression bomb must not be inflated in memory');
        self::assertTrue($closed);
    }

    public function testTheRequestDoesNotDecodeTheContentEncoding(): void
    {
        $s3 = new RecordingS3([new Result(['Body' => Utils::streamFor('x'), 'ContentLength' => 1])]);

        (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10);

        $http = $s3->calls[0]['args']['@http'] ?? null;
        self::assertIsArray($http);
        self::assertFalse($http['decode_content'] ?? null);
        self::assertTrue($http['stream'] ?? null);
    }

    public function testTheBodyIsClosedAfterASuccessfulRead(): void
    {
        $bytesRead = 0;
        $closed = false;
        $s3 = new RecordingS3([new Result(['Body' => self::spyStream('0123456789', $bytesRead, $closed), 'ContentLength' => 10])]);

        self::assertSame('0123456789', (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10));

        self::assertTrue($closed);
    }

    public function testTheBodyIsClosedAfterAnUnlimitedRead(): void
    {
        $bytesRead = 0;
        $closed = false;
        $s3 = new RecordingS3([new Result(['Body' => self::spyStream('abc', $bytesRead, $closed)])]);

        self::assertSame('abc', (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b'));

        self::assertTrue($closed);
    }

    public function testTheBodyIsClosedWhenTheContentLengthIsOverTheLimit(): void
    {
        $bytesRead = 0;
        $closed = false;
        $s3 = new RecordingS3([new Result(['Body' => self::spyStream('x', $bytesRead, $closed), 'ContentLength' => 11])]);

        try {
            (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', 10);
            self::fail('Expected ObjectStoreFailure');
        } catch (ObjectStoreFailure $failure) {
            self::assertSame('TooLarge', $failure->errorCode);
        }

        self::assertSame(0, $bytesRead);
        self::assertTrue($closed);
    }

    public function testAReadErrorMidStreamIsAFailureWithoutTheMessageAndTheBodyIsClosed(): void
    {
        foreach ([10, null] as $limit) {
            $bytesRead = 0;
            $closed = false;
            $s3 = new RecordingS3([new Result(['Body' => self::spyStream('0123456789', $bytesRead, $closed, failOnRead: true)])]);

            try {
                (new S3ObjectStore($s3->client()))->get(self::BUCKET, 'a/b', $limit);
                self::fail('Expected ObjectStoreFailure');
            } catch (ObjectStoreFailure $failure) {
                self::assertSame('GetObject', $failure->operation);
                self::assertNull($failure->errorCode);
                self::assertSame(\RuntimeException::class, $failure->exceptionClass);
                self::assertNull($failure->getPrevious());
                foreach (['http', 'Signature', 'reset'] as $forbidden) {
                    self::assertStringNotContainsStringIgnoringCase($forbidden, $failure->getMessage());
                }
            }
            self::assertTrue($closed, 'limit ' . var_export($limit, true));
        }
    }
}
