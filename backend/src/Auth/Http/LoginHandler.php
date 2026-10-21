<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Email;
use App\Auth\InvalidEmail;
use App\Auth\Password\PasswordVerifier;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Password\PasswordViolation;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\RequireSession;
use App\Auth\Session\SessionCookie;
use App\Auth\Session\SessionLifetime;
use App\Auth\Session\SessionStore;
use App\Auth\Throttle\LoginAttempt;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\User\User;
use App\Auth\User\UserRepository;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Problem\FieldError;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;

/**
 * `POST /auth/login` (A4, A13, A16). In this order:
 *
 *  1. the body: media type, size and JSON (415, 413, 400), its shape ({@see CredentialsInput}), then the address
 *     format and the password maximum (422; login enforces nothing else about a password, so a later policy change
 *     never locks anyone out). None of this depends on whether an account exists, and none of it hashes;
 *  2. the login throttle, counted before anything is read (429 + `Retry-After`). A blocked attempt stops here: it
 *     reads nothing, verifies nothing and never reaches `succeeded()`;
 *  3. the account lookup. Without an account the work of a verification is still done ({@see PasswordVerifier::verifyDummy()})
 *     and the dummy user is read, so known and unknown e-mails cost the same hash time and the same number of reads;
 *  4. the password. Wrong and unknown give one 401 (same status, headers and body);
 *  5. only after the password is verified: `succeeded()` (resets the account bucket, refunds the address buckets;
 *     best effort inside the throttle), the hash upgrade if its parameters are outdated (best effort: a storage error, a lost race with a newer password or a PHP hashing error is logged and ignored), a NEW session,
 *     then the session the request carried is ended (best effort, after the new one exists), `200` + cookie.
 *
 * Logs: `login succeeded` / `login failed` with the user id when known and a reason category; never the address, the
 * password, a hash or a token. Storage overload travels to the StorageUnavailable middleware (503, A18).
 */
final readonly class LoginHandler implements Handler
{
    private const INVALID_CREDENTIALS = 'Invalid email or password.';

    public function __construct(
        private LoginThrottle $throttle,
        private PasswordPolicy $policy,
        private PasswordVerifier $hasher,
        private UserRepository $users,
        private SessionStore $sessions,
        private CarriedSession $carriedSession,
        private JsonLogger $logger,
    ) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     */
    public function handle(Request $request): Response
    {
        $input = CredentialsInput::fromBody(JsonBody::decode($request->header('content-type'), $request->body));
        $email = $this->validated($input);

        $attempt = $this->enforceThrottle($request, $email);

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->hasher->verifyDummy($input->password());
            $this->users->readDummyUser();
            throw $this->failed($request, 'unknown_email');
        }
        if (!$this->hasher->verify($input->password(), $user->passwordHash())) {
            throw $this->failed($request, 'wrong_password', $user->userId);
        }

        // From here on the password is verified.
        $this->throttle->succeeded($attempt, $request->attribute(Request::REQUEST_ID));
        $this->upgradeHashIfOutdated($request, $user, $input->password());

        $cookie = SessionCookie::issue($this->sessions->create($user->userId), SessionLifetime::ABSOLUTE);
        $this->carriedSession->endBestEffort($request);

        $this->recordSuccess($request, $user->userId);

        return UserResponse::signedIn(200, $user->userId, $user->email, $cookie);
    }

    /**
     * @throws ValidationProblem
     */
    private function validated(CredentialsInput $input): Email
    {
        $email = Email::parse($input->email);
        $tooLong = $this->policy->exceedsLoginLimit($input->password());
        if ($email instanceof InvalidEmail || $tooLong) {
            $errors = [];
            if ($email instanceof InvalidEmail) {
                $errors[] = new FieldError('/email', FieldMessages::email($email));
            }
            if ($tooLong) {
                $errors[] = new FieldError('/password', FieldMessages::password(PasswordViolation::TooLong));
            }

            throw new ValidationProblem($errors);
        }

        return $email;
    }

    /**
     * @throws HttpProblem 429 when any bucket is over its limit
     * @throws StorageFailure
     */
    private function enforceThrottle(Request $request, Email $email): LoginAttempt
    {
        $result = $this->throttle->attempt($email, ClientIpMiddleware::addressOf($request));
        if (!$result->decision->isAllowed || $result->attempt === null) {
            $this->logFailure($request, 'throttled');

            throw TooManyAttempts::problem($result->decision->retryAfterSeconds);
        }

        return $result->attempt;
    }

    /** One answer for "no such account" and "wrong password": the caller throws it, so the two cannot differ. */
    private function failed(Request $request, string $reason, ?string $userId = null): HttpProblem
    {
        $this->logFailure($request, $reason, $userId);

        return HttpProblem::of(401, self::INVALID_CREDENTIALS, headers: ['WWW-Authenticate' => [RequireSession::CHALLENGE]]);
    }

    private function logFailure(Request $request, string $reason, ?string $userId = null): void
    {
        $context = ['requestId' => $request->attribute(Request::REQUEST_ID), 'reason' => $reason];
        if ($userId !== null) {
            $context['userId'] = $userId;
        }
        $this->logger->warning('login failed', $context);
    }

    /**
     * Best effort: the password was right and the login must not fail because the upgrade did. The old hash stays
     * valid and the next login tries again. Only the user id and the error code are logged, never a hash.
     */
    private function upgradeHashIfOutdated(Request $request, User $user, #[\SensitiveParameter] string $password): void
    {
        if (!$this->hasher->needsRehash($user->passwordHash())) {
            return;
        }
        try {
            $this->users->updatePasswordHash($user->userId, $user->passwordHash(), $this->hasher->hash($password));
        } catch (\ValueError $error) {
            // PHP could not hash (for example no memory for Argon2id). Only the class is logged: the message of a
            // failing hash call is not ours to put in a log. Nothing broader is caught.
            $this->logHashUpgradeFailure($request, $user->userId, null, $error::class);
        } catch (StorageFailure $failure) {
            $this->logHashUpgradeFailure($request, $user->userId, $failure->awsErrorCode ?? 'unknown');
        } catch (ConditionFailed) {
            $this->logHashUpgradeFailure($request, $user->userId, 'ConditionalCheckFailedException');
        }
    }

    private function logHashUpgradeFailure(Request $request, string $userId, ?string $code, ?string $exception = null): void
    {
        $this->logger->warning('could not upgrade the password hash', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'userId' => $userId,
        ] + ($code === null ? [] : ['code' => $code]) + ($exception === null ? [] : ['exception' => $exception]));
    }

    private function recordSuccess(Request $request, string $userId): void
    {
        $context = $request->attribute(RequestLogContext::ATTRIBUTE);
        if ($context instanceof RequestLogContext) {
            $context->recordUserId($userId);
        }
        $this->logger->info('login succeeded', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'userId' => $userId,
        ]);
    }
}
