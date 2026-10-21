<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\User;

use App\Auth\Email;
use App\Auth\Password\PasswordHasher;
use App\Auth\User\User;
use App\Tests\Support\FrozenClock;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserTest extends TestCase
{
    private const HASH = '$argon2id$v=19$m=8,t=1,p=1$invented-salt$invented-hash-value';
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';

    private static function user(): User
    {
        $email = Email::parse('ana@example.com');
        self::assertInstanceOf(Email::class, $email);

        return User::register(self::ID, $email, self::HASH, new FrozenClock('2026-10-16T12:00:00+00:00'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function debugInfo(): array
    {
        return self::user()->__debugInfo();
    }

    public function testRegisterTakesTheCreationTimeFromTheClock(): void
    {
        $user = self::user();

        self::assertSame(self::ID, $user->userId);
        self::assertSame('ana@example.com', $user->email->value());
        self::assertSame('2026-10-16T12:00:00+00:00', $user->createdAt->format('c'));
        self::assertSame(self::HASH, $user->passwordHash());
    }

    public function testTheHashIsNotInDebugOutput(): void
    {
        $dump = print_r(self::user(), true) . var_export(self::debugInfo(), true);

        self::assertStringNotContainsString('invented-hash-value', $dump);
        self::assertStringContainsString('ana@example.com', $dump);
    }

    public function testTheHashIsInNoDumpOrCastPath(): void
    {
        $user = self::user();
        ob_start();
        var_dump($user);
        $varDump = (string) ob_get_clean();

        $outputs = [
            'var_export' => var_export($user, true),
            'array cast' => var_export((array) $user, true) . serialize(array_keys((array) $user)),
            'get_object_vars' => var_export(get_object_vars($user), true),
            'print_r' => print_r($user, true),
            'var_dump' => $varDump,
            'debug info' => var_export($user->__debugInfo(), true),
        ];
        foreach ($outputs as $label => $output) {
            self::assertStringNotContainsString('invented-hash-value', $output, $label);
            self::assertStringNotContainsString('$argon2id$', $output, $label);
        }
        self::assertSame(['userId', 'email', 'createdAt'], array_keys(get_object_vars($user)));
    }

    public function testTheOtherFieldsStayReadable(): void
    {
        $user = self::user();

        self::assertSame(['userId' => self::ID, 'email' => $user->email, 'createdAt' => $user->createdAt], get_object_vars($user));
    }

    public function testTwoUsersKeepTheirOwnHashes(): void
    {
        $other = User::register('9a8b7c6d-5e4f-4a3b-8c2d-1e0f9a8b7c6d', self::user()->email, '$argon2id$other-invented', new FrozenClock());

        self::assertSame(self::HASH, self::user()->passwordHash());
        self::assertSame('$argon2id$other-invented', $other->passwordHash());
    }

    public function testTheUserCannotBeCloned(): void
    {
        $this->expectException(LogicException::class);

        $copy = clone self::user();
        unset($copy);
    }

    public function testTheUserCannotBeUnserialised(): void
    {
        $this->expectException(LogicException::class);

        (new \ReflectionClass(User::class))->newInstanceWithoutConstructor()->__unserialize(['field' => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedHashes(): iterable
    {
        yield 'empty' => [''];
        yield 'plain text' => ['plain-invented-secret'];
        yield 'another algorithm' => ['$2y$10$invented-bcrypt-looking-value'];
        yield 'prefix in the middle' => ['x$argon2id$v=19'];
        yield 'upper case prefix' => ['$ARGON2ID$v=19'];
    }

    #[DataProvider('refusedHashes')]
    public function testAHashThatIsNotArgon2idIsRefusedWithoutEchoingIt(string $hash): void
    {
        $email = self::user()->email;
        try {
            User::register(self::ID, $email, $hash, new FrozenClock());
            self::fail('expected InvalidArgumentException');
        } catch (InvalidArgumentException $refused) {
            if ($hash !== '') {
                self::assertStringNotContainsString($hash, $refused->getMessage());
            }
        }
    }

    public function testTheConstructorRefusesANonArgon2idHashToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new User(self::ID, self::user()->email, 'plain-invented-secret', new DateTimeImmutable());
    }

    public function testTheDummyHashOfTheTimingEqualisationIsNeverAcceptedAsAUsersHash(): void
    {
        self::assertFalse(User::isAcceptableHash(PasswordHasher::DUMMY_HASH));
        $this->expectException(InvalidArgumentException::class);

        User::register(self::ID, self::user()->email, PasswordHasher::DUMMY_HASH, new FrozenClock());
    }

    public function testTheUserCannotBeEncodedAsJson(): void
    {
        $this->expectException(LogicException::class);

        json_encode(self::user());
    }

    public function testTheUserCannotBeSerialised(): void
    {
        $this->expectException(LogicException::class);

        serialize(self::user());
    }
}
