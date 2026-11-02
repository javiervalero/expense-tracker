<?php

declare(strict_types=1);

namespace App\Tests\Integration\Receipts;

use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Id\Uuid;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\LocalAws;
use App\Tests\Support\ReceiptQueueDrain;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\Uri;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * `POST /expenses/{id}/receipt/upload` end to end (nginx -> php-fpm -> DynamoDB Local and the S3 emulator), every call
 * through request(), so each response is also checked against the bundled contract. Each test registers its own users.
 * The files the tests upload are real objects under `receipts/`, so the class is `queue-exclusive` like
 * ReceiptS3SigningTest: the bucket rule sends an event for each, and the queue is purged once at the end.
 * Run through `make test-integration`.
 */
#[Group('queue-exclusive')]
final class ReceiptUploadApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const JSON = ['Content-Type' => 'application/json'];
    private const RECEIPTS_BUCKET = 'receipts';
    private const PUBLIC_HOST = 'localhost:9000';
    private const JPEG_BODY = "\xFF\xD8\xFF\xE0" . 'a small body that is enough for S3';

    private static bool $sawEvents = false;

    /** @var list<string> original keys to remove again */
    private array $created = [];

    protected function tearDown(): void
    {
        $s3 = LocalAws::fromGlobals()->s3();
        foreach ($this->created as $key) {
            $s3->deleteObject(['Bucket' => self::RECEIPTS_BUCKET, 'Key' => $key]);
        }
        $this->created = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$sawEvents) {
            self::$sawEvents = false;
            ReceiptQueueDrain::afterEvents();
        }
    }

    /** @return string the new user's id; the session cookie of the user is set for the next calls */
    private function signedIn(): string
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'rcp-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createExpense(): string
    {
        $response = $this->request('POST', '/expenses', [
            'headers' => self::JSON,
            'body' => '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}',
        ]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** The body that declares a JPEG of exactly `$size` bytes: the policy accepts that size and no other. */
    private static function declaring(int $size): string
    {
        return '{"contentType":"image/jpeg","size":' . $size . '}';
    }

    private function startUpload(string $expenseId, ?string $body = null): ResponseInterface
    {
        return $this->request('POST', '/expenses/' . $expenseId . '/receipt/upload', ['headers' => self::JSON, 'body' => $body ?? self::declaring(strlen(self::JPEG_BODY))]);
    }

    /** @return array{receiptId: string, url: string, fields: array<string, string>} */
    private function started(string $expenseId): array
    {
        $response = $this->startUpload($expenseId);
        self::assertSame(201, $response->getStatusCode());
        $data = $this->problemBody($response);
        $fields = [];
        self::assertIsArray($data['fields'] ?? null);
        foreach ($data['fields'] as $name => $value) {
            self::assertIsString($value);
            $fields[(string) $name] = $value;
        }
        self::assertIsString($fields['key'] ?? null);
        self::assertIsString($data['receiptId'] ?? null);
        self::assertIsString($data['url'] ?? null);
        $this->created[] = $fields['key'];
        self::$sawEvents = true;

        return ['receiptId' => $data['receiptId'], 'url' => $data['url'], 'fields' => $fields];
    }

    private static function s3(): S3Client
    {
        return LocalAws::fromGlobals()->s3();
    }

    /**
     * POSTs the form as a browser would (file part last), to the internal endpoint with the public Host.
     *
     * @param array{receiptId: string, url: string, fields: array<string, string>} $upload
     */
    private static function uploadFile(array $upload): int
    {
        $multipart = [];
        foreach ($upload['fields'] as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => self::JPEG_BODY, 'filename' => 'receipt.jpg'];
        $internal = new Uri(LocalAws::fromGlobals()->s3Endpoint());
        $url = (string) (new Uri($upload['url']))->withScheme($internal->getScheme())->withHost($internal->getHost())->withPort($internal->getPort());
        $http = new HttpClient(['http_errors' => false, 'timeout' => 60, 'allow_redirects' => false]);

        return $http->post($url, ['multipart' => $multipart, 'headers' => ['Host' => self::PUBLIC_HOST]])->getStatusCode();
    }

    private static function receipts(): ReceiptRepository
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);

        return new ReceiptRepository(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table));
    }

    private static function stored(string $userId, string $expenseId): ?string
    {
        $receipt = self::receipts()->find(new Principal($userId, str_repeat('a', 64)), $expenseId, new \DateTimeImmutable());

        return $receipt?->receiptId();
    }

    public function testAnUploadStartAnswers201WithLocationNoStoreAndTheSignedForm(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();

        $response = $this->startUpload($expenseId);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('/api/v1/expenses/' . $expenseId . '/receipt', $response->getHeaderLine('Location'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = $this->problemBody($response);
        self::assertIsString($data['receiptId'] ?? null);
        self::assertTrue(Uuid::isValid($data['receiptId']));
        self::assertSame(10_485_760, $data['maxBytes'] ?? null);
        self::assertIsArray($data['fields'] ?? null);
        self::assertSame('receipts/' . $userId . '/' . $expenseId . '/' . $data['receiptId'], $data['fields']['key'] ?? null);
        self::assertSame('image/jpeg', $data['fields']['Content-Type'] ?? null);
        self::assertSame($data['receiptId'], self::stored($userId, $expenseId), 'the pending record is stored');
    }

    public function testAnotherUsersExpenseIsTheGeneric404AndNoRecordIsWritten(): void
    {
        $ownerId = $this->signedIn();
        $expenseId = $this->createExpense();
        $intruderId = $this->signedIn();

        $foreign = $this->startUpload($expenseId);
        $unknown = $this->startUpload('9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10');

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $foreign->getBody());
        self::assertNull(self::stored($ownerId, $expenseId), "nothing under the owner's partition");
        self::assertNull(self::stored($intruderId, $expenseId), "nothing under the intruder's partition");
    }

    public function testWithoutTheRequestedWithHeaderTheAnswerIs403AndNothingIsWritten(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $this->withoutRequestedWith();

        $response = $this->startUpload($expenseId);

        self::assertSame(403, $response->getStatusCode());
        self::assertNull(self::stored($userId, $expenseId));
    }

    public function testAnInvalidBodyIs422AtItsPointerAndNothingIsWritten(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();

        $response = $this->startUpload($expenseId, '{"contentType":"image/gif","size":0}');

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertSame(['/contentType', '/size'], array_map(static fn(mixed $error): mixed => is_array($error) ? $error['pointer'] ?? null : null, $errors));
        self::assertNull(self::stored($userId, $expenseId));
    }

    public function testReplacingRemovesTheOldOriginalAndKeepsTheNewRecord(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $first = $this->started($expenseId);
        self::assertSame(204, self::uploadFile($first), 'the browser upload of the first file');
        $firstKey = ReceiptObjectKey::parse($first['fields']['key']);
        self::assertNotNull($firstKey);
        self::assertTrue(self::s3()->doesObjectExistV2(self::RECEIPTS_BUCKET, $firstKey->originalKey()));

        $second = $this->started($expenseId);

        self::assertNotSame($first['receiptId'], $second['receiptId']);
        self::assertFalse(self::s3()->doesObjectExistV2(self::RECEIPTS_BUCKET, $firstKey->originalKey()), 'the old original is gone');
        self::assertSame($second['receiptId'], self::stored($userId, $expenseId));
    }
}
