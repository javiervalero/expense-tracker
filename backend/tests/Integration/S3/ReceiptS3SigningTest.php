<?php

declare(strict_types=1);

namespace App\Tests\Integration\S3;

use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Id\RandomUuidGenerator;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3ObjectStore;
use App\Infrastructure\S3\S3OriginalObjects;
use App\Infrastructure\S3\S3Settings;
use App\Infrastructure\S3\S3ThumbnailObjects;
use App\Infrastructure\S3\SignedUpload;
use App\Infrastructure\S3\UploadPolicySigner;
use App\Infrastructure\S3\ViewUrlSigner;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptObjectKey;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\LocalAws;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The WP3 signers and the object store against the S3 emulator (`make up` first; run through `make test-integration`).
 *
 * Signed for the PUBLIC endpoint (`http://localhost:9000`, what the browser would use) and sent to the internal one
 * (`http://s3:9000`) with `Host: localhost:9000`: the signature covers the host (ADR 0007 note). The API's own path
 * (through the nginx listener) is checked by `make test-api-s3-path`.
 *
 * Keys of the real shape start with `receipts/`, and every object created there makes the emulator emit an event
 * toward the `receipts` queue. Tests that create one set {@see self::$sawEvents}; tearDownAfterClass then waits ONCE
 * (bounded) for the relay to deliver and purges the queue (the runner is stopped during this part of
 * `make test-integration`). Everything else uses keys under `conformance/`, outside the notification rule.
 */
#[Group('queue-exclusive')]
final class ReceiptS3SigningTest extends TestCase
{
    private const RECEIPTS_BUCKET = 'receipts';
    private const THUMBNAILS_BUCKET = 'receipt-thumbnails';
    private const PUBLIC_HOST = 'localhost:9000';
    private const RELAY_DEADLINE_SECONDS = 10;
    private const POLL_INTERVAL_MICROSECONDS = 500_000;
    private const STABLE_POLLS = 3;
    private const JPEG_BODY = "\xFF\xD8\xFF\xE0" . 'a small body that is enough for S3';

    private S3Settings $settings;
    private S3Client $calls;
    private S3ObjectStore $store;
    private UploadPolicySigner $uploads;
    private ViewUrlSigner $views;
    private HttpClient $http;
    private string $internalEndpoint;
    /** Whether any test of the class created an object under `receipts/` (an event is on its way to the queue). */
    private static bool $sawEvents = false;

    /** @var list<array{bucket: string, key: string}> */
    private array $created = [];

    /** @var list<array{bucket: string, prefix: string}> */
    private array $createdPrefixes = [];

