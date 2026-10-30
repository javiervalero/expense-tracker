<?php

declare(strict_types=1);

namespace App\Tests\Integration\Receipts;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseKeys;
use App\Expenses\ExpenseNotFound;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Receipts\FailureReason;
use App\Receipts\Receipt;
use App\Receipts\ReceiptChanged;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptNotPending;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use App\Receipts\ReceiptStatus;
use App\Receipts\SettleResult;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use Aws\CommandInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * ReceiptRepository against DynamoDB Local, on a table of its own. Ids and times are invented.
 */
final class ReceiptRepositoryTest extends TestCase
{
    private const USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_USER = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const EXPENSE = '5c8e1f3a-2b4d-4a6c-8e0f-1a2b3c4d5e6f';
    private const FIRST = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    private const SECOND = 'a1b2c3d4-e5f6-4789-8abc-def012345678';
    private const NOW = '2026-10-29T12:00:00+00:00';

    private TemporaryTable $table;
    private ItemStore $store;
    private ReceiptRepository $receipts;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->receipts = new ReceiptRepository($this->store);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function owner(string $userId = self::USER): Principal
    {
        return new Principal($userId, str_repeat('a', 64));
    }

    private static function now(int $offsetSeconds = 0): DateTimeImmutable
    {
        return (new DateTimeImmutable(self::NOW))->modify(sprintf('%+d seconds', $offsetSeconds));
    }

    private static function keyOf(string $receiptId, string $userId = self::USER): ReceiptObjectKey
    {
        return ReceiptObjectKey::from($userId, self::EXPENSE, $receiptId);
    }

    /** A pending receipt created at NOW, expiring 24 h later (C2). */
    private static function pending(string $receiptId, string $userId = self::USER): Receipt
    {
        return new Receipt(self::keyOf($receiptId, $userId), ReceiptStatus::Pending, ReceiptContentType::Png, 1234, self::now(), self::now(86_400), null, null);
    }

    private function putExpense(string $userId = self::USER): void
    {
        $this->store->putItem(ExpenseKeys::expense(self::owner($userId), self::EXPENSE) + ['expenseId' => self::EXPENSE]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rawReceipt(string $userId = self::USER): array
    {
        return $this->store->getItem(ExpenseKeys::receipt(self::owner($userId), self::EXPENSE)) ?? [];
    }

    /**
     * A repository whose transaction is preceded, once, by `$interference` (another request that wins the race between
     * the read and the write).
     *
     * @param callable(): mixed $interference
     */
    private function racingRepository(callable $interference): ReceiptRepository
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $pending = true;
        $client->getHandlerList()->appendInit(static function (callable $next) use (&$pending, $interference): callable {
            return static function (CommandInterface $command) use ($next, &$pending, $interference): mixed {
                if ($pending && $command->getName() === 'TransactWriteItems') {
                    $pending = false;
                    $interference();
                }

                return $next($command);
            };
        }, 'race');

        return new ReceiptRepository(new ItemStore($client, $this->table->name));
    }

    // startUpload, find

    public function testStartUploadStoresAPendingRecordThatFindReturns(): void
    {
        $this->putExpense();

        $old = $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        self::assertNull($old);
        $found = $this->receipts->find(self::owner(), self::EXPENSE, self::now(60));
        self::assertNotNull($found);
        self::assertSame(self::FIRST, $found->receiptId());
        self::assertSame(ReceiptStatus::Pending, $found->status);
        self::assertSame(ReceiptContentType::Png, $found->contentType);
        self::assertSame(1234, $found->size);
        self::assertSame(self::now(86_400)->getTimestamp(), $found->expiresAt?->getTimestamp());
    }

    public function testStartUploadOnAMissingExpenseIsExpenseNotFoundAndWritesNothing(): void
    {
        try {
            $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
            self::fail('A receipt needs its expense.');
        } catch (ExpenseNotFound) {
            self::assertSame([], $this->rawReceipt());
        }
    }

    public function testStartUploadOverAnExistingReceiptReplacesItAndReturnsTheOldKey(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        $old = $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND));

