<?php

declare(strict_types=1);

namespace App\Tests\Conformance\S3;

use App\Tests\Support\LocalAws;
use Aws\Credentials\CredentialsInterface;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers of the S3 conformance suite: SDK client, presigned POST building and sending, S3 error parsing.
 * The suite checks what the application relies on from the S3 emulator (ADR 0006, ADR 0007); it runs against the
 * `s3` service of the Compose stack (`make up` first, then `make test-conformance`).
 *
 * The receipts bucket has a notification rule for keys under `receipts/` (the receipt pipeline, ADR 0006): every
 * object created there queues an event for the worker. So the tests use keys under `conformance/`, outside the
 * rule, and every object a test may have created is deleted in tearDown (deletes emit no event). Only the one
 * test that proves the real key shape uses a `receipts/` key (see {@see self::newReceiptShapedKey()}).
 */
abstract class S3ConformanceCase extends TestCase
{
    protected const RECEIPTS_BUCKET = 'receipts';
    protected const THUMBNAILS_BUCKET = 'receipt-thumbnails';
    protected const MIN_SIZE = 10;
    protected const MAX_SIZE = 1024;
    protected const CONTENT_TYPE = 'image/jpeg';
    private const HTTP_TIMEOUT_SECONDS = 20;

    protected LocalAws $localAws;
    protected S3Client $s3;
    protected HttpClient $http;

    /** @var list<array{bucket: string, key: string}> every object a test may have created, deleted in tearDown */
    private array $trackedObjects = [];

    protected function setUp(): void
    {
        $this->localAws = LocalAws::fromGlobals();
        $this->s3 = $this->localAws->s3();
        $this->http = new HttpClient(['http_errors' => false, 'timeout' => self::HTTP_TIMEOUT_SECONDS]);
    }

    protected function tearDown(): void
    {
        // Deleting a key that does not exist succeeds, so every tracked key can be deleted unconditionally.
        foreach ($this->trackedObjects as $object) {
            $this->s3->deleteObject(['Bucket' => $object['bucket'], 'Key' => $object['key']]);
        }
        $this->trackedObjects = [];
    }

    /**
     * A key OUTSIDE the notification rule (`conformance/...`): creating it emits no event for the worker.
     */
    protected function newKey(string $bucket = self::RECEIPTS_BUCKET): string
    {
        $key = 'conformance/' . bin2hex(random_bytes(8));
        $this->trackObject($bucket, $key);

        return $key;
    }

    /**
     * A key shaped like the real ones (ADR 0006): receipts/<userId>/<expenseId>/<receiptId>. Creating an object
     * under it DOES emit an event (the bucket rule matches the `receipts/` prefix), so use it only where that is
     * the point of the test.
     */
    protected function newReceiptShapedKey(): string
    {
        $id = bin2hex(random_bytes(4));
        $key = "receipts/user-{$id}/expense-{$id}/receipt-{$id}";
        $this->trackObject(self::RECEIPTS_BUCKET, $key);

        return $key;
    }

    protected function trackObject(string $bucket, string $key): void
    {
        $this->trackedObjects[] = ['bucket' => $bucket, 'key' => $key];
    }

    /**
     * @param array<string, string> $overrides
     */
    protected function presignedPost(string $key, string $expiration = '+10 minutes', array $overrides = [], ?S3Client $signer = null): PostObjectV4
    {
        $inputs = ['key' => $key, 'Content-Type' => self::CONTENT_TYPE] + $overrides;
        $conditions = [
            ['bucket' => self::RECEIPTS_BUCKET],
            ['eq', '$key', $key],
            ['eq', '$Content-Type', self::CONTENT_TYPE],
            ['content-length-range', self::MIN_SIZE, self::MAX_SIZE],
        ];

        return new PostObjectV4($signer ?? $this->s3, self::RECEIPTS_BUCKET, $inputs, $conditions, $expiration);
    }

    /**
     * Sends the form as multipart (the file part last, as S3 requires).
     *
     * @param array<string, string> $fieldOverrides applied after signing (tampering)
     * @param array<string, string> $extraFields    unsigned extra fields
     * @param list<string>          $omitFields     names of fields removed from the form after signing
     * @param string|null           $actionUrl      replaces the form's action URL (posting to another bucket)
     *
     * @return array{status: int, code: ?string, message: ?string}
     */
    protected function sendPost(
        PostObjectV4 $form,
        string $body,
        array $fieldOverrides = [],
        array $extraFields = [],
        array $omitFields = [],
        ?string $actionUrl = null,
    ): array {
        $fields = array_diff_key(
            array_merge($form->getFormInputs(), $fieldOverrides, $extraFields),
            array_flip($omitFields),
        );
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => (string) $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => $body, 'filename' => 'receipt.jpg'];

        $response = $this->http->post($actionUrl ?? self::formAction($form), ['multipart' => $multipart]);

        return self::summarise($response->getStatusCode(), (string) $response->getBody());
    }

    protected static function formAction(PostObjectV4 $form): string
    {
        $action = $form->getFormAttributes()['action'] ?? null;
        self::assertIsString($action, 'the presigned form must carry its action URL');

        return $action;
    }

    protected static function formInput(PostObjectV4 $form, string $name): string
    {
        $value = $form->getFormInputs()[$name] ?? null;
        self::assertIsString($value, "the presigned form must carry {$name}");

        return $value;
    }

    /**
     * @return array{status: int, code: ?string, message: ?string}
     */
    protected static function summarise(int $status, string $body): array
    {
        preg_match('#<Code>(.*?)</Code>#s', $body, $code);
        preg_match('#<Message>(.*?)</Message>#s', $body, $message);

        return ['status' => $status, 'code' => $code[1] ?? null, 'message' => $message[1] ?? null];
    }

    protected function objectExists(string $key, string $bucket = self::RECEIPTS_BUCKET): bool
    {
        return $this->s3->doesObjectExistV2($bucket, $key);
    }

    /**
     * A client with the same endpoint, region and access key but another secret: its signatures are wrong.
     */
    protected function s3ClientWithSecret(string $secret): S3Client
    {
        $credentials = $this->s3->getCredentials()->wait();
        self::assertInstanceOf(CredentialsInterface::class, $credentials);

        return new S3Client([
            'version' => '2006-03-01',
            'region' => $this->s3->getRegion(),
            'endpoint' => $this->localAws->s3Endpoint(),
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $credentials->getAccessKeyId(), 'secret' => $secret],
        ]);
    }

    protected static function validBody(): string
    {
        return str_repeat('x', 100);
    }
}
