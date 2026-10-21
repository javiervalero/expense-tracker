<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Throttle;

use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Auth\Throttle\LoginAttempt;
use App\Auth\Throttle\LoginThrottle;
use App\Auth\Throttle\ThrottleRule;
use App\Http\ClientAddress;
use App\Infrastructure\DynamoDb\ConditionFailed;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\InMemoryThrottleStore;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * LoginThrottle over a fake counter. Addresses are from the documentation ranges; e-mails are invented.
 */
final class LoginThrottleTest extends TestCase
{
    private const IPV6 = '2001:db8:1:2::1';
    private const REQUEST_ID = 'req-1';
    private const IPV6_PREFIX_64 = '2001:db8:1:2::/64';
    private const IPV6_PREFIX_48 = '2001:db8:1::/48';

    private InMemoryThrottleStore $store;
    private LoginThrottle $throttle;

    /** @var resource */
    private $logStream;

    protected function setUp(): void
    {
        $this->store = new InMemoryThrottleStore();
        $this->throttle = new LoginThrottle($this->store, $this->logger());
    }

    private function logger(): JsonLogger
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->logStream = $stream;

        return new JsonLogger($stream);
    }

    private function logged(): string
    {
        rewind($this->logStream);

        return (string) stream_get_contents($this->logStream);
    }

    private function allowedAttempt(ClientAddress $address): LoginAttempt
    {
        $attempt = $this->throttle->attempt(self::email(), $address)->attempt;
        self::assertInstanceOf(LoginAttempt::class, $attempt);
        $this->store->calls = [];

        return $attempt;
    }

    private static function email(string $address = 'ana@example.com'): Email
    {
        $email = Email::parse($address);
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private static function emailKey(string $address = 'ana@example.com'): string
    {
        return hash('sha256', $address);
    }

    private static function ipv4(): ClientAddress
    {
        return ClientAddress::of('192.0.2.7', '192.0.2.7');
    }

    private static function ipv6(): ClientAddress
    {
        return ClientAddress::of(self::IPV6, self::IPV6_PREFIX_64);
    }

    public function testFirstAttemptFromAnIpv4AddressIsAllowedAndCountsTwoBuckets(): void
    {
        $decision = $this->throttle->attempt(self::email(), self::ipv4())->decision;

        self::assertTrue($decision->isAllowed);
        self::assertSame(
            ['hit|LOGIN-EMAIL|' . self::emailKey(), 'hit|LOGIN-IP|192.0.2.7'],
            $this->store->calls,
        );
    }

    public function testAnIpv6AddressAlsoCountsTheSlash48Bucket(): void
    {
        $this->throttle->attempt(self::email(), self::ipv6());

        self::assertSame(
            ['hit|LOGIN-EMAIL|' . self::emailKey(), 'hit|LOGIN-IP|' . self::IPV6_PREFIX_64, 'hit|LOGIN-NET|' . self::IPV6_PREFIX_48],
            $this->store->calls,
        );
    }

    public function testTheAccountBucketIsKeyedByTheHashOfTheNormalisedEmailNeverTheEmail(): void
    {
        $this->throttle->attempt(self::email('  Ana@Example.COM '), self::ipv4());

        self::assertSame(['hit|LOGIN-EMAIL|' . self::emailKey('ana@example.com'), 'hit|LOGIN-IP|192.0.2.7'], $this->store->calls);
        foreach ($this->store->calls as $call) {
            self::assertStringNotContainsString('example.com', $call);
        }
    }

    public function testTheTenthAttemptPassesAndTheEleventhIsBlockedByTheAccountBucket(): void
    {
        $this->store->seed(AuthKeys::LOGIN_EMAIL, self::emailKey(), 9);
        self::assertTrue($this->throttle->attempt(self::email(), self::ipv4())->decision->isAllowed);

        $decision = $this->throttle->attempt(self::email(), self::ipv4())->decision;

        self::assertFalse($decision->isAllowed);
    }

    public function testTheAddressBucketAloneBlocks(): void
    {
        $this->store->seed(AuthKeys::LOGIN_IP, '192.0.2.7', 30);

        self::assertFalse($this->throttle->attempt(self::email(), self::ipv4())->decision->isAllowed);
    }

    public function testTheNetworkBucketAloneBlocks(): void
    {
        $this->store->seed(AuthKeys::LOGIN_NET, self::IPV6_PREFIX_48, 100);

        self::assertFalse($this->throttle->attempt(self::email(), self::ipv6())->decision->isAllowed);
    }

    public function testAnIpv4AddressHasNoNetworkBucketToBlock(): void
    {
        $this->store->seed(AuthKeys::LOGIN_NET, self::IPV6_PREFIX_48, 1000);

        self::assertTrue($this->throttle->attempt(self::email(), self::ipv4())->decision->isAllowed);
    }

    public function testRetryAfterIsTheLargestRemainingTimeOfTheBlockedBuckets(): void
    {
        $this->store->seed(AuthKeys::LOGIN_EMAIL, self::emailKey(), 10);
        $this->store->seed(AuthKeys::LOGIN_IP, self::IPV6_PREFIX_64, 30);
        $this->store->seed(AuthKeys::LOGIN_NET, self::IPV6_PREFIX_48, 100);
        $this->store->retryAfterByScope = [AuthKeys::LOGIN_EMAIL => 120, AuthKeys::LOGIN_IP => 700, AuthKeys::LOGIN_NET => 300];

        $decision = $this->throttle->attempt(self::email(), self::ipv6())->decision;

        self::assertFalse($decision->isAllowed);
        self::assertSame(700, $decision->retryAfterSeconds);
    }

    public function testABucketThatIsNotBlockedDoesNotLowerTheRetryAfter(): void
    {
        $this->store->seed(AuthKeys::LOGIN_EMAIL, self::emailKey(), 10);
        $this->store->retryAfterByScope = [AuthKeys::LOGIN_EMAIL => 45, AuthKeys::LOGIN_IP => 900];

        $decision = $this->throttle->attempt(self::email(), self::ipv4())->decision;

        self::assertSame(45, $decision->retryAfterSeconds);
    }

    public function testEveryBucketIsCountedEvenWhenAnEarlierOneIsAlreadyBlocked(): void
    {
        $this->store->seed(AuthKeys::LOGIN_EMAIL, self::emailKey(), 10);

        $this->throttle->attempt(self::email(), self::ipv6());

        self::assertSame(1, $this->store->count(AuthKeys::LOGIN_IP, self::IPV6_PREFIX_64));
        self::assertSame(1, $this->store->count(AuthKeys::LOGIN_NET, self::IPV6_PREFIX_48));
    }

    public function testTheDecisionDoesNotDependOnWhetherTheAccountExists(): void
    {
        // The throttle is given only the e-mail and the address: it has no way to ask about an account, so two
        // different addresses (one "real", one invented) in the same state get the same decision and the same calls.
        $other = new InMemoryThrottleStore();
        $this->store->seed(AuthKeys::LOGIN_EMAIL, self::emailKey('real@example.com'), 10);
        $other->seed(AuthKeys::LOGIN_EMAIL, self::emailKey('nobody@example.com'), 10);

        $real = $this->throttle->attempt(self::email('real@example.com'), self::ipv4())->decision;
        $invented = (new LoginThrottle($other, $this->logger()))->attempt(self::email('nobody@example.com'), self::ipv4())->decision;

        self::assertEquals($real, $invented);
        self::assertCount(count($other->calls), $this->store->calls);
    }

    public function testAnAllowedAttemptCarriesAnAttemptAndABlockedOneDoesNot(): void
    {
        $allowed = $this->throttle->attempt(self::email(), self::ipv4());
        $this->store->seed(AuthKeys::LOGIN_IP, '192.0.2.8', 30);
        $blocked = $this->throttle->attempt(self::email(), ClientAddress::of('192.0.2.8', '192.0.2.8'));

        self::assertTrue($allowed->decision->isAllowed);
        self::assertInstanceOf(LoginAttempt::class, $allowed->attempt);
        self::assertFalse($blocked->decision->isAllowed);
        self::assertNull($blocked->attempt);
    }

    public function testALoginAttemptCannotBeBuiltOutsideTheThrottle(): void
    {
        $constructor = (new \ReflectionClass(LoginAttempt::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testSuccessDeletesTheAccountBucketAndRefundsTheAddressBucketsInTheCountedWindow(): void
    {
        $attempt = $this->allowedAttempt(self::ipv6());

        $this->throttle->succeeded($attempt, self::REQUEST_ID);

        self::assertSame(
            ['delete|LOGIN-EMAIL|' . self::emailKey(), 'refund|LOGIN-IP|' . self::IPV6_PREFIX_64, 'refund|LOGIN-NET|' . self::IPV6_PREFIX_48],
            $this->store->calls,
        );
        self::assertSame(
            [
                'LOGIN-IP|' . self::IPV6_PREFIX_64 => InMemoryThrottleStore::WINDOW_END + ThrottleRule::LOGIN_WINDOW_SECONDS,
                'LOGIN-NET|' . self::IPV6_PREFIX_48 => InMemoryThrottleStore::WINDOW_END + ThrottleRule::LOGIN_WINDOW_SECONDS,
            ],
            $this->store->refundedWindows,
        );
    }

    public function testSuccessFromIpv4HasNoNetworkRefund(): void
    {
        $this->throttle->succeeded($this->allowedAttempt(self::ipv4()), self::REQUEST_ID);

        self::assertSame(['delete|LOGIN-EMAIL|' . self::emailKey(), 'refund|LOGIN-IP|192.0.2.7'], $this->store->calls);
    }

    public function testAttemptThenSuccessLeavesNoCountAgainstTheCaller(): void
    {
        $attempt = $this->throttle->attempt(self::email(), self::ipv6())->attempt;
        self::assertNotNull($attempt);
        $this->throttle->succeeded($attempt, self::REQUEST_ID);

        self::assertSame(0, $this->store->count(AuthKeys::LOGIN_EMAIL, self::emailKey()));
        self::assertSame(0, $this->store->count(AuthKeys::LOGIN_IP, self::IPV6_PREFIX_64));
        self::assertSame(0, $this->store->count(AuthKeys::LOGIN_NET, self::IPV6_PREFIX_48));
    }

    public function testAnAttemptCanBeUsedForSuccessOnlyOnce(): void
    {
        $attempt = $this->allowedAttempt(self::ipv6());
        $this->throttle->succeeded($attempt, self::REQUEST_ID);
        $this->store->calls = [];

        try {
            $this->throttle->succeeded($attempt, self::REQUEST_ID);
            self::fail('Expected a LogicException.');
        } catch (LogicException) {
            self::assertSame([], $this->store->calls, 'the second use touched the store');
        }
    }

    public function testAFailingDeleteStillRunsTheRefundsAndNothingIsThrown(): void
    {
        $attempt = $this->allowedAttempt(self::ipv6());
        $this->store->failures['delete|LOGIN-EMAIL'] = new StorageFailure('DeleteItem', 'ThrottlingException');

        $this->throttle->succeeded($attempt, self::REQUEST_ID);

        self::assertSame(
            ['delete|LOGIN-EMAIL|' . self::emailKey(), 'refund|LOGIN-IP|' . self::IPV6_PREFIX_64, 'refund|LOGIN-NET|' . self::IPV6_PREFIX_48],
            $this->store->calls,
        );
    }

    public function testAFailingRefundDoesNotStopTheNextOneAndAConditionFailureIsCaughtToo(): void
    {
        $attempt = $this->allowedAttempt(self::ipv6());
        $this->store->failures['refund|LOGIN-IP'] = new StorageFailure('UpdateItem', 'ProvisionedThroughputExceededException');
        $this->store->failures['refund|LOGIN-NET'] = new ConditionFailed('UpdateItem');

        $this->throttle->succeeded($attempt, self::REQUEST_ID);

        self::assertCount(3, $this->store->calls);
        self::assertStringContainsString('"operation":"UpdateItem","code":"ConditionalCheckFailedException"', $this->logged());
        self::assertStringContainsString('ProvisionedThroughputExceededException', $this->logged());
    }

    public function testAFailedStepIsLoggedAsAWarningWithTheScopeAndTheCodeOnly(): void
    {
        $attempt = $this->allowedAttempt(self::ipv6());
        $this->store->failures['delete|LOGIN-EMAIL'] = new StorageFailure('DeleteItem', 'ThrottlingException');
        $this->store->failures['refund|LOGIN-IP'] = new StorageFailure('UpdateItem', 'InternalServerError');

        $this->throttle->succeeded($attempt, self::REQUEST_ID);

        $lines = array_values(array_filter(explode("\n", $this->logged()), static fn(string $line): bool => $line !== ""));
        self::assertCount(2, $lines);
        $first = json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame('warning', $first['level']);
        self::assertSame('LOGIN-EMAIL', $first['scope']);
        self::assertSame('ThrottlingException', $first['code']);
        self::assertSame('DeleteItem', $first['operation']);
        self::assertSame(self::REQUEST_ID, $first['requestId']);
        self::assertSame('login throttle clean-up failed after a successful login', $first['message']);
        self::assertArrayNotHasKey('errorCode', $first);
        $log = $this->logged();
        foreach ([self::emailKey(), 'ana@example.com', '192.0.2.7', '2001:db8', self::IPV6_PREFIX_64] as $secret) {
            self::assertStringNotContainsString($secret, $log);
        }
    }

    public function testTheUnknownAddressIsOneSharedBucket(): void
    {
        $first = ClientAddress::unknown('missing');
        $second = ClientAddress::unknown('invalid');

        $this->throttle->attempt(self::email('one@example.com'), $first);
        $this->throttle->attempt(self::email('two@example.com'), $second);

        self::assertSame(2, $this->store->count(AuthKeys::LOGIN_IP, 'unknown'));
        self::assertSame(0, $this->store->count(AuthKeys::LOGIN_NET, 'unknown'));
        self::assertNotContains('hit|LOGIN-NET|unknown', $this->store->calls);
    }

    public function testTheSharedUnknownBucketBlocksAtThirty(): void
    {
        $this->store->seed(AuthKeys::LOGIN_IP, 'unknown', 30);

        self::assertFalse($this->throttle->attempt(self::email(), ClientAddress::unknown('missing'))->decision->isAllowed);
    }
}
