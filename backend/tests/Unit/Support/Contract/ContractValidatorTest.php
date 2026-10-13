<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support\Contract;

use App\Tests\Support\Contract\ContractValidator;
use App\Tests\Support\Contract\ContractViolation;
use App\Tests\Support\Contract\OpenApiDocument;
use PHPUnit\Framework\TestCase;

final class ContractValidatorTest extends TestCase
{
    private const array JSON = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
    private const array PROBLEM = ['Content-Type' => 'application/problem+json; charset=utf-8', 'Cache-Control' => 'no-store'];
    private const array VALID_EXPENSE = ['id' => 'e1', 'kind' => 'expense', 'amount' => 1250, 'link' => '/expenses/e1'];

    private static function validator(): ContractValidator
    {
        return new ContractValidator(
            OpenApiDocument::fromFile(__DIR__ . '/../../../Fixtures/contract/openapi.fixture.json'),
        );
    }

    /**
     * @param array<string, string> $headers
     *
     * @return list<string> messages prefixed by the violation location
     */
    private static function check(string $method, string $path, int $status, array $headers, string $body): array
    {
        return array_map(
            static fn(ContractViolation $v): string => $v->location . ': ' . $v->message,
            self::validator()->validate($method, $path, $status, $headers, $body),
        );
    }

    private static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function testAValidBodyPasses(): void
    {
        self::assertSame([], self::check('GET', '/expenses/e1', 200, self::JSON, self::json(self::VALID_EXPENSE)));
    }

    public function testAnExtraPropertyFails(): void
    {
        $body = self::json([...self::VALID_EXPENSE, 'surprise' => true]);

        $violations = self::check('GET', '/expenses/e1', 200, self::JSON, $body);

        self::assertCount(1, $violations);
        self::assertStringStartsWith('body: ', $violations[0]);
        self::assertStringContainsString('surprise', $violations[0]);
    }

    public function testAWrongTypeFailsThroughAComponentsRefChain(): void
    {
        $body = self::json([...self::VALID_EXPENSE, 'amount' => 'a lot']);

        $violations = self::check('GET', '/expenses/e1', 200, self::JSON, $body);

        self::assertCount(1, $violations);
        self::assertStringContainsString('/amount', $violations[0]);
    }

    public function testAConstMismatchFails(): void
    {
        $body = self::json([...self::VALID_EXPENSE, 'kind' => 'income']);

        $violations = self::check('GET', '/expenses/e1', 200, self::JSON, $body);

        self::assertCount(1, $violations);
        self::assertStringContainsString('/kind', $violations[0]);
    }

    public function testAnUndocumentedStatusFails(): void
    {
        $violations = self::check('GET', '/expenses/e1', 500, self::JSON, '{}');

        self::assertSame(['status: GET /expenses/{id} documents no 500 response'], $violations);
    }

    public function testAnUndocumentedContentTypeFails(): void
    {
        $headers = ['Content-Type' => 'text/html', 'Cache-Control' => 'no-store'];

        $violations = self::check('GET', '/expenses/e1', 200, $headers, '<p>hi</p>');

        self::assertSame(['content-type: text/html is not documented for GET /expenses/{id} 200 (documented: application/json)'], $violations);
    }

    public function testAMissingContentTypeWithABodyFails(): void
    {
        $violations = self::check('GET', '/expenses/e1', 200, ['Cache-Control' => 'no-store'], self::json(self::VALID_EXPENSE));

        self::assertCount(1, $violations);
        self::assertStringStartsWith('content-type: ', $violations[0]);
    }

    public function testAMissingRequiredHeaderFails(): void
    {
        $violations = self::check('GET', '/expenses/e1', 200, ['Content-Type' => 'application/json'], self::json(self::VALID_EXPENSE));

        self::assertSame(['header: required header Cache-Control is missing'], $violations);
    }

    public function testHeaderNamesAreCaseInsensitive(): void
    {
        $headers = ['content-type' => 'Application/JSON', 'CACHE-CONTROL' => 'no-store'];

        self::assertSame([], self::check('GET', '/expenses/e1', 200, $headers, self::json(self::VALID_EXPENSE)));
    }

    public function testAProblemJsonResponseThroughAResponseRefPasses(): void
    {
        $body = self::json(['type' => '/problems/not-found', 'title' => 'Not Found', 'status' => 404]);

        self::assertSame([], self::check('GET', '/expenses/e1', 404, self::PROBLEM, $body));
    }

    public function testAProblemMissingItsTitleFails(): void
    {
        $violations = self::check('GET', '/expenses/e1', 404, self::PROBLEM, self::json(['status' => 404]));

        self::assertCount(1, $violations);
        self::assertStringContainsString('title', $violations[0]);
    }

    public function testHeadIsCheckedWithNoBody(): void
    {
        $headers = ['Content-Type' => 'application/json'];

        self::assertSame([], self::check('HEAD', '/health', 200, $headers, ''));
        self::assertSame(['body: HEAD /health 200 must have no body'], self::check('HEAD', '/health', 200, $headers, '{}'));
    }

    public function testAResponseWithoutContentMustHaveNoBody(): void
    {
        self::assertSame([], self::check('GET', '/expenses/summary', 204, [], ''));
        self::assertCount(1, self::check('GET', '/expenses/summary', 204, [], '{}'));
    }

    public function testANonMatchingPathFailsAsNoSuchOperation(): void
    {
        self::assertSame(['operation: no such operation: GET /nope'], self::check('GET', '/nope', 404, self::PROBLEM, '{}'));
    }

    public function testAMethodTheSpecDoesNotListFailsAsNoSuchOperation(): void
    {
        self::assertSame(['operation: no such operation: DELETE /expenses/e1'], self::check('DELETE', '/expenses/e1', 200, self::JSON, '{}'));
    }

    public function testABodyThatIsNotJsonFails(): void
    {
        $violations = self::check('GET', '/expenses/e1', 200, self::JSON, '{not json');

        self::assertCount(1, $violations);
        self::assertStringStartsWith('body: ', $violations[0]);
    }

    public function testAnnotationKeywordsAndUriReferenceFormatDoNotError(): void
    {
        // The fixture schema carries `example`, `discriminator` and `format: uri-reference`.
        $body = self::json([...self::VALID_EXPENSE, 'link' => '../relative/ref?x=1#frag']);

        self::assertSame([], self::check('GET', '/expenses/e1', 200, self::JSON, $body));
    }

    public function testSpecialCharactersInTheBodyAreHandled(): void
    {
        $body = self::json([...self::VALID_EXPENSE, 'id' => "caf\u{e9} \u{1F4B8} ' OR 1=1 --"]);

        self::assertSame([], self::check('GET', '/expenses/e1', 200, self::JSON, $body));
    }

    public function testLoadsTheRealBundledSpec(): void
    {
        $file = __DIR__ . '/../../../../../contract/dist/openapi.json';
        if (!is_file($file)) {
            self::markTestSkipped('contract/dist/openapi.json is missing; run `make contract-bundle`.');
        }
        $validator = new ContractValidator(OpenApiDocument::fromFile($file));
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];

        self::assertSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"ok"}'));
        self::assertNotSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"down"}'));
        self::assertNotSame([], $validator->validate('GET', '/health', 200, $headers, '{"status":"ok","x":1}'));
    }
}
