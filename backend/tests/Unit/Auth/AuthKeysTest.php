<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth;

use App\Auth\AuthKeys;
use App\Auth\Email;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AuthKeysTest extends TestCase
{
    private const ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';

    public function testUserKeyIsTheProfileItemOfTheUser(): void
    {
        self::assertSame(['PK' => 'USER#' . self::ID, 'SK' => 'PROFILE'], AuthKeys::user(self::ID));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => [strtoupper(self::ID)];
        yield 'not a uuid' => ['USER#other'];
        yield 'trailing newline' => [self::ID . "\n"];
        yield 'wrong version' => ['3f2b8c1e-5d4a-1b7e-9a60-1c2d3e4f5a6b'];
    }

    #[DataProvider('badIds')]
    public function testUserKeyRefusesAnIdThatIsNotACanonicalLowerCaseUuid(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        AuthKeys::user($id);
    }

    public function testEmailLockKeyUsesTheNormalisedAddress(): void
    {
        $email = Email::parse('  Ana@Example.COM ');
        self::assertInstanceOf(Email::class, $email);

        self::assertSame(['PK' => 'EMAIL#ana@example.com', 'SK' => 'EMAIL'], AuthKeys::emailLock($email));
    }

    public function testALocalPartWithAHashIsFineBecauseTheKeyIsNeverParsedBack(): void
    {
        $email = Email::parse('a#b@example.com');
        self::assertInstanceOf(Email::class, $email);

        self::assertSame('EMAIL#a#b@example.com', AuthKeys::emailLock($email)['PK']);
    }
}