    protected function setUp(): void
    {
        $localAws = LocalAws::fromGlobals();
        $this->internalEndpoint = $localAws->s3Endpoint();
        $region = getenv('AWS_REGION');
        $this->settings = S3Settings::fromEnvironment([
            'RECEIPTS_BUCKET' => self::RECEIPTS_BUCKET,
            'THUMBNAILS_BUCKET' => self::THUMBNAILS_BUCKET,
            'S3_ENDPOINT' => $this->internalEndpoint,
            'S3_PUBLIC_ENDPOINT' => $localAws->s3PublicEndpoint(),
            'AWS_REGION' => is_string($region) ? $region : '',
        ]);
        $signing = S3ClientFactory::forSigning($this->settings);
        $this->calls = S3ClientFactory::forCalls($this->settings);
        $this->store = new S3ObjectStore($this->calls);
        $clock = new SystemClock();
        $this->uploads = new UploadPolicySigner($signing, self::RECEIPTS_BUCKET, $clock);
        $this->views = new ViewUrlSigner($signing, self::RECEIPTS_BUCKET, self::THUMBNAILS_BUCKET, $clock);
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => 60, 'allow_redirects' => false]);
    }

    protected function tearDown(): void
    {
        // try/finally: a failing cleanup call must not skip the rest, and the lists are always reset.
        try {
            foreach ($this->createdPrefixes as $folder) {
                $this->store->deletePrefix($folder['bucket'], $folder['prefix']);
            }
        } finally {
            try {
                foreach ($this->created as $object) {
                    $this->store->delete($object['bucket'], $object['key']);
                }
            } finally {
                $this->created = [];
                $this->createdPrefixes = [];
            }
        }
    }

    /**
     * One bounded wait for the whole class: poll the queue's counts until they are above zero and have not changed for
     * a few polls (the relay has delivered what it will), or until the deadline; then purge once.
     */
    public static function tearDownAfterClass(): void
    {
        if (!self::$sawEvents) {
            return;
        }
        self::$sawEvents = false;
        $sqs = LocalAws::fromGlobals()->sqs();
        $queueUrl = getenv('SQS_RECEIPTS_QUEUE_URL');
        if (!is_string($queueUrl)) {
            return;
        }
        $deadline = microtime(true) + self::RELAY_DEADLINE_SECONDS;
        $last = -1;
        $stable = 0;
        while (microtime(true) < $deadline && $stable < self::STABLE_POLLS) {
            $attributes = $sqs->getQueueAttributes(['QueueUrl' => $queueUrl, 'AttributeNames' => ['ApproximateNumberOfMessages', 'ApproximateNumberOfMessagesNotVisible']])->get('Attributes');
            $count = is_array($attributes) ? self::number($attributes['ApproximateNumberOfMessages'] ?? 0) + self::number($attributes['ApproximateNumberOfMessagesNotVisible'] ?? 0) : 0;
            $stable = $count > 0 && $count === $last ? $stable + 1 : 0;
            $last = $count;
            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
        $sqs->purgeQueue(['QueueUrl' => $queueUrl]);
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function newKey(): ReceiptObjectKey
    {
        $ids = new RandomUuidGenerator();

        return ReceiptObjectKey::from($ids->generate(), $ids->generate(), $ids->generate());
    }

    private function trackOriginal(ReceiptObjectKey $key): void
    {
        self::$sawEvents = true;
        $this->created[] = ['bucket' => self::RECEIPTS_BUCKET, 'key' => $key->originalKey()];
    }

    /**
     * POSTs the signed form to the internal endpoint with the public Host, the file part last.
     *
     * @param array<string, string> $fieldOverrides applied after signing (tampering)
     * @param array<string, string> $extraFields unsigned extra fields
     *
     * @return array{status: int, code: ?string}
     */
    private function post(SignedUpload $upload, string $body, array $fieldOverrides = [], array $extraFields = []): array
    {
        $multipart = [];
        foreach (array_merge($upload->fields, $fieldOverrides, $extraFields) as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => $body, 'filename' => 'receipt.bin'];
        $url = (string) (new Uri($upload->url))->withScheme((new Uri($this->internalEndpoint))->getScheme())
            ->withHost((new Uri($this->internalEndpoint))->getHost())
            ->withPort((new Uri($this->internalEndpoint))->getPort());

        $response = $this->http->post($url, ['multipart' => $multipart, 'headers' => ['Host' => self::PUBLIC_HOST]]);
        preg_match('#<Code>(.*?)</Code>#s', (string) $response->getBody(), $code);

        return ['status' => $response->getStatusCode(), 'code' => $code[1] ?? null];
    }

    private function exists(string $bucket, string $key): bool
    {
        return $this->calls->doesObjectExistV2($bucket, $key);
    }

    private function viaTheInternalEndpoint(string $signedUrl): string
    {
        $internal = new Uri($this->internalEndpoint);

        return (string) (new Uri($signedUrl))->withScheme($internal->getScheme())->withHost($internal->getHost())->withPort($internal->getPort());
    }

    public function testThePolicyAcceptsTheExactKeyAndType(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), self::JPEG_BODY);

        self::assertSame(204, $result['status']);
        $head = $this->calls->headObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key->originalKey()]);
        self::assertSame(strlen(self::JPEG_BODY), $head->get('ContentLength'));
        self::assertSame('image/jpeg', $head->get('ContentType'));
    }

    public function testThePolicyRefusesAnotherKey(): void
    {
        $signedFor = $this->newKey();
        $other = $this->newKey();
        $this->trackOriginal($signedFor);
        $this->trackOriginal($other);

        $result = $this->post($this->uploads->sign($signedFor, ReceiptContentType::Jpeg), self::JPEG_BODY, ['key' => $other->originalKey()]);

        self::assertSame(['status' => 403, 'code' => 'AccessDenied'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $other->originalKey()));
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $signedFor->originalKey()));
    }

    public function testThePolicyRefusesAnotherType(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), self::JPEG_BODY, ['Content-Type' => 'text/html']);

        self::assertSame(['status' => 403, 'code' => 'AccessDenied'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testThePolicyRefusesOneByteOverTheLimit(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), str_repeat('x', ReceiptLimits::MAX_BYTES + 1));

        self::assertSame(['status' => 400, 'code' => 'EntityTooLarge'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testThePolicyRefusesAnEmptyFile(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), '');

        self::assertSame(['status' => 400, 'code' => 'EntityTooSmall'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testThePolicyRefusesAnExtraField(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), self::JPEG_BODY, [], ['x-amz-meta-injected' => 'evil']);

        self::assertSame(['status' => 403, 'code' => 'AccessDenied'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testThePolicyAcceptsABodyOfExactlyTheMaximumSize(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);

        $result = $this->post($this->uploads->sign($key, ReceiptContentType::Jpeg), str_repeat('x', ReceiptLimits::MAX_BYTES));

        self::assertSame(204, $result['status']);
        $head = $this->calls->headObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key->originalKey()]);
        self::assertSame(ReceiptLimits::MAX_BYTES, $head->get('ContentLength'));
    }

    public function testAnExpiredPolicyIsRefused(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);
        $past = new UploadPolicySigner(S3ClientFactory::forSigning($this->settings), self::RECEIPTS_BUCKET, new FrozenClock('-1 hour'));

        $result = $this->post($past->sign($key, ReceiptContentType::Jpeg), self::JPEG_BODY);

        self::assertSame(['status' => 403, 'code' => 'AccessDenied'], $result);
        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testTheViewUrlReturnsTheBytesWithTheForcedTypeAndName(): void
    {
        $key = $this->newKey();
        $this->trackOriginal($key);
        // Stored with a type that would run script in a browser: the URL must override it.
        $this->calls->putObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key->originalKey(), 'Body' => '%PDF-1.7 bytes', 'ContentType' => 'text/html']);
        $signed = $this->views->original($key, ReceiptContentType::Pdf);

        $response = $this->http->get($this->viaTheInternalEndpoint($signed->url), ['headers' => ['Host' => self::PUBLIC_HOST]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('%PDF-1.7 bytes', (string) $response->getBody());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="receipt.pdf"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheThumbnailViewUrlReturnsAJpeg(): void
    {
        $key = $this->newKey();
        $this->created[] = ['bucket' => self::THUMBNAILS_BUCKET, 'key' => $key->thumbnailKey()];
        $this->calls->putObject(['Bucket' => self::THUMBNAILS_BUCKET, 'Key' => $key->thumbnailKey(), 'Body' => 'thumb', 'ContentType' => 'text/plain']);

        $response = $this->http->get($this->viaTheInternalEndpoint($this->views->thumbnail($key)->url), ['headers' => ['Host' => self::PUBLIC_HOST]]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('thumb', (string) $response->getBody());
        self::assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="receipt.jpg"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAViewUrlWithAnotherTypeInTheQueryIsRefused(): void
    {
        $key = $this->newKey();
        $this->created[] = ['bucket' => self::THUMBNAILS_BUCKET, 'key' => $key->thumbnailKey()];
        $this->calls->putObject(['Bucket' => self::THUMBNAILS_BUCKET, 'Key' => $key->thumbnailKey(), 'Body' => 'SECRET-BYTES']);
        $url = $this->viaTheInternalEndpoint($this->views->thumbnail($key)->url);

        $tampered = str_replace('response-content-type=image%2Fjpeg', 'response-content-type=text%2Fhtml', $url);
        self::assertNotSame($url, $tampered, 'the test must really change the URL');

        $response = $this->http->get($tampered, ['headers' => ['Host' => self::PUBLIC_HOST]]);

        self::assertSame(403, $response->getStatusCode());
        self::assertStringNotContainsString('SECRET-BYTES', (string) $response->getBody());
    }

    public function testDeletePrefixRemovesTheFolderAndLeavesASiblingPrefix(): void
    {
        $run = 'conformance/' . bin2hex(random_bytes(6));
        foreach ([self::RECEIPTS_BUCKET, self::THUMBNAILS_BUCKET] as $bucket) {
            $this->createdPrefixes[] = ['bucket' => $bucket, 'prefix' => $run . '/'];
            foreach (['e1/a', 'e1/b', 'e1/deeper/c', 'e10/a'] as $name) {
                $this->calls->putObject(['Bucket' => $bucket, 'Key' => $run . '/' . $name, 'Body' => 'x']);
            }

            $removed = $this->store->deletePrefix($bucket, $run . '/e1/');

            self::assertSame(3, $removed, $bucket);
            self::assertFalse($this->exists($bucket, $run . '/e1/a'), $bucket);
            self::assertFalse($this->exists($bucket, $run . '/e1/deeper/c'), $bucket);
            self::assertTrue($this->exists($bucket, $run . '/e10/a'), $bucket . ': the sibling prefix e10/ must survive');
        }
    }

    public function testDeletePrefixOnAnEmptyFolderSucceedsAndDeletesNothing(): void
    {
        $removed = $this->store->deletePrefix(self::RECEIPTS_BUCKET, 'conformance/' . bin2hex(random_bytes(6)) . '/nothing-here/');

        self::assertSame(0, $removed);
    }

    public function testTheStoreRoundTripsAndReportsAMissingKeyAsNull(): void
    {
        $key = 'conformance/' . bin2hex(random_bytes(6));
        $this->created[] = ['bucket' => self::RECEIPTS_BUCKET, 'key' => $key];

        self::assertNull($this->store->get(self::RECEIPTS_BUCKET, $key));
        $this->store->put(self::RECEIPTS_BUCKET, $key, 'hello', 'text/plain');

        self::assertSame('hello', $this->store->get(self::RECEIPTS_BUCKET, $key));
        $this->store->delete(self::RECEIPTS_BUCKET, $key);
        self::assertNull($this->store->get(self::RECEIPTS_BUCKET, $key));
    }

    public function testTheCleanerRemovesTheOriginalAndTheThumbnailOfAnExpense(): void
    {
        $key = $this->newKey();
        $other = ReceiptObjectKey::from($key->userId(), $this->newKey()->expenseId(), $key->receiptId());
        $this->trackOriginal($key);
        $this->trackOriginal($other);
        $this->created[] = ['bucket' => self::THUMBNAILS_BUCKET, 'key' => $key->thumbnailKey()];
        $this->created[] = ['bucket' => self::THUMBNAILS_BUCKET, 'key' => $other->thumbnailKey()];
        foreach ([$key, $other] as $each) {
            $this->calls->putObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $each->originalKey(), 'Body' => 'o']);
            $this->calls->putObject(['Bucket' => self::THUMBNAILS_BUCKET, 'Key' => $each->thumbnailKey(), 'Body' => 't']);
        }
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $cleaner = new ReceiptObjectCleaner(
            new S3OriginalObjects($this->store, $this->settings),
            new S3ThumbnailObjects($this->store, $this->settings),
            new JsonLogger($stream),
            $this->settings,
        );

        $cleaner->removeExpense($key->userId(), $key->expenseId());

        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $key->originalKey()));
        self::assertFalse($this->exists(self::THUMBNAILS_BUCKET, $key->thumbnailKey()));
        self::assertTrue($this->exists(self::RECEIPTS_BUCKET, $other->originalKey()), 'another expense of the same user is untouched');
        self::assertTrue($this->exists(self::THUMBNAILS_BUCKET, $other->thumbnailKey()));
        rewind($stream);
        self::assertSame('', stream_get_contents($stream), 'a clean run logs nothing');

        $cleaner->removeReceipt($other);

        self::assertFalse($this->exists(self::RECEIPTS_BUCKET, $other->originalKey()));
        self::assertFalse($this->exists(self::THUMBNAILS_BUCKET, $other->thumbnailKey()));
    }
}