        self::assertSame(self::keyOf(self::FIRST)->originalKey(), $old?->originalKey());
        self::assertSame(self::SECOND, $this->receipts->find(self::owner(), self::EXPENSE, self::now(60))?->receiptId());
    }

    public function testStartUploadWhenTheReceiptChangedAfterTheReadIsReceiptChanged(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $racing = $this->racingRepository(fn() => $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND)));

        try {
            $racing->startUpload(self::owner(), self::EXPENSE, self::pending('d4e5f607-1829-4a3b-9c4d-5e6f70819203'));
            self::fail('A write over a receipt that changed must not go through.');
        } catch (ReceiptChanged) {
            self::assertSame(self::SECOND, $this->receipts->find(self::owner(), self::EXPENSE, self::now(60))?->receiptId());
        }
    }

    public function testStartUploadWhenTheFirstReceiptAppearedAfterTheReadIsReceiptChanged(): void
    {
        $this->putExpense();
        $racing = $this->racingRepository(fn() => $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST)));

        $this->expectException(ReceiptChanged::class);

        $racing->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND));
    }

    public function testFindHidesExpiredPendingAndFailedRecordsButNotReadyOnes(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        self::assertNotNull($this->receipts->find(self::owner(), self::EXPENSE, self::now(86_399)));
        self::assertNull($this->receipts->find(self::owner(), self::EXPENSE, self::now(86_400)));

        $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::Unreadable, self::now(60));
        self::assertNotNull($this->receipts->find(self::owner(), self::EXPENSE, self::now(60 + 604_799)));
        self::assertNull($this->receipts->find(self::owner(), self::EXPENSE, self::now(60 + 604_800)));

        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND));
        $this->receipts->markReady(self::keyOf(self::SECOND), ReceiptContentType::Png, self::now(120));
        self::assertNotNull($this->receipts->find(self::owner(), self::EXPENSE, self::now(10 * 365 * 86_400)));
    }

    // remove

    public function testRemoveReturnsTheOldRecordThenNull(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        $old = $this->receipts->remove(self::owner(), self::EXPENSE);

        self::assertSame(self::FIRST, $old?->receiptId());
        self::assertSame([], $this->rawReceipt());
        self::assertNull($this->receipts->remove(self::owner(), self::EXPENSE));
    }

    public function testRemoveReturnsAnExpiredPendingRecordNotYetDeletedByTtlAndClearsIt(): void
    {
        $this->putExpense();
        $expired = new Receipt(self::keyOf(self::FIRST), ReceiptStatus::Pending, ReceiptContentType::Png, 1234, self::now(-7_200), self::now(-3_600), null, null);
        $this->receipts->startUpload(self::owner(), self::EXPENSE, $expired);

        $old = $this->receipts->remove(self::owner(), self::EXPENSE);

        self::assertNotNull($old);
        self::assertSame(self::FIRST, $old->receiptId());
        self::assertFalse($old->isLive(self::now()));
        self::assertSame([], $this->rawReceipt());
    }

    public function testRemoveOfACorruptStoredItemIsAStorageFailureAndTheItemIsGone(): void
    {
        $this->store->putItem(ExpenseKeys::receipt(self::owner(), self::EXPENSE) + ['receiptId' => self::FIRST, 'objectKey' => 'receipts/x/y/z', 'status' => 'pending']);

        try {
            $this->receipts->remove(self::owner(), self::EXPENSE);
            self::fail('A corrupt item must not be returned.');
        } catch (StorageFailure) {
            self::assertSame([], $this->rawReceipt());
        }
    }

    // markReady, markFailed

    public function testMarkReadySettlesOnceAndAnotherCallReportsAlreadyProcessed(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        $first = $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Jpeg, self::now(60));
        $second = $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Jpeg, self::now(61));

        self::assertSame(SettleResult::Applied, $first);
        self::assertSame(SettleResult::AlreadyProcessed, $second);
        $ready = $this->receipts->findByKey(self::keyOf(self::FIRST));
        self::assertSame(ReceiptStatus::Ready, $ready?->status);
        self::assertSame(ReceiptContentType::Jpeg, $ready->contentType);
        self::assertNull($ready->expiresAt);
        self::assertNull($ready->failureReason);
        self::assertSame(self::now(60)->getTimestamp(), $ready->processedAt?->getTimestamp());
        self::assertArrayNotHasKey('expiresAt', $this->rawReceipt());
    }

    public function testMarkFailedSettlesOnceWithASevenDayExpiry(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        $first = $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::UnsupportedType, self::now(60));
        $second = $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::Unreadable, self::now(61));

        self::assertSame(SettleResult::Applied, $first);
        self::assertSame(SettleResult::AlreadyProcessed, $second);
        $failed = $this->receipts->findByKey(self::keyOf(self::FIRST));
        self::assertSame(ReceiptStatus::Failed, $failed?->status);
        self::assertSame(FailureReason::UnsupportedType, $failed->failureReason);
        self::assertSame(self::now(60 + 604_800)->getTimestamp(), $failed->expiresAt?->getTimestamp());
        self::assertSame(ReceiptContentType::Png, $failed->contentType);
    }

    public function testMarkReadyAfterMarkFailedReportsAlreadyProcessed(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::Unreadable, self::now(60));

        self::assertSame(SettleResult::AlreadyProcessed, $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Png, self::now(61)));
        self::assertSame(ReceiptStatus::Failed, $this->receipts->findByKey(self::keyOf(self::FIRST))?->status);
    }

    public function testMarkReadyForAReplacedKeyFailsAndLeavesTheNewRecordPending(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND));

        try {
            $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Png, self::now(60));
            self::fail('A replaced upload must not settle the new record.');
        } catch (ReceiptNotPending) {
            $current = $this->receipts->findByKey(self::keyOf(self::SECOND));
            self::assertSame(self::SECOND, $current?->receiptId());
            self::assertSame(ReceiptStatus::Pending, $current->status);
        }
    }

    public function testMarkFailedForAReplacedKeyFails(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::SECOND));

        $this->expectException(ReceiptNotPending::class);

        $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::Unreadable, self::now(60));
    }

    public function testMarkReadyOfAnExpiredPendingRecordFailsAndLeavesItPending(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        try {
            $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Png, self::now(86_400));
            self::fail('An expired pending record must not become ready.');
        } catch (ReceiptNotPending) {
            self::assertSame(ReceiptStatus::Pending, $this->receipts->findByKey(self::keyOf(self::FIRST))?->status);
        }
    }

    public function testMarkFailedOfAnExpiredPendingRecordFails(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));

        $this->expectException(ReceiptNotPending::class);

        $this->receipts->markFailed(self::keyOf(self::FIRST), FailureReason::Unreadable, self::now(86_401));
    }

    public function testMarkReadyOfAMissingRecordFailsAndCreatesNothing(): void
    {
        try {
            $this->receipts->markReady(self::keyOf(self::FIRST), ReceiptContentType::Png, self::now(60));
            self::fail('There is nothing to settle.');
        } catch (ReceiptNotPending) {
            self::assertSame([], $this->rawReceipt());
        }
    }

    // corrupt

    public function testACorruptStoredItemIsAStorageFailure(): void
    {
        $this->store->putItem(ExpenseKeys::receipt(self::owner(), self::EXPENSE) + ['receiptId' => self::FIRST, 'objectKey' => 'receipts/x/y/z', 'status' => 'pending']);

        $this->expectException(StorageFailure::class);

        $this->receipts->find(self::owner(), self::EXPENSE, self::now());
    }

    // cross-user

    public function testAnotherUsersPrincipalNeverReadsOrChangesTheReceipt(): void
    {
        $this->putExpense();
        $this->putExpense(self::OTHER_USER);
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $intruder = self::owner(self::OTHER_USER);

        self::assertNull($this->receipts->find($intruder, self::EXPENSE, self::now(60)));
        self::assertNull($this->receipts->remove($intruder, self::EXPENSE));
        self::assertNull($this->receipts->startUpload($intruder, self::EXPENSE, self::pending(self::SECOND, self::OTHER_USER)));

        $mine = $this->receipts->find(self::owner(), self::EXPENSE, self::now(60));
        self::assertSame(self::FIRST, $mine?->receiptId());
        self::assertSame(self::SECOND, $this->receipts->find($intruder, self::EXPENSE, self::now(60))?->receiptId());
    }

    public function testAnotherUsersReceiptKeyCannotSettleOrReadTheOwnersRecord(): void
    {
        $this->putExpense();
        $this->receipts->startUpload(self::owner(), self::EXPENSE, self::pending(self::FIRST));
        $foreignKey = self::keyOf(self::FIRST, self::OTHER_USER);

        self::assertNull($this->receipts->findByKey($foreignKey));
        try {
            $this->receipts->markReady($foreignKey, ReceiptContentType::Png, self::now(60));
            self::fail('A key under another user must not settle this record.');
        } catch (ReceiptNotPending) {
            self::assertSame(ReceiptStatus::Pending, $this->receipts->findByKey(self::keyOf(self::FIRST))?->status);
        }
    }

    public function testAnotherUsersPrincipalCannotStartAnUploadOnTheOwnersExpense(): void
    {
        $this->putExpense();

        $this->expectException(ExpenseNotFound::class);

        $this->receipts->startUpload(self::owner(self::OTHER_USER), self::EXPENSE, self::pending(self::FIRST, self::OTHER_USER));
    }
}
