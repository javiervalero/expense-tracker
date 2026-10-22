<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Http\Request;
use LogicException;

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
     * The principal of an authenticated request. A request without one never passed {@see RequireSession}: that is a
     * misconfigured route (a `500`), never an anonymous caller let through.
     *
     * @throws LogicException when the guard did not put a Principal on the request
     */
    public static function of(Request $request): self
    {
        $principal = $request->attribute(self::ATTRIBUTE);
        if (!$principal instanceof self) {
            throw new LogicException('The request has no principal: the route is not behind RequireSession.');
        }

        return $principal;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['userId' => $this->userId, 'tokenHash' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A principal must not be serialised.');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __unserialize(#[\SensitiveParameter] array $data): void
    {
        throw new LogicException('A principal must not be unserialised.');
    }

    public function __clone()
    {
        throw new LogicException('A principal must not be cloned (nor cloned with other values).');
    }
}
