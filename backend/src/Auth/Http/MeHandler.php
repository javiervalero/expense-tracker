<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Session\Principal;
use App\Auth\Session\RequireSession;
use App\Auth\Session\SessionStore;
use App\Auth\User\UserRepository;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use LogicException;

/**
 * `GET /auth/me` (A6), behind {@see RequireSession}: answers with the user of the session. The key is built from the
 * principal that the guard took from the stored session, never from the request. A session whose user no longer
 * exists is of no use: it is ended (the one write of this read-only route, which only removes a session that could
 * never authenticate again) and the request gets the same `401` as any other request without a session.
 */
final readonly class MeHandler implements Handler
{
    public function __construct(
        private UserRepository $users,
        private SessionStore $sessions,
        private JsonLogger $logger,
    ) {}

    /**
     * @throws StorageFailure
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = $request->attribute(Principal::ATTRIBUTE);
        if (!$principal instanceof Principal) {
            throw new LogicException('GET /auth/me must be wrapped in RequireSession.');
        }
        $user = $this->users->findById($principal->userId);
        if ($user === null) {
            $this->sessions->delete($principal->tokenHash);
            $this->logger->warning('session of a missing user ended', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'userId' => $principal->userId,
            ]);

            return RequireSession::challenge(true);
        }

        return Response::json(200, ['id' => $user->userId, 'email' => $user->email->value()]);
    }
}
