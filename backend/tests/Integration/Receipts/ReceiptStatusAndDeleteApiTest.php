<?php

declare(strict_types=1);

namespace App\Tests\Integration\Receipts;

use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Receipts\ReceiptContentType;
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
 * `GET` and `DELETE /expenses/{id}/receipt`, and the cleanup of `DELETE /expenses/{id}`, end to end (nginx -> php-fpm ->
 * DynamoDB Local and the S3 emulator), every call through request() so each answer is checked against the bundled
 * contract. The tests put real objects under `receipts/` (the bucket rule sends an event for each), so the class is
 * `queue-exclusive` like ReceiptUploadApiTest. A ready record is made with the repository, as the worker would.
 * Run through `make test-integration`.
 */
#[Group('queue-exclusive')]
final class ReceiptStatusAndDeleteApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const JSON = ['Content-Type' => 'application/json'];
    private const RECEIPTS_BUCKET = 'receipts';
    private const THUMBNAILS_BUCKET = 'receipt-thumbnails';
    private const PUBLIC_HOST = 'localhost:9000';
    private const BODY = "\x89PNG\r\n\x1A\n" . 'not a real image, only bytes';

    private static bool $sawEvents = false;

    /** @var list<array{string, string}> bucket and key to remove again */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as [$bucket, $key]) {
            self::s3()->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
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

    private static function s3(): S3Client
    {
        return LocalAws::fromGlobals()->s3();
    }

    /** @return string the new user's id; the session cookie of the user is set for the next calls */
    private function signedIn(): string
    {
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'rcs-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
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
        $response = $this->request('POST', '/expenses', ['headers' => self::JSON, 'body' => '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}']);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** Starts an upload (a pending record) and returns its object key. */
    private function pendingReceipt(string $expenseId, string $contentType = 'image/png'): ReceiptObjectKey
    {
        $response = $this->request('POST', '/expenses/' . $expenseId . '/receipt/upload', [
            'headers' => self::JSON,
            'body' => '{"contentType":"' . $contentType . '","size":' . strlen(self::BODY) . '}',
        ]);
        self::assertSame(201, $response->getStatusCode());
        $fields = $this->problemBody($response)['fields'] ?? null;
        self::assertIsArray($fields);
        $key = $fields['key'] ?? null;
        self::assertIsString($key);
        $parsed = ReceiptObjectKey::parse($key);
        self::assertNotNull($parsed);

        return $parsed;
    }

    private function putObject(string $bucket, string $key): void
    {
        self::s3()->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => self::BODY]);
        $this->created[] = [$bucket, $key];
        self::$sawEvents = true;
    }

    private static function exists(string $bucket, string $key): bool
    {
        return self::s3()->doesObjectExistV2($bucket, $key);
    }

    private static function receipts(): ReceiptRepository
    {
        $table = getenv('DYNAMODB_TABLE');
        self::assertIsString($table);

        return new ReceiptRepository(new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $table));
    }

    private static function makeReady(string $userId, ReceiptObjectKey $key, ReceiptContentType $type): void
    {
        self::receipts()->markReady($key, $type, new \DateTimeImmutable(), null);
        self::assertSame('ready', self::receipts()->find(new Principal($userId, str_repeat('a', 64)), $key->expenseId(), new \DateTimeImmutable())?->status->value);
    }

    private function getReceipt(string $expenseId): ResponseInterface
    {
        return $this->request('GET', '/expenses/' . $expenseId . '/receipt');
    }

    private function deleteReceipt(string $expenseId): ResponseInterface
    {
        return $this->request('DELETE', '/expenses/' . $expenseId . '/receipt');
    }

    /** GETs a signed address as a browser would: the internal endpoint, with the public Host header. */
    private static function fetch(string $signedUrl): ResponseInterface
    {
        $internal = new Uri(LocalAws::fromGlobals()->s3Endpoint());
        $url = (string) (new Uri($signedUrl))->withScheme($internal->getScheme())->withHost($internal->getHost())->withPort($internal->getPort());

        return (new HttpClient(['http_errors' => false, 'timeout' => 30, 'allow_redirects' => false]))->get($url, ['headers' => ['Host' => self::PUBLIC_HOST]]);
    }

    public function testAnExpenseWithNoReceiptIs404(): void
    {
        $this->signedIn();
        $expenseId = $this->createExpense();

        $response = $this->getReceipt($expenseId);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAPendingReceiptHasNoUrls(): void
    {
        $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);

        $response = $this->getReceipt($expenseId);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $data = $this->problemBody($response);
        self::assertSame($key->receiptId(), $data['receiptId'] ?? null);
        self::assertSame('pending', $data['status'] ?? null);
        self::assertSame('image/png', $data['contentType'] ?? null);
        self::assertArrayHasKey('viewUrl', $data);
        self::assertNull($data['viewUrl']);
        self::assertArrayHasKey('thumbnailUrl', $data);
        self::assertNull($data['thumbnailUrl']);
        self::assertArrayHasKey('urlsExpireAt', $data);
        self::assertNull($data['urlsExpireAt']);
    }

    public function testAReadyImageGivesWorkingSignedAddressesForTheOriginalAndTheThumbnail(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        $this->putObject(self::THUMBNAILS_BUCKET, $key->thumbnailKey());
        self::makeReady($userId, $key, ReceiptContentType::Png);

        $response = $this->getReceipt($expenseId);

        self::assertSame(200, $response->getStatusCode());
        $data = $this->problemBody($response);
        self::assertSame('ready', $data['status'] ?? null);
        self::assertIsString($data['viewUrl'] ?? null);
        self::assertIsString($data['thumbnailUrl'] ?? null);
        self::assertIsString($data['urlsExpireAt'] ?? null);
        $original = self::fetch($data['viewUrl']);
        self::assertSame(200, $original->getStatusCode());
        self::assertSame('image/png', $original->getHeaderLine('Content-Type'));
        self::assertSame('inline; filename="receipt.png"', $original->getHeaderLine('Content-Disposition'));
        self::assertSame(200, self::fetch($data['thumbnailUrl'])->getStatusCode());
    }

    public function testAReadyPdfHasAViewUrlOnlyServedWithAForcedPdfType(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId, 'application/pdf');
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        self::makeReady($userId, $key, ReceiptContentType::Pdf);

        $data = $this->problemBody($this->getReceipt($expenseId));

        self::assertIsString($data['viewUrl'] ?? null);
        self::assertArrayHasKey('thumbnailUrl', $data);
        self::assertNull($data['thumbnailUrl']);
        self::assertSame('application/pdf', self::fetch($data['viewUrl'])->getHeaderLine('Content-Type'));
    }

    public function testDeleteRemovesTheRecordAndBothFilesThenTheSecondDeleteIs404(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        $this->putObject(self::THUMBNAILS_BUCKET, $key->thumbnailKey());
        self::makeReady($userId, $key, ReceiptContentType::Png);

        $first = $this->deleteReceipt($expenseId);

        self::assertSame(204, $first->getStatusCode());
        self::assertSame('', (string) $first->getBody());
        self::assertFalse(self::exists(self::RECEIPTS_BUCKET, $key->originalKey()));
        self::assertFalse(self::exists(self::THUMBNAILS_BUCKET, $key->thumbnailKey()));
        self::assertSame(404, $this->getReceipt($expenseId)->getStatusCode());
        self::assertSame(404, $this->deleteReceipt($expenseId)->getStatusCode());
    }

    public function testDeleteWithoutTheRequestedWithHeaderIs403AndNothingIsRemoved(): void
    {
        $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        $this->withoutRequestedWith();

        $response = $this->deleteReceipt($expenseId);

        self::assertSame(403, $response->getStatusCode());
        self::assertTrue(self::exists(self::RECEIPTS_BUCKET, $key->originalKey()));
        self::assertSame(200, $this->getReceipt($expenseId)->getStatusCode());
    }

    public function testAnotherUsersDeleteChangesNothing(): void
    {
        $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        $this->signedIn();

        self::assertSame(404, $this->deleteReceipt($expenseId)->getStatusCode());
        self::assertSame(404, $this->getReceipt($expenseId)->getStatusCode());
        self::assertTrue(self::exists(self::RECEIPTS_BUCKET, $key->originalKey()));
    }

    public function testAnotherUsersExpenseDeleteIs404AndTheOwnersFilesStillExist(): void
    {
        $this->signedIn();
        $expenseId = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $this->putObject(self::RECEIPTS_BUCKET, $key->originalKey());
        $this->putObject(self::THUMBNAILS_BUCKET, $key->thumbnailKey());
        $this->signedIn();

        $response = $this->request('DELETE', '/expenses/' . $expenseId);

        self::assertSame(404, $response->getStatusCode());
        self::assertTrue(self::exists(self::RECEIPTS_BUCKET, $key->originalKey()), "the owner's original stays");
        self::assertTrue(self::exists(self::THUMBNAILS_BUCKET, $key->thumbnailKey()), "the owner's thumbnail stays");
    }

    public function testDeletingTheExpenseRemovesEveryFileUnderItsTwoFoldersAndNoOtherExpensesFile(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $otherExpense = $this->createExpense();
        $key = $this->pendingReceipt($expenseId);
        $otherKey = $this->pendingReceipt($otherExpense);
        $stray = 'receipts/' . $userId . '/' . $expenseId . '/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        foreach ([$key->originalKey(), $stray, $otherKey->originalKey()] as $original) {
            $this->putObject(self::RECEIPTS_BUCKET, $original);
        }
        foreach ([$key->thumbnailKey(), $otherKey->thumbnailKey()] as $thumbnail) {
            $this->putObject(self::THUMBNAILS_BUCKET, $thumbnail);
        }

        $response = $this->request('DELETE', '/expenses/' . $expenseId);

        self::assertSame(204, $response->getStatusCode());
        self::assertFalse(self::exists(self::RECEIPTS_BUCKET, $key->originalKey()));
        self::assertFalse(self::exists(self::RECEIPTS_BUCKET, $stray));
        self::assertFalse(self::exists(self::THUMBNAILS_BUCKET, $key->thumbnailKey()));
        self::assertTrue(self::exists(self::RECEIPTS_BUCKET, $otherKey->originalKey()), "the other expense's original stays");
        self::assertTrue(self::exists(self::THUMBNAILS_BUCKET, $otherKey->thumbnailKey()), "the other expense's thumbnail stays");
    }

    public function testDeletingAnExpenseWithNoReceiptIs204AndAnUnknownExpenseTouchesNoFile(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense();
        $someoneElses = 'receipts/' . $userId . '/9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->putObject(self::RECEIPTS_BUCKET, $someoneElses);

        self::assertSame(204, $this->request('DELETE', '/expenses/' . $expenseId)->getStatusCode());
        self::assertSame(404, $this->request('DELETE', '/expenses/9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10')->getStatusCode());
        self::assertTrue(self::exists(self::RECEIPTS_BUCKET, $someoneElses), 'a 404 on the expense cleans nothing');
    }
}
