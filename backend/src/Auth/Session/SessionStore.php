<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;

/** Where sessions live. {@see SessionRepository} is the DynamoDB one; tests use an in-memory one. */
interface SessionStore
{
    /**
     * Starts a session for the user and returns its token, which only the caller may put in a cookie.
     *
     * @throws \InvalidArgumentException when the user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function create(string $userId): SessionToken;

    /**
     * @throws StorageFailure
     */
    public function find(#[\SensitiveParameter] string $tokenHash, int $now): ?Session;

    /**
     * Moves the idle expiry forward; returns the session as stored. Two overlapping renewals may leave the earlier
     * one's expiry (a few seconds lower): harmless.
     *
     * @throws ConditionFailed when the session no longer exists or its idle expiry has passed
     * @throws StorageFailure
     */
    public function renew(Session $session, int $now): Session;

    /**
     * Ends the session. Deleting one that does not exist is not an error.
     *
     * @throws StorageFailure
     */
    public function delete(#[\SensitiveParameter] string $tokenHash): void;
}
