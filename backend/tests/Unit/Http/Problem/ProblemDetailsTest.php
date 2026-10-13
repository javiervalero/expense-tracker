<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Problem;

use App\Http\Problem\FieldError;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ProblemDetails;
use App\Http\Problem\ValidationProblem;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function testTypeDefaultsToAboutBlankAndOptionalMembersAreOmitted(): void
    {
        $problem = new ProblemDetails(status: 404, title: 'Not Found');

        self::assertSame(['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404], $problem->toArray());
    }

    public function testAllMembersAreSerialisedInRfcOrder(): void
    {
        $problem = new ProblemDetails(
            status: 409,
            title: 'Conflict',
            type: 'https://example.test/problems/conflict',
            detail: 'Already there.',
            instance: '/api/v1/things/1',
            extensions: ['retryAfter' => 3],
        );

        self::assertSame(
            [
                'type' => 'https://example.test/problems/conflict',
                'title' => 'Conflict',
                'status' => 409,
                'detail' => 'Already there.',
                'instance' => '/api/v1/things/1',
                'retryAfter' => 3,
            ],
            $problem->toArray(),
        );
    }

    #[DataProvider('statusTitles')]
    public function testForStatusUsesTheStatusPhraseAsTitle(int $status, string $title): void
    {
        $problem = ProblemDetails::forStatus($status, 'why');

        self::assertSame(
            ['type' => 'about:blank', 'title' => $title, 'status' => $status, 'detail' => 'why'],
            $problem->toArray(),
        );
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statusTitles(): iterable
    {
        yield '400' => [400, 'Bad Request'];
        yield '401' => [401, 'Unauthorized'];
        yield '403' => [403, 'Forbidden'];
        yield '404' => [404, 'Not Found'];
        yield '405' => [405, 'Method Not Allowed'];
        yield '413' => [413, 'Content Too Large'];
        yield '415' => [415, 'Unsupported Media Type'];
        yield '422' => [422, 'Unprocessable Content'];
        yield '429' => [429, 'Too Many Requests'];
        yield '500' => [500, 'Internal Server Error'];
        yield '503' => [503, 'Service Unavailable'];
        yield 'unlisted' => [418, 'Error'];
    }

    #[DataProvider('reservedMembers')]
    public function testAnExtensionCannotOverrideAStandardMember(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProblemDetails(status: 400, title: 'Bad Request', extensions: [$name => 'x']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedMembers(): iterable
    {
        foreach (['type', 'title', 'status', 'detail', 'instance'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('invalidStatuses')]
    public function testStatusMustBeAnErrorStatus(int $status): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProblemDetails(status: $status, title: 'x');
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidStatuses(): iterable
    {
        yield 'ok' => [200];
        yield 'below range' => [399];
        yield 'above range' => [600];
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    public function testHttpProblemCarriesItsProblemAndPrevious(): void
    {
        $previous = new \RuntimeException('root cause');
        $problem = new HttpProblem(ProblemDetails::forStatus(400, 'Broken.'), $previous);

        self::assertSame(400, $problem->problem->status);
        self::assertSame('Broken.', $problem->getMessage());
        self::assertSame($previous, $problem->getPrevious());
    }

    public function testHttpProblemOfBuildsAProblemFromAStatus(): void
    {
        $problem = HttpProblem::of(413, 'Too big.');

        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Content Too Large', 'status' => 413, 'detail' => 'Too big.'],
            $problem->problem->toArray(),
        );
    }

    public function testValidationProblemListsEveryFieldError(): void
    {
        $problem = new ValidationProblem([
            new FieldError('/amount', 'Must be positive.'),
            new FieldError('/tags/0', 'Must not be empty.'),
        ]);

        self::assertSame(422, $problem->problem->status);
        self::assertSame(
            [
                'type' => 'about:blank',
                'title' => 'Unprocessable Content',
                'status' => 422,
                'detail' => 'One or more fields are invalid.',
                'errors' => [
                    ['pointer' => '/amount', 'detail' => 'Must be positive.'],
                    ['pointer' => '/tags/0', 'detail' => 'Must not be empty.'],
                ],
            ],
            $problem->problem->toArray(),
        );
    }

    public function testValidationProblemIsAnHttpProblem(): void
    {
        $this->expectException(HttpProblem::class);

        throw new ValidationProblem([new FieldError('', 'Bad.')]);
    }

    public function testValidationProblemWithoutErrorsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ValidationProblem([]);
    }

    public function testFieldErrorPointerOfTheWholeBodyIsTheEmptyString(): void
    {
        self::assertSame(['pointer' => '', 'detail' => 'Bad.'], (new FieldError('', 'Bad.'))->toArray());
    }

    public function testFieldErrorPointerMustBeEmptyOrStartWithASlash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new FieldError('amount', 'Bad.');
    }
}
