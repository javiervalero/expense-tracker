<?php

declare(strict_types=1);

namespace App\Auth\Session;

/**
 * Who a request is, once its session is valid: the user id (from the stored session, never from the request) and the
 * session's token hash (to end the session at logout or rotation).
 */
final readonly class Principal
{
    /** Request attribute holding the Principal of an authenticated request. */
    public const ATTRIBUTE = 'auth.principal';

    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $tokenHash,
    ) {}

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'tokenHash' => '[redacted]'];
    }
}
