<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Email;
use App\Auth\Http\UserResponse;
use PHPUnit\Framework\TestCase;

final class UserResponseTest extends TestCase
{
    private const USER_ID = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const COOKIE = '__Host-session=invented; Max-Age=604800; Path=/; Secure; HttpOnly; SameSite=Lax';

    public function testItIsTheUserBodyWithTheGivenStatusAndTheSessionCookie(): void
    {
        $email = Email::parse('Ana@Example.com');
        self::assertInstanceOf(Email::class, $email);

        foreach ([200, 201] as $status) {
            $response = UserResponse::signedIn($status, self::USER_ID, $email, self::COOKIE);

            self::assertSame($status, $response->status);
            self::assertSame('{"id":"' . self::USER_ID . '","email":"ana@example.com"}', $response->body);
            self::assertSame([self::COOKIE], $response->headerValues('Set-Cookie'));
            self::assertSame(['application/json'], $response->headerValues('Content-Type'));
        }
    }
}
