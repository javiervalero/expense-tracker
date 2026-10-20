<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Session\Session;
use App\Auth\Session\SessionStore;
use App\Auth\Session\SessionToken;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;

/**
 * A session store in memory that counts its calls. Like the real one, `find` does not return a session that has
 * expired. Set `$findIgnoresExpiry` to make it misbehave (return whatever is stored) so that a test can reach the
 * authenticator's own expiry check, which is defence in depth behind the store's.
 */
final class InMemorySessionStore implements SessionStore
{
    /** @var array<string, Session> */
    public array $sessions = [];
    public int $calls = 0;
    public int $renewCalls = 0;
    public bool $renewFindsNothing = false;
    public bool $renewFails = false;
    public ?StorageFailure $renewFailure = null;
    public bool $findIgnoresExpiry = false;
    public ?StorageFailure $createFailure = null;
    public ?StorageFailure $deleteFailure = null;

    /** @var list<string> 'create' and 'delete' calls, in order */
    public array $operations = [];

    public function __construct(private readonly FrozenClock $clock) {}

    public function put(string $tokenHash, Session $session): void
    {
        $this->sessions[$tokenHash] = $session;
    }

    /** Stores a new session for the user without counting a call: for arranging a test. */
    public function seed(string $userId): SessionToken
    {
        $token = SessionToken::generate();
        $this->sessions[$token->hash()] = Session::start($userId, $token->hash(), $this->clock->now()->getTimestamp());

        return $token;
    }

    public function create(string $userId): SessionToken
    {
        ++$this->calls;
        $this->operations[] = 'create';
        if ($this->createFailure !== null) {
            throw $this->createFailure;
        }

        return $this->seed($userId);
    }

    public function find(string $tokenHash, int $now): ?Session
    {
        ++$this->calls;

        $session = $this->sessions[$tokenHash] ?? null;
        if ($session === null || $this->findIgnoresExpiry || $session->isActiveAt($now)) {
            return $session;
        }

        return null;
    }

    public function renew(Session $session, int $now): Session
    {
        ++$this->calls;
        ++$this->renewCalls;
        if ($this->renewFindsNothing) {
            throw new ConditionFailed('UpdateItem');
        }
        if ($this->renewFailure !== null) {
            throw $this->renewFailure;
        }
        if ($this->renewFails) {
            throw new StorageFailure('UpdateItem', 'ThrottlingException');
        }

        return $this->sessions[$session->tokenHash] = $session->renewed($now);
    }

    public function delete(string $tokenHash): void
    {
        ++$this->calls;
        $this->operations[] = 'delete';
        if ($this->deleteFailure !== null) {
            throw $this->deleteFailure;
        }
        unset($this->sessions[$tokenHash]);
    }
}
