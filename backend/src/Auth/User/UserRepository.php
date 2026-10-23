<?php

declare(strict_types=1);

namespace App\Auth\User;

use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TableLayout;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\DynamoDb\TransactionOperation;
use App\Infrastructure\Id\Uuid;
use App\Infrastructure\Time\StoredTime;
use InvalidArgumentException;

/**
 * Users and their e-mail locks. A user is two items written together: `USER#<id>/PROFILE` and `EMAIL#<email>/EMAIL`
 * (the lock, which makes the address unique). Whatever is read back is checked field by field; an item that is
 * missing a field or holds a field of the wrong type is a {@see StorageFailure}, never trusted.
 */
final readonly class UserRepository
{
    /**
     * The key of the dummy read of a login for an unknown e-mail (A16). A valid UUID that no user is given (users get
     * random ones), so the read finds nothing; {@see self::readDummyUser()} discards whatever it finds anyway.
     */
    public const DUMMY_USER_ID = '00000000-0000-4000-8000-000000000000';

    private const CONDITION_CHECK_FAILED = 'ConditionalCheckFailed';

    public function __construct(private ItemStore $items) {}

    /**
     * Creating the same user again succeeds (the SDK may retry a write whose first attempt went through, A19).
     *
     * @throws EmailTaken another user holds the address
     * @throws TransactionCancelled for any other cancellation (the caller maps overload to 503)
     * @throws StorageFailure
     */
    public function create(User $user): void
    {
        $lockKey = AuthKeys::emailLock($user->email);
        try {
            $this->items->transactWrite([
                TransactionOperation::put(self::userItem($user), 'attribute_not_exists(PK)'),
                TransactionOperation::put($lockKey + ['userId' => $user->userId], 'attribute_not_exists(PK)'),
            ]);
        } catch (TransactionCancelled $cancelled) {
            if (!in_array(self::CONDITION_CHECK_FAILED, $cancelled->reasons, true)) {
                throw $cancelled;
            }
            $this->settleDuplicate($user, $lockKey, $cancelled);
        }
    }

    /**
     * @throws StorageFailure
     */
    public function findByEmail(Email $email): ?User
    {
        $lock = $this->items->getItem(AuthKeys::emailLock($email));
        if ($lock === null) {
            return null;
        }
        $userId = $lock['userId'] ?? null;
        if (!is_string($userId) || !Uuid::isValid($userId)) {
            throw self::corrupt('GetItem');
        }
        $user = $this->findById($userId);
        if ($user === null || $user->email->value() !== $email->value()) {
            throw self::corrupt('GetItem');
        }

        return $user;
    }

    /**
     * Costs what the second read of a known login costs (same GetItem, same parameters, different key) so that known
     * and unknown e-mails do the same number of reads (A16). It returns nothing and never builds a user, so the dummy
     * key can never lead to a loginable account, even if an item were ever stored there.
     *
     * @throws StorageFailure
     */
    public function readDummyUser(): void
    {
        $this->items->getItem(AuthKeys::user(self::DUMMY_USER_ID));
    }

    /**
     * @throws InvalidArgumentException when the id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function findById(string $userId): ?User
    {
        $item = $this->items->getItem(AuthKeys::user($userId));

        return $item === null ? null : self::hydrate($userId, $item);
    }

    /**
     * Replaces the hash only if the stored one is still `$expectedOldHash`, the one the caller verified the password
     * against. A slow login that read the hash long ago therefore cannot overwrite a newer password (a password
     * change would be a second writer): it gets a {@see ConditionFailed}, and nothing is written.
     *
     * @throws InvalidArgumentException when the id is not a lower-case UUID, or a hash is not an Argon2id hash
     *                                  (the message never holds a hash)
     * @throws ConditionFailed when the user does not exist or no longer has the expected hash
     * @throws StorageFailure
     */
    public function updatePasswordHash(
        string $userId,
        #[\SensitiveParameter]
        string $expectedOldHash,
        #[\SensitiveParameter]
        string $newHash,
    ): void {
        if (!User::isAcceptableHash($newHash) || !User::isAcceptableHash($expectedOldHash)) {
            throw new InvalidArgumentException('A password hash must be an Argon2id hash.');
        }
        $this->items->updateItem(
            AuthKeys::user($userId),
            'SET #passwordHash = :passwordHash',
            ['#passwordHash' => 'passwordHash'],
            [':passwordHash' => $newHash, ':expected' => $expectedOldHash],
            'attribute_exists(PK) AND #passwordHash = :expected',
        );
    }

    /**
     * A condition failed: the lock tells whether it was our own earlier attempt or another user (EmailTaken).
     *
     * A19: the SDK may retry a write whose first attempt went through, so the retry finds its own items and fails
     * its conditions. That is a success, but only when what is stored is the user being created: the same id on the
     * lock is not enough, because a different user could reuse the id. So the stored user must have the same creation
     * time and password hash; otherwise the cancellation is rethrown, never reported as success.
     *
     * @param array{PK: string, SK: string} $lockKey
     */
    private function settleDuplicate(User $user, array $lockKey, TransactionCancelled $cancelled): void
    {
        $lock = $this->items->getItem($lockKey);
        if ($lock === null) {
            // The user id itself was taken without a lock: not a duplicate address, so not ours to explain.
            throw $cancelled;
        }
        $holder = $lock['userId'] ?? null;
        if (!is_string($holder) || !Uuid::isValid($holder)) {
            throw self::corrupt('GetItem');
        }
        if ($holder !== $user->userId) {
            throw new EmailTaken();
        }
        $stored = $this->items->getItem(AuthKeys::user($user->userId));
        $expected = self::userItem($user);
        if (
            $stored === null
            || !is_string($stored['createdAt'] ?? null)
            || !is_string($stored['passwordHash'] ?? null)
            || $stored['createdAt'] !== $expected['createdAt']
            || !hash_equals($expected['passwordHash'], $stored['passwordHash'])
        ) {
            throw $cancelled;
        }
    }

    /**
     * @return array<string, string>
     */
    private static function userItem(User $user): array
    {
        return AuthKeys::user($user->userId) + [
            'userId' => $user->userId,
            'email' => $user->email->value(),
            'passwordHash' => $user->passwordHash(),
            'createdAt' => StoredTime::format($user->createdAt),
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @throws StorageFailure
     */
    private static function hydrate(string $expectedId, array $item): User
    {
        $userId = $item['userId'] ?? null;
        $address = $item['email'] ?? null;
        $hash = $item['passwordHash'] ?? null;
        $created = $item['createdAt'] ?? null;
        if ($userId !== $expectedId || !is_string($address) || !is_string($hash) || !User::isAcceptableHash($hash) || !is_string($created)) {
            throw self::corrupt('GetItem');
        }
        $email = Email::parse($address);
        $createdAt = StoredTime::parse($created);
        if (!$email instanceof Email || $email->value() !== $address || $createdAt === null) {
            throw self::corrupt('GetItem');
        }

        return new User($userId, $email, $hash, $createdAt);
    }

    private static function corrupt(string $operation): StorageFailure
    {
        return new StorageFailure($operation, StorageFailure::CORRUPT_ITEM);
    }
}
