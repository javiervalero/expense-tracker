<?php

declare(strict_types=1);

namespace App\Tests\Integration\Auth;

use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Auth\User\EmailTaken;
use App\Auth\User\User;
use App\Auth\User\UserRepository;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * UserRepository against DynamoDB Local, on a table of its own. All e-mails and hashes are invented.
 */
final class UserRepositoryTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const OTHER_ID = '9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d';
    private const OTHER_HASH = '$argon2id$v=19$m=8,t=1,p=1$invented-salt$another-invented-value';
    private const HASH = '$argon2id$v=19$m=8,t=1,p=1$invented-salt$invented-hash-value';

    private TemporaryTable $table;
    private ItemStore $store;
    private UserRepository $users;

    protected function setUp(): void
    {
        $client = LocalAws::fromGlobals()->dynamoDb();
        $this->table = TemporaryTable::create($client);
        $this->store = new ItemStore($client, $this->table->name);
        $this->users = new UserRepository($this->store);
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    private static function email(string $address = 'ana@example.com'): Email
    {
        $email = Email::parse($address);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private static function user(string $id = self::ID, string $address = 'ana@example.com'): User
    {
        return User::register($id, self::email($address), self::HASH, new FrozenClock('2026-10-16T12:00:00+00:00'));
    }

    public function testCreateThenFindByEmailAndById(): void
    {
        $this->users->create(self::user());

        $byEmail = $this->users->findByEmail(self::email());
        $byId = $this->users->findById(self::ID);

        foreach ([$byEmail, $byId] as $found) {
            self::assertNotNull($found);
            self::assertSame(self::ID, $found->userId);
            self::assertSame('ana@example.com', $found->email->value());
            self::assertSame(self::HASH, $found->passwordHash());
            self::assertSame('2026-10-16T12:00:00+00:00', $found->createdAt->format('c'));
        }
    }

    public function testTheStoredItemsHaveExactlyTheDocumentedAttributes(): void
    {
        $this->users->create(self::user());

        $user = $this->store->getItem(AuthKeys::user(self::ID));
        $lock = $this->store->getItem(AuthKeys::emailLock(self::email()));

        self::assertNotNull($user);
        ksort($user);
        self::assertSame([
            'PK' => 'USER#' . self::ID,
            'SK' => 'PROFILE',
            'createdAt' => '2026-10-16T12:00:00Z',
            'email' => 'ana@example.com',
            'passwordHash' => self::HASH,
            'userId' => self::ID,
        ], $user);
        self::assertNotNull($lock);
        ksort($lock);
        self::assertSame(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL', 'userId' => self::ID], $lock);
    }

    public function testTheSameEmailInOtherLetterCaseIsEmailTakenAndWritesNoSecondUser(): void
    {
        $this->users->create(self::user());

        try {
            $this->users->create(self::user(self::OTHER_ID, 'ANA@Example.COM'));
            self::fail('expected EmailTaken');
        } catch (EmailTaken) {
        }

        self::assertNull($this->store->getItem(AuthKeys::user(self::OTHER_ID)));
        self::assertNotNull($this->users->findById(self::ID));
        self::assertSame(self::ID, $this->users->findByEmail(self::email())?->userId);
    }

    public function testANewUserWithAnExistingIdIsRejectedAndLeavesNoLockForItsAddress(): void
    {
        $this->users->create(self::user());

        try {
            $this->users->create(self::user(self::ID, 'bob@example.com'));
            self::fail('expected TransactionCancelled');
        } catch (TransactionCancelled $cancelled) {
            self::assertContains('ConditionalCheckFailed', $cancelled->reasons);
        }

        self::assertNull($this->users->findByEmail(self::email('bob@example.com')));
        self::assertNull($this->store->getItem(AuthKeys::emailLock(self::email('bob@example.com'))));
        self::assertSame('ana@example.com', $this->users->findById(self::ID)?->email->value());
    }

    public function testCreatingTheSameUserTwiceSucceeds(): void
    {
        $user = self::user();

        $this->users->create($user);
        $this->users->create($user);

        self::assertSame(self::ID, $this->users->findByEmail(self::email())?->userId);
    }

    public function testAnEmailWithAHashInTheLocalPartWorks(): void
    {
        $this->users->create(self::user(self::ID, 'a#b@example.com'));

        self::assertSame(self::ID, $this->users->findByEmail(self::email('a#b@example.com'))?->userId);
    }

    public function testUnknownEmailAndUnknownIdGiveNull(): void
    {
        self::assertNull($this->users->findByEmail(self::email('nobody@example.com')));
        self::assertNull($this->users->findById(self::OTHER_ID));
    }

    public function testUpdatePasswordHashReplacesOnlyTheHash(): void
    {
        $this->users->create(self::user());

        $this->users->updatePasswordHash(self::ID, self::HASH, self::OTHER_HASH);

        $found = $this->users->findById(self::ID);
        self::assertNotNull($found);
        self::assertSame(self::OTHER_HASH, $found->passwordHash());
        self::assertSame('ana@example.com', $found->email->value());
    }

    public function testUpdatePasswordHashOnAMissingUserFailsTheConditionAndCreatesNothing(): void
    {
        try {
            $this->users->updatePasswordHash(self::ID, self::HASH, self::OTHER_HASH);
            self::fail('expected ConditionFailed');
        } catch (ConditionFailed) {
        }

        self::assertNull($this->store->getItem(AuthKeys::user(self::ID)));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function putUserItem(array $overrides): void
    {
        $this->store->putItem($overrides + [
            'PK' => 'USER#' . self::ID,
            'SK' => 'PROFILE',
            'userId' => self::ID,
            'email' => 'ana@example.com',
            'passwordHash' => self::HASH,
            'createdAt' => '2026-10-16T12:00:00Z',
        ]);
    }

    public function testACorruptItemIsAStorageFailureNeverTrusted(): void
    {
        $corruptions = [
            'hash is a number' => ['passwordHash' => 5],
            'hash is empty' => ['passwordHash' => ''],
            'hash is not argon2id' => ['passwordHash' => 'plain-invented-secret'],
            'email is a number' => ['email' => 7],
            'email is not an address' => ['email' => 'not-an-address'],
            'email is not normalised' => ['email' => 'ANA@example.com'],
            'createdAt is not a date' => ['createdAt' => 'yesterday'],
            'createdAt is not a real date' => ['createdAt' => '2026-02-30T00:00:00Z'],
            'createdAt is a number' => ['createdAt' => 1760616000],
            'userId is another user' => ['userId' => self::OTHER_ID],
            'userId is a number' => ['userId' => 1],
        ];
        foreach ($corruptions as $label => $override) {
            $this->putUserItem($override);
            try {
                $this->users->findById(self::ID);
                self::fail("expected StorageFailure: $label");
            } catch (StorageFailure $failure) {
                self::assertSame('CorruptItem', $failure->awsErrorCode, $label);
            }
        }
    }

    public function testAMissingAttributeIsAStorageFailure(): void
    {
        $this->store->putItem(['PK' => 'USER#' . self::ID, 'SK' => 'PROFILE', 'userId' => self::ID]);

        $this->expectException(StorageFailure::class);

        $this->users->findById(self::ID);
    }

    public function testALockPointingAtNoUserIsAStorageFailure(): void
    {
        $this->store->putItem(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL', 'userId' => self::ID]);

        $this->expectException(StorageFailure::class);

        $this->users->findByEmail(self::email());
    }

    public function testALockPointingAtAUserWithAnotherAddressIsAStorageFailure(): void
    {
        $this->store->putItem(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL', 'userId' => self::ID]);
        $this->putUserItem(['email' => 'someone@example.com']);

        $this->expectException(StorageFailure::class);

        $this->users->findByEmail(self::email());
    }

    public function testALockWhoseUserIdIsANumberIsACorruptItem(): void
    {
        $this->store->putItem(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL', 'userId' => 5]);

        try {
            $this->users->findByEmail(self::email());
            self::fail('expected StorageFailure');
        } catch (StorageFailure $failure) {
            self::assertSame('CorruptItem', $failure->awsErrorCode);
        }
    }

    public function testALockWithAnInvalidUserIdIsAStorageFailure(): void
    {
        $this->store->putItem(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL', 'userId' => 'USER#x']);

        $this->expectException(StorageFailure::class);

        $this->users->findByEmail(self::email());
    }
}
