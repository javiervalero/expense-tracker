<?php

declare(strict_types=1);

namespace App\Tests\Unit\Auth\Http;

use App\Auth\Http\CredentialsInput;
use App\Http\Problem\ValidationProblem;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CredentialsInputTest extends TestCase
{
    private const PASSWORD = 'correct horse staple 42';

    /**
     * @param array<array-key, mixed> $body
     *
     * @return list<array{pointer: string, detail: string}>
     */
    private static function errorsOf(array $body): array
    {
        try {
            CredentialsInput::fromBody($body);
        } catch (ValidationProblem $problem) {
            $errors = $problem->problem->extensions['errors'] ?? null;
            self::assertIsArray($errors);

            $list = [];
            foreach ($errors as $error) {
                self::assertIsArray($error);
                self::assertIsString($error['pointer'] ?? null);
                self::assertIsString($error['detail'] ?? null);
                $list[] = ['pointer' => $error['pointer'], 'detail' => $error['detail']];
            }

            return $list;
        }
        self::fail('expected a ValidationProblem');
    }

    /**
     * @param list<array{pointer: string, detail: string}> $errors
     *
     * @return list<string>
     */
    private static function pointers(array $errors): array
    {
        return array_map(static fn(array $error): string => $error['pointer'], $errors);
    }

    public function testExactlyEmailAndPasswordAsStringsAreAccepted(): void
    {
        $input = CredentialsInput::fromBody(['email' => ' Ana@Example.com ', 'password' => self::PASSWORD]);

        self::assertSame(' Ana@Example.com ', $input->email);
        self::assertSame(self::PASSWORD, $input->password());
    }

    public function testEmptyStringsAreShapeValidAndLeftToTheRules(): void
    {
        $input = CredentialsInput::fromBody(['email' => '', 'password' => '']);

        self::assertSame('', $input->email);
        self::assertSame('', $input->password());
    }

    public function testAMissingMemberIsReportedAtItsPointer(): void
    {
        self::assertSame(['/password'], self::pointers(self::errorsOf(['email' => 'ana@example.com'])));
        self::assertSame(['/email'], self::pointers(self::errorsOf(['password' => self::PASSWORD])));
    }

    public function testAnEmptyObjectReportsBothMembers(): void
    {
        self::assertSame(['/email', '/password'], self::pointers(self::errorsOf([])));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notStrings(): iterable
    {
        yield 'null' => [null];
        yield 'integer' => [42];
        yield 'float' => [1.5];
        yield 'boolean' => [true];
        yield 'list' => [['a', 'b']];
        yield 'object' => [['nested' => 'x']];
        yield 'numeric-key object' => [['0' => 'a', '1' => 'b']];
        yield 'empty array' => [[]];
    }

    #[DataProvider('notStrings')]
    public function testANonStringEmailOrPasswordIsReportedAtItsPointer(mixed $value): void
    {
        self::assertSame(['/email'], self::pointers(self::errorsOf(['email' => $value, 'password' => self::PASSWORD])));
        self::assertSame(['/password'], self::pointers(self::errorsOf(['email' => 'ana@example.com', 'password' => $value])));
    }

    public function testAnUnknownKeyIsReportedAtItsPointer(): void
    {
        $errors = self::errorsOf(['email' => 'ana@example.com', 'password' => self::PASSWORD, 'role' => 'admin']);

        self::assertSame(['/role'], self::pointers($errors));
    }

    public function testAKeyWithPointerSyntaxCharactersIsReportedForTheWholeBody(): void
    {
        $errors = self::errorsOf(['email' => 'ana@example.com', 'password' => self::PASSWORD, 'a/b~c' => 1]);

        self::assertSame([''], self::pointers($errors));
    }

    public function testADigitOnlyKeyBecomesAnIntegerKeyAndIsStillReported(): void
    {
        $errors = self::errorsOf(['email' => 'ana@example.com', 'password' => self::PASSWORD, 7 => 'x']);

        self::assertSame(['/7'], self::pointers($errors));
    }

    public function testAKeyThatIsLongOrHasControlCharactersIsReportedForTheWholeBody(): void
    {
        $long = str_repeat('k', 65);
        $errors = self::errorsOf(['email' => 'ana@example.com', 'password' => self::PASSWORD, $long => 1, "bad\x00key" => 1, "esc\x1b[31m" => 1]);

        self::assertSame(['', '', ''], self::pointers($errors));
    }

    public function testNoMessageRepeatsAValueOrAKey(): void
    {
        $secret = 'hunter2-invented-value';
        $errors = self::errorsOf(['email' => [$secret], 'password' => [$secret], 'unexpected-name' => $secret]);

        foreach ($errors as $error) {
            self::assertStringNotContainsString($secret, $error['detail']);
            self::assertStringNotContainsString('unexpected-name', $error['detail']);
        }
        self::assertCount(3, $errors);
    }

    public function testShapeErrorsAreAllReportedTogether(): void
    {
        $errors = self::errorsOf(['email' => null, 'extra' => 'x']);

        self::assertSame(['/email', '/password', '/extra'], self::pointers($errors));
    }

    public function testThePasswordIsHiddenFromDebuggingAndSerialising(): void
    {
        $input = CredentialsInput::fromBody(['email' => 'ana@example.com', 'password' => self::PASSWORD]);

        self::assertStringNotContainsString(self::PASSWORD, print_r($input, true));
        ob_start();
        var_dump($input);
        $dumped = (string) ob_get_clean();
        self::assertStringNotContainsString(self::PASSWORD, $dumped);
        self::assertStringNotContainsString(self::PASSWORD, var_export($input, true));
        self::assertStringNotContainsString(self::PASSWORD, serialize((array) $input));
    }

    public function testItCannotBeEncodedSerialisedOrCloned(): void
    {
        $input = CredentialsInput::fromBody(['email' => 'ana@example.com', 'password' => self::PASSWORD]);

        foreach ([
            static fn() => json_encode($input, JSON_THROW_ON_ERROR),
            static fn() => serialize($input),
            static fn() => unserialize('O:' . strlen(CredentialsInput::class) . ':"' . CredentialsInput::class . '":0:{}'),
            static fn() => clone $input,
        ] as $attempt) {
            try {
                $attempt();
                self::fail('expected a LogicException');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlyTheFirstFiveUnknownKeysAreReported(): void
    {
        $body = ['email' => 'ana@example.com', 'password' => self::PASSWORD];
        for ($i = 0; $i < 50; ++$i) {
            $body['extra' . $i] = 'x';
        }

        $errors = self::errorsOf($body);

        self::assertSame(['/extra0', '/extra1', '/extra2', '/extra3', '/extra4'], self::pointers($errors));
    }

    public function testTheCapCountsOnlyUnknownKeysSoMissingMembersAreStillReported(): void
    {
        $body = [];
        for ($i = 0; $i < 20; ++$i) {
            $body['extra' . $i] = 'x';
        }

        self::assertCount(2 + 5, self::errorsOf($body));
    }

    public function testDebugOutputShowsNeitherTheEmailNorThePassword(): void
    {
        $input = CredentialsInput::fromBody(['email' => 'ana@example.com', 'password' => self::PASSWORD]);

        ob_start();
        var_dump($input);
        $dumped = (string) ob_get_clean() . print_r($input, true);

        self::assertStringNotContainsString('ana@example.com', $dumped);
        self::assertStringNotContainsString(self::PASSWORD, $dumped);
        self::assertStringContainsString('[redacted]', $dumped);
    }
}
