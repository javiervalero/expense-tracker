<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Password;

use App\Auth\Password\PasswordHasher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

final class PasswordHasherTest extends TestCase
{
    private const PRODUCTION_PREFIX = '$argon2id$v=19$m=65536,t=4,p=1$';
    private const PASSWORD = 'correct horse battery staple';

    private static function cheap(): PasswordHasher
    {
        return new PasswordHasher(memoryCost: 8, timeCost: 1, threads: 1);
    }

    public function testHashUsesArgon2idWithTheGivenParameters(): void
    {
        $hash = self::cheap()->hash(self::PASSWORD);

        self::assertStringStartsWith('$argon2id$v=19$m=8,t=1,p=1$', $hash);
    }

    public function testVerifyAcceptsTheRightPassword(): void
    {
        $hasher = self::cheap();

        self::assertTrue($hasher->verify(self::PASSWORD, $hasher->hash(self::PASSWORD)));
    }

    public function testVerifyRefusesAWrongPassword(): void
    {
        $hasher = self::cheap();
        $hash = $hasher->hash(self::PASSWORD);

        self::assertFalse($hasher->verify(self::PASSWORD . 'x', $hash));
        self::assertFalse($hasher->verify(strtoupper(self::PASSWORD), $hash));
        self::assertFalse($hasher->verify('', $hash));
    }

    public function testVerifyRefusesAMalformedHashWithoutThrowing(): void
    {
        self::assertFalse(self::cheap()->verify(self::PASSWORD, 'not a hash'));
        self::assertFalse(self::cheap()->verify(self::PASSWORD, ''));
    }

    public function testTwoHashesOfTheSamePasswordDiffByTheSalt(): void
    {
        $hasher = self::cheap();

        self::assertNotSame($hasher->hash(self::PASSWORD), $hasher->hash(self::PASSWORD));
    }

    public function testUnicodeAndSpacesAndLongPasswordsRoundTrip(): void
    {
        $hasher = self::cheap();
        foreach (["p\u{e4}ss w\u{f6}rd \u{1F600}\u{1F600}", str_repeat('x', 128), " leading and trailing \t"] as $password) {
            self::assertTrue($hasher->verify($password, $hasher->hash($password)));
        }
    }

    public function testNeedsRehashIsTrueForAHashWithOlderParameters(): void
    {
        $old = new PasswordHasher(memoryCost: 8, timeCost: 1, threads: 1);
        $hash = $old->hash(self::PASSWORD);

        self::assertTrue(PasswordHasher::production()->needsRehash($hash));
    }

    public function testNeedsRehashIsFalseForAHashWithTheCurrentParameters(): void
    {
        $hasher = self::cheap();

        self::assertFalse($hasher->needsRehash($hasher->hash(self::PASSWORD)));
    }

    public function testNeedsRehashIsTrueForAnotherAlgorithm(): void
    {
        self::assertTrue(self::cheap()->needsRehash(password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 4])));
    }

    public function testTheDummyHashHasExactlyTheProductionParameters(): void
    {
        $info = password_get_info(PasswordHasher::DUMMY_HASH);

        self::assertSame('argon2id', $info['algoName']);
        self::assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            $info['options'],
        );
        self::assertStringStartsWith(self::PRODUCTION_PREFIX, PasswordHasher::DUMMY_HASH);
    }

    public function testTheProductionHasherConsidersTheDummyHashCurrent(): void
    {
        self::assertFalse(PasswordHasher::production()->needsRehash(PasswordHasher::DUMMY_HASH));
    }

    public function testNoPasswordMatchesTheDummyHash(): void
    {
        foreach (['', 'password', self::PASSWORD, PasswordHasher::DUMMY_HASH] as $guess) {
            self::assertFalse(password_verify($guess, PasswordHasher::DUMMY_HASH));
        }
    }

    public function testVerifyDummyDoesTheWorkAndGivesNothingBack(): void
    {
        $start = hrtime(true);
        PasswordHasher::production()->verifyDummy(self::PASSWORD);
        $elapsedMilliseconds = (hrtime(true) - $start) / 1_000_000;

        // Argon2id at 64 MiB and t=4 cannot finish in under a millisecond: the work was really done.
        self::assertGreaterThan(1.0, $elapsedMilliseconds);
    }

    public function testEveryParameterThatTakesAPasswordIsMarkedSensitive(): void
    {
        foreach (['hash' => [0], 'verify' => [0, 1], 'needsRehash' => [0], 'verifyDummy' => [0]] as $name => $positions) {
            $parameters = (new \ReflectionMethod(PasswordHasher::class, $name))->getParameters();
            foreach ($positions as $position) {
                self::assertCount(1, $parameters[$position]->getAttributes(\SensitiveParameter::class), "{$name} #{$position}");
            }
        }
    }

    public function testTheProductionHasherUsesExactlyTheProductionCost(): void
    {
        $production = PasswordHasher::production();
        $options = new \ReflectionMethod($production, 'options');

        self::assertSame(
            ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
            $options->invoke($production),
        );
    }

    public function testPasswordsAreComparedByteForByteWithoutUnicodeNormalisation(): void
    {
        // The intl extension (Normalizer) is in neither the tools image nor the Bref image: no NFKC.
        $hasher = self::cheap();
        $precomposed = "caf\u{e9} au lait!";
        $decomposed = "cafe\u{301} au lait!";

        self::assertTrue($hasher->verify($precomposed, $hasher->hash($precomposed)));
        self::assertFalse($hasher->verify($decomposed, $hasher->hash($precomposed)));
    }

    #[Group('slow')]
    public function testOneProductionHashTakesUnderTwoSecondsAndHasTheProductionPrefix(): void
    {
        $start = hrtime(true);
        $hash = PasswordHasher::production()->hash(self::PASSWORD);
        $seconds = (hrtime(true) - $start) / 1_000_000_000;

        $directory = dirname(__DIR__, 5) . '/build/tmp';
        self::assertTrue(is_dir($directory) || mkdir($directory, 0o775, true), "Cannot create {$directory}");
        $written = file_put_contents(
            $directory . '/argon2-timing.txt',
            sprintf("argon2id m=65536 t=4 p=1: one hash in %.3f s (%s, PHP %s)\n", $seconds, php_uname('s'), PHP_VERSION),
        );
        self::assertNotFalse($written, 'Cannot write argon2-timing.txt');

        self::assertStringStartsWith(self::PRODUCTION_PREFIX, $hash);
        self::assertLessThan(2.0, $seconds);
    }
}
