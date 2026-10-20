<?php

declare(strict_types=1);

namespace App\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Http\ClientAddress;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use LogicException;

/**
 * The login throttle (A13, A14): one bucket per account, one per address (IPv4, IPv6 `/64`, or the shared `unknown`)
 * and, for IPv6, one per `/48`. The account bucket is keyed by the SHA-256 of the normalised e-mail, so mistyped
 * addresses are not stored. The throttle is given only the e-mail text: it never learns whether an account exists, so
 * the decision cannot depend on it.
 */
final readonly class LoginThrottle
{
    public function __construct(private ThrottleStore $store, private JsonLogger $logger) {}

    /**
     * Counts the attempt in every bucket (also those after a blocked one, so the counts do not depend on the order)
     * and returns the strictest decision. When it is allowed the result also carries the {@see LoginAttempt} for
     * {@see self::succeeded()}; a blocked attempt has none, so it can never be refunded.
     *
     * @throws StorageFailure
     */
    public function attempt(Email $email, ClientAddress $address): LoginAttemptResult
    {
        $buckets = [[ThrottleRule::loginAccount(), self::accountKey($email)]];
        foreach (self::addressBuckets($address) as $bucket) {
            $buckets[] = $bucket;
        }

        $decisions = [];
        $counted = [];
        foreach ($buckets as [$rule, $key]) {
            $hit = $this->store->hit($rule, $key);
            $decisions[] = $hit->decision;
            $counted[] = [$rule, $key, $hit->windowExpiresAt];
        }
        $decision = ThrottleDecision::strictest(...$decisions);

        return new LoginAttemptResult($decision, $decision->isAllowed ? LoginAttempt::issuedBy($this, $counted) : null);
    }

    /**
     * Failures are what counts: the account bucket is deleted and the address buckets give the attempt back, each only
     * in the window the attempt was counted in. This is best effort: the login has already succeeded, so a storage
     * error in one step is logged (scope and error code only, never a key) and the other steps still run. Nothing is
     * thrown for storage errors.
     *
     * @throws LogicException when the attempt was already used
     */
    public function succeeded(LoginAttempt $attempt): void
    {
        foreach ($attempt->consume($this) as [$rule, $key, $windowExpiresAt]) {
            $this->bestEffort(
                $rule,
                $rule->scope === AuthKeys::LOGIN_EMAIL
                    ? fn() => $this->store->delete($rule, $key)
                    : fn() => $this->store->refund($rule, $key, $windowExpiresAt),
            );
        }
    }

    private function bestEffort(ThrottleRule $rule, \Closure $step): void
    {
        try {
            $step();
        } catch (StorageFailure $failure) {
            $this->warn($rule, $failure->awsErrorCode ?? 'unknown');
        } catch (ConditionFailed) {
            $this->warn($rule, 'ConditionalCheckFailedException');
        }
    }

    private function warn(ThrottleRule $rule, string $errorCode): void
    {
        $this->logger->warning('Login throttle clean-up after a successful login failed', ['scope' => $rule->scope, 'errorCode' => $errorCode]);
    }

    private static function accountKey(Email $email): string
    {
        return hash('sha256', $email->value());
    }

    /**
     * @return list<array{ThrottleRule, string}>
     */
    private static function addressBuckets(ClientAddress $address): array
    {
        $buckets = [[ThrottleRule::loginAddress(), $address->throttleKey]];
        $network = NetworkPrefix::of($address);
        if ($network !== null) {
            $buckets[] = [ThrottleRule::loginNetwork(), $network];
        }

        return $buckets;
    }
}
