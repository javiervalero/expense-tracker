<?php

declare(strict_types=1);

namespace App\Tests\Integration\Receipts;

use App\Receipts\FailureReason;
use App\Receipts\Receipt;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptStatus;
use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\Receipts\ReceiptFixtures;
use App\Tests\Support\ReceiptWorld as World;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * The receipt life cycle with the real worker: API policy -> multipart POST to the S3 emulator (internal endpoint, public
 * Host) -> relay -> queue -> `worker-runner` -> worker -> record and thumbnail. Every API call goes through request(), so
 * it is also checked against the contract. The record is polled for at most 15 s. The class is in the group
 * `needs-runner`: the runner MUST be running (`make test-integration` starts it for this part).
 *
 * Markers for `make test-integration` (build/pipe-test/): the key of the JPEG that became ready, the key of the HTML file
 * that failed (the worker's log must hold `receipt ready` / `receipt failed` for them), and the policy and signature
 * values of one real upload form (no log of api-php or worker may hold them, C15).
 */
#[Group('needs-runner')]
final class ReceiptLifecycleTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse staple 42';
    private const array JSON = ['Content-Type' => 'application/json'];
    private const string HTML = '<html><body>not an image</body></html>';

    /** @var list<array{string, string}> user and expense whose files are removed again */
    private array $created = [];

    protected function tearDown(): void
    {
        foreach ($this->created as [$userId, $expenseId]) {
            World::deleteEverythingOf($userId, $expenseId);
        }
        $this->created = [];
    }

    public function testAJpegBecomesReadyWithAThumbnail(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $file = ReceiptFixtures::jpeg(40, 30);
        $upload = $this->started($expenseId, 'image/jpeg', strlen($file));
        foreach (['Policy' => 'policy-value.txt', 'X-Amz-Signature' => 'signature-value.txt'] as $field => $marker) {
            self::assertNotSame('', $upload['fields'][$field] ?? '', "the form has a $field value");
            World::writeMarker($marker, $upload['fields'][$field]);
        }
        World::writeMarker('ready-key.txt', $upload['key']->originalKey());

        $status = World::browserUpload($upload['url'], $upload['fields'], $file);

        self::assertSame(204, $status);
        $receipt = $this->awaitSettled($userId, $expenseId);
        self::assertSame(ReceiptStatus::Ready, $receipt->status);
        self::assertTrue(World::exists(World::RECEIPTS_BUCKET, $upload['key']->originalKey()));
        self::assertTrue(World::exists(World::THUMBNAILS_BUCKET, $upload['key']->thumbnailKey()));
    }

    public function testAPdfBecomesReadyWithoutAThumbnail(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $file = ReceiptFixtures::pdf();
        $upload = $this->started($expenseId, 'application/pdf', strlen($file));

        $status = World::browserUpload($upload['url'], $upload['fields'], $file);

        self::assertSame(204, $status);
        self::assertSame(ReceiptStatus::Ready, $this->awaitSettled($userId, $expenseId)->status);
        self::assertTrue(World::exists(World::RECEIPTS_BUCKET, $upload['key']->originalKey()));
        self::assertFalse(World::exists(World::THUMBNAILS_BUCKET, $upload['key']->thumbnailKey()));
    }

    public function testAnHtmlFileDeclaredAsPngFailsAndItsOriginalIsRemoved(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $upload = $this->started($expenseId, 'image/png', strlen(self::HTML));
        World::writeMarker('failed-key.txt', $upload['key']->originalKey());

        $status = World::browserUpload($upload['url'], $upload['fields'], self::HTML);

        self::assertSame(204, $status);
        $receipt = $this->awaitSettled($userId, $expenseId);
        self::assertSame(ReceiptStatus::Failed, $receipt->status);
        self::assertSame(FailureReason::UnsupportedType, $receipt->failureReason);
        World::eventually(fn(): bool => !World::exists(World::RECEIPTS_BUCKET, $upload['key']->originalKey()), 'the rejected original is removed');
        self::assertFalse(World::exists(World::THUMBNAILS_BUCKET, $upload['key']->thumbnailKey()));
    }

    public function testAnUploadAfterTheRecordWasDeletedLeavesNoObjectBehind(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $file = ReceiptFixtures::jpeg(40, 30);
        $upload = $this->started($expenseId, 'image/jpeg', strlen($file));
        self::assertSame(204, $this->request('DELETE', '/expenses/' . $expenseId . '/receipt')->getStatusCode());

        $status = World::browserUpload($upload['url'], $upload['fields'], $file);

        self::assertSame(204, $status, 'S3 still accepts the signed form');
        World::eventually(fn(): bool => !World::exists(World::RECEIPTS_BUCKET, $upload['key']->originalKey()), 'the orphan original is removed');
        self::assertFalse(World::exists(World::THUMBNAILS_BUCKET, $upload['key']->thumbnailKey()));
        self::assertNull(World::receipt($userId, $expenseId));
    }

    public function testAnOldUploadArrivingAfterTheReplaceIsRemovedByTheWorkerAndTheNewReceiptBecomesReady(): void
    {
        // Arrange: the replace happens BEFORE the old file reaches S3, so the API has nothing to delete; only the worker can.
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $first = ReceiptFixtures::jpeg(40, 30);
        $old = $this->started($expenseId, 'image/jpeg', strlen($first));
        $second = ReceiptFixtures::png(30, 20);
        $new = $this->started($expenseId, 'image/png', strlen($second));

        // Act: the old policy is still valid; its event is processed while the record belongs to the new upload.
        // The 204 proves S3 stored the file; no "exists" check here, because the worker may already have removed it.
        self::assertSame(204, World::browserUpload($old['url'], $old['fields'], $first));

        // Assert: C10 step 7 removed it, and the old receipt was not resurrected.
        World::eventually(fn(): bool => !World::exists(World::RECEIPTS_BUCKET, $old['key']->originalKey()), 'the worker removes the old original');
        World::eventually(fn(): bool => !World::exists(World::THUMBNAILS_BUCKET, $old['key']->thumbnailKey()), 'the old thumbnail is not left');
        $current = World::receipt($userId, $expenseId);
        self::assertSame($new['key']->receiptId(), $current?->receiptId());
        self::assertSame(ReceiptStatus::Pending, $current->status);
        self::assertSame(204, World::browserUpload($new['url'], $new['fields'], $second));
        $receipt = $this->awaitSettled($userId, $expenseId);
        self::assertSame($new['key']->receiptId(), $receipt->receiptId());
        self::assertSame(ReceiptStatus::Ready, $receipt->status);
        World::eventually(fn(): bool => !World::exists(World::RECEIPTS_BUCKET, $old['key']->originalKey()), 'the old original stays gone');
        World::eventually(fn(): bool => !World::exists(World::THUMBNAILS_BUCKET, $old['key']->thumbnailKey()), 'the old thumbnail stays gone');
        self::assertTrue(World::exists(World::THUMBNAILS_BUCKET, $new['key']->thumbnailKey()));
    }

    public function testSendingTheSameS3EventAgainChangesNothing(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $file = ReceiptFixtures::jpeg(40, 30);
        $upload = $this->started($expenseId, 'image/jpeg', strlen($file));
        self::assertSame(204, World::browserUpload($upload['url'], $upload['fields'], $file));
        $before = $this->awaitSettled($userId, $expenseId);

        World::sendS3Event($upload['key']);
        World::waitUntilQueueDrained();

        $after = World::receipt($userId, $expenseId);
        self::assertSame(ReceiptStatus::Ready, $after?->status);
        self::assertEquals($before->processedAt, $after->processedAt, 'the record was not rewritten');
        self::assertSame($before->objectEtag, $after->objectEtag, 'the stored file is the one that was read');
        self::assertSame($before->status, $after->status);
        self::assertSame(0, World::messagesInDeadLetterQueue(), 'the duplicate is accepted, not dead-lettered');
        self::assertTrue(World::exists(World::RECEIPTS_BUCKET, $upload['key']->originalKey()));
        self::assertTrue(World::exists(World::THUMBNAILS_BUCKET, $upload['key']->thumbnailKey()));
    }

    public function testDeletingTheExpenseAfterReadyEmptiesBothPrefixes(): void
    {
        $userId = $this->signedIn();
        $expenseId = $this->createExpense($userId);
        $this->readyJpeg($userId, $expenseId);

        $response = $this->request('DELETE', '/expenses/' . $expenseId);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame([], World::keysUnder(World::RECEIPTS_BUCKET, ReceiptObjectKey::originalPrefix($userId, $expenseId)));
        self::assertSame([], World::keysUnder(World::THUMBNAILS_BUCKET, ReceiptObjectKey::thumbnailPrefix($userId, $expenseId)));
    }

    public function testAnotherUsersGetDeleteAndUploadAre404AndTheOwnersObjectsStay(): void
    {
        $ownerId = $this->signedIn();
        $expenseId = $this->createExpense($ownerId);
        $key = $this->readyJpeg($ownerId, $expenseId);
        $this->signedIn();

        $get = $this->request('GET', '/expenses/' . $expenseId . '/receipt');
        $delete = $this->request('DELETE', '/expenses/' . $expenseId . '/receipt');
        $post = $this->request('POST', '/expenses/' . $expenseId . '/receipt/upload', ['headers' => self::JSON, 'body' => '{"contentType":"image/jpeg","size":100}']);

        self::assertSame([404, 404, 404], [$get->getStatusCode(), $delete->getStatusCode(), $post->getStatusCode()]);
        $this->assertSameAsUnknownExpense([$get, $delete, $post], '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10');
        self::assertTrue(World::exists(World::RECEIPTS_BUCKET, $key->originalKey()), "the owner's original stays");
        self::assertTrue(World::exists(World::THUMBNAILS_BUCKET, $key->thumbnailKey()), "the owner's thumbnail stays");
        self::assertSame(ReceiptStatus::Ready, World::receipt($ownerId, $expenseId)?->status, "the owner's record stays");
    }

    /**
     * The three answers must be the generic "not found" problem, byte for byte what an unknown expense id gives on the
     * same routes (as CrossUserIsolationApiTest compares), so a routing 404 cannot pass.
     *
     * @param list<ResponseInterface> $foreign GET, DELETE and POST answers
     */
    private function assertSameAsUnknownExpense(array $foreign, string $unknownId): void
    {
        $unknown = [
            $this->request('GET', '/expenses/' . $unknownId . '/receipt'),
            $this->request('DELETE', '/expenses/' . $unknownId . '/receipt'),
            $this->request('POST', '/expenses/' . $unknownId . '/receipt/upload', ['headers' => self::JSON, 'body' => '{"contentType":"image/jpeg","size":100}']),
        ];
        foreach ($foreign as $position => $response) {
            self::assertSame(404, $unknown[$position]->getStatusCode());
            self::assertSame((string) $unknown[$position]->getBody(), (string) $response->getBody(), 'same body as an unknown expense, call ' . $position);
            self::assertNotSame('', (string) $response->getBody());
        }
    }

    /** @return string the new user's id; the session cookie of the user is set for the next calls */
    private function signedIn(): string
    {
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'rcl-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function createExpense(string $userId): string
    {
        $response = $this->request('POST', '/expenses', ['headers' => self::JSON, 'body' => '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}']);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);
        $this->created[] = [$userId, $id];

        return $id;
    }

    /** @return array{key: ReceiptObjectKey, url: string, fields: array<string, string>} */
    private function started(string $expenseId, string $contentType, int $size): array
    {
        $response = $this->request('POST', '/expenses/' . $expenseId . '/receipt/upload', [
            'headers' => self::JSON,
            'body' => json_encode(['contentType' => $contentType, 'size' => $size], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());

        return self::policyOf($this->problemBody($response));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array{key: ReceiptObjectKey, url: string, fields: array<string, string>}
     */
    private static function policyOf(array $data): array
    {
        self::assertIsArray($data['fields'] ?? null);
        $fields = [];
        foreach ($data['fields'] as $name => $value) {
            self::assertIsString($value);
            $fields[(string) $name] = $value;
        }
        self::assertIsString($data['url'] ?? null);
        $key = ReceiptObjectKey::parse($fields['key'] ?? '');
        self::assertNotNull($key, 'the policy key is a receipt key');

        return ['key' => $key, 'url' => $data['url'], 'fields' => $fields];
    }

    /** Uploads a valid JPEG for the expense and waits until the worker made it ready. */
    private function readyJpeg(string $userId, string $expenseId): ReceiptObjectKey
    {
        $file = ReceiptFixtures::jpeg(40, 30);
        $upload = $this->started($expenseId, 'image/jpeg', strlen($file));
        self::assertSame(204, World::browserUpload($upload['url'], $upload['fields'], $file));
        self::assertSame(ReceiptStatus::Ready, $this->awaitSettled($userId, $expenseId)->status);

        return $upload['key'];
    }

    /** Polls the record (at most 15 s) until the worker moved it out of `pending`. */
    private function awaitSettled(string $userId, string $expenseId): Receipt
    {
        return World::eventually(
            static function () use ($userId, $expenseId) {
                $receipt = World::receipt($userId, $expenseId);

                return $receipt !== null && $receipt->status !== ReceiptStatus::Pending ? $receipt : null;
            },
            'the worker settles the record',
        );
    }
}
