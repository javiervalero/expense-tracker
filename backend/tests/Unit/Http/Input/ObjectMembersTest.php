<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Input;

use App\Http\Input\ObjectMembers;
use PHPUnit\Framework\TestCase;

final class ObjectMembersTest extends TestCase
{
    public function testKnownMembersGiveNoErrors(): void
    {
        self::assertSame([], ObjectMembers::unknownErrors(['a' => 1, 'b' => 2], ['a', 'b', 'c']));
    }

    public function testAnEmptyBodyGivesNoErrors(): void
    {
        self::assertSame([], ObjectMembers::unknownErrors([], ['a']));
    }

    public function testAnUnknownMemberIsReportedByPointer(): void
    {
        $errors = ObjectMembers::unknownErrors(['a' => 1, 'extra' => 2], ['a']);

        self::assertCount(1, $errors);
        self::assertSame('/extra', $errors[0]->pointer);
        self::assertSame('This field is not allowed.', $errors[0]->detail);
    }

    public function testAtMostFiveUnknownMembersAreReported(): void
    {
        $body = [];
        for ($i = 0; $i < 20; ++$i) {
            $body["k{$i}"] = $i;
        }

        $errors = ObjectMembers::unknownErrors($body, []);

        self::assertCount(5, $errors);
        self::assertSame('/k0', $errors[0]->pointer);
        self::assertSame('/k4', $errors[4]->pointer);
    }

    public function testIntegerKeysAreReportedAsText(): void
    {
        $errors = ObjectMembers::unknownErrors([0 => 'x'], ['a']);

        self::assertSame('/0', $errors[0]->pointer);
    }

    public function testAnUnsafeKeyIsReportedForTheWholeBody(): void
    {
        $errors = ObjectMembers::unknownErrors(
            ["a/b" => 1, "x\x00" => 1, 'sp ace' => 1, str_repeat('k', 65) => 1, '' => 1, "~" => 1],
            [],
        );

        self::assertCount(5, $errors);
        foreach ($errors as $error) {
            self::assertSame('', $error->pointer);
            self::assertSame('This field is not allowed.', $error->detail);
        }
    }

    public function testAKeyOf64SafeCharactersIsEchoedAndAnUnsafeOneIsNot(): void
    {
        $key = str_repeat('k', 63) . '.';

        $errors = ObjectMembers::unknownErrors([$key => 1, "bad\n" => 1], []);

        self::assertSame('/' . $key, $errors[0]->pointer);
        self::assertSame('', $errors[1]->pointer);
    }

    public function testAKeyEndingInANewlineIsNotSafe(): void
    {
        self::assertSame('', ObjectMembers::unknownErrors(["abc\n" => 1], [])[0]->pointer);
    }
}
