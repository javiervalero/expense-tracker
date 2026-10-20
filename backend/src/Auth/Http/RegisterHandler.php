<?php

declare(strict_types=1);

namespace App\Auth\Http;

use App\Auth\Email;
use App\Auth\InvalidEmail;
use App\Auth\Password\PasswordHasher;
use App\Auth\Password\PasswordPolicy;
use App\Auth\Password\PasswordViolation;
use App\Auth\Session\CarriedSession;
use App\Auth\Session\SessionCookie;
use App\Auth\Session\SessionLifetime;
use App\Auth\Session\SessionStore;
use App\Auth\Throttle\RegisterThrottle;
use App\Auth\User\EmailTaken;
use App\Auth\User\User;
use App\Auth\User\UserRepository;
use App\Http\ClientAddress;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Middleware\ClientIpMiddleware;
use App\Http\Problem\FieldError;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\Logging\JsonLogger;

/**
 * `POST /auth/register` (A9). In this order, so the cheap and the safe checks come before the expensive ones:
 *
 *  1. the register throttle (429), before the body is read and long before any hashing; every attempt counts;
 *  2. the body: media type, size and JSON (415, 413, 400), then its shape ({@see CredentialsInput}), then the address
 *     and the password rules, all reported together (422);
 *  3. the Argon2id hash;
 *  4. the user and its e-mail lock in one transaction (409 if another user holds the address);
 *  5. a new session (a failure here propagates);
 *  6. the session the request carried, if any, is deleted (A4: a token chosen by the client is never adopted), best
 *     effort: a storage failure is logged as a warning and the answer is still the 201;
 *  7. `201` with the cookie; 8. the log line `user registered` (the user id, never the address).
 *
 * Storage overload is not handled here: the exceptions travel to the StorageUnavailable middleware, which makes the
 * 503 (A18). A user who gets that 503 after a commit and retries creates a new id, so the retry can see 409 for their
 * own account; the 409 text says so.
 */
final readonly class RegisterHandler implements Handler
{
    private const TOO_MANY = 'Too many attempts. Try again later.';
    private const TAKEN = 'An account with this email address already exists. If your previous attempt ended with a temporary error, it may have gone through: try signing in.';

    public function __construct(
        private RegisterThrottle $throttle,
        private PasswordPolicy $policy,
        private PasswordHasher $hasher,
        private UserRepository $users,
        private SessionStore $sessions,
        private CarriedSession $carriedSession,
        private IdGenerator $ids,
        private Clock $clock,
        private JsonLogger $logger,
    ) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     * @throws TransactionCancelled
     */
    public function handle(Request $request): Response
    {
        $this->enforceThrottle($request);
        $input = CredentialsInput::fromBody(JsonBody::decode($request->header('content-type'), $request->body));
        $email = $this->validated($input);

        $user = User::register($this->ids->generate(), $email, $this->hasher->hash($input->password()), $this->clock);
        try {
            $this->users->create($user);
        } catch (EmailTaken) {
            throw HttpProblem::of(409, self::TAKEN);
        }

        $cookie = SessionCookie::issue($this->sessions->create($user->userId), SessionLifetime::ABSOLUTE);
        $this->endCarriedSessionIfPossible($request);

        $this->recordRegistration($request, $user->userId);

        return Response::json(201, ['id' => $user->userId, 'email' => $email->value()], ['Set-Cookie' => [$cookie]]);
    }

    private function enforceThrottle(Request $request): void
    {
        $address = $request->attribute(ClientIpMiddleware::ATTRIBUTE);
        // Without an address the request counts in the shared `unknown` bucket: never an exemption.
        $decision = $this->throttle->attempt($address instanceof ClientAddress ? $address : ClientAddress::unknown('missing'));
        if (!$decision->isAllowed) {
            throw HttpProblem::of(429, self::TOO_MANY, headers: ['Retry-After' => [(string) $decision->retryAfterSeconds]]);
        }
    }

    /**
     * @throws ValidationProblem
     */
    private function validated(CredentialsInput $input): Email
    {
        $email = Email::parse($input->email);
        $violation = $this->policy->violation($input->password());
        if ($email instanceof InvalidEmail || $violation instanceof PasswordViolation) {
            throw new ValidationProblem(self::fieldErrors($email, $violation));
        }

        return $email;
    }

    /**
     * @return list<FieldError>
     */
    private static function fieldErrors(Email|InvalidEmail $email, ?PasswordViolation $violation): array
    {
        $errors = [];
        if ($email instanceof InvalidEmail) {
            $errors[] = new FieldError('/email', self::emailMessage($email));
        }
        if ($violation instanceof PasswordViolation) {
            $errors[] = new FieldError('/password', self::passwordMessage($violation));
        }

        return $errors;
    }

    private static function emailMessage(InvalidEmail $problem): string
    {
        return match ($problem) {
            InvalidEmail::Empty => 'Enter an email address.',
            InvalidEmail::TooLong => 'The email address must have at most ' . Email::MAX_BYTES . ' bytes.',
            InvalidEmail::ControlCharacter => 'The email address must not contain control characters.',
            InvalidEmail::NonAscii => 'The email address must use ASCII characters only.',
            InvalidEmail::Malformed => 'Enter a valid email address.',
        };
    }

    private static function passwordMessage(PasswordViolation $problem): string
    {
        return match ($problem) {
            PasswordViolation::InvalidEncoding => 'The password must be valid UTF-8.',
            PasswordViolation::TooShort => 'The password must have at least ' . PasswordPolicy::MIN_CHARS . ' characters.',
            PasswordViolation::TooLong => 'The password must have at most ' . PasswordPolicy::MAX_CHARS . ' characters.',
            PasswordViolation::ControlCharacter => 'The password must not contain control characters.',
        };
    }

    /**
     * Best effort: the account and the new session exist, so failing to delete the old session must not turn the
     * answer into an error (a retry would then see 409). The old session simply expires on its own. Only the error
     * code is logged, never a token.
     */
    private function endCarriedSessionIfPossible(Request $request): void
    {
        try {
            $this->carriedSession->end($request);
        } catch (StorageFailure $failure) {
            $this->logger->warning('could not end the carried session after registering', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'operation' => $failure->operation,
                'code' => $failure->awsErrorCode,
            ]);
        }
    }

    private function recordRegistration(Request $request, string $userId): void
    {
        $context = $request->attribute(RequestLogContext::ATTRIBUTE);
        if ($context instanceof RequestLogContext) {
            $context->recordUserId($userId);
        }
        $this->logger->info('user registered', [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'userId' => $userId,
        ]);
    }
}
