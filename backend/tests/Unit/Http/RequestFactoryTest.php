<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Problem\HttpProblem;
use App\Http\RequestFactory;
use PHPUnit\Framework\TestCase;

final class RequestFactoryTest extends TestCase
{
    public function testBuildsARequestFromServerVariables(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/api/v1/expenses?limit=5',
            'QUERY_STRING' => 'limit=5',
        ], '{"a":1}');

        self::assertSame('POST', $request->method);
        self::assertSame('/api/v1/expenses', $request->path);
        self::assertSame(['limit' => '5'], $request->query);
        self::assertSame('{"a":1}', $request->body);
    }

    public function testHeaderNamesComeFromHttpVariablesLowerCasedWithDashes(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_REQUEST_ID' => 'r1',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_COOKIE' => 'sid=abc',
        ], '');

        self::assertSame(
            ['x-request-id' => 'r1', 'accept' => 'application/json', 'cookie' => 'sid=abc'],
            $this->headersOf($request),
        );
    }

    public function testContentTypeAndLengthAreHeadersToo(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => '7',
        ], '{"a":1}');

        self::assertSame('application/json', $request->header('content-type'));
        self::assertSame('7', $request->header('content-length'));
    }

    public function testHttpPrefixedContentVariablesAreIgnored(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/',
            'HTTP_CONTENT_TYPE' => 'text/plain',
            'HTTP_CONTENT_LENGTH' => '999',
        ], '');

        self::assertSame([], $this->headersOf($request));
    }

    public function testHttpPrefixedContentVariablesNeverOverrideTheRealOnes(): void
    {
        $base = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/'];
        $real = ['CONTENT_TYPE' => 'application/json'];
        $spoofed = ['HTTP_CONTENT_TYPE' => 'text/plain'];

        foreach ([$real + $spoofed, $spoofed + $real] as $variables) {
            $request = RequestFactory::fromGlobals($base + $variables, '');

            self::assertSame('application/json', $request->header('content-type'));
        }
    }

    public function testOnlyTheCanonicalSpellingOfAnHttpVariableIsAHeader(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_x_forwarded_for' => 'lower',
            'HTTP_X-FORWARDED-FOR' => 'dashed',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ], '');

        self::assertSame(['x-forwarded-for' => '10.0.0.1'], $this->headersOf($request));
    }

    public function testTheResultDoesNotDependOnTheOrderOfCollidingVariables(): void
    {
        $base = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'];
        $canonicalFirst = $base + ['HTTP_X_A' => 'canonical', 'HTTP_x_a' => 'other'];
        $canonicalLast = $base + ['HTTP_x_a' => 'other', 'HTTP_X_A' => 'canonical'];

        self::assertSame(['x-a' => 'canonical'], $this->headersOf(RequestFactory::fromGlobals($canonicalFirst, '')));
        self::assertSame(['x-a' => 'canonical'], $this->headersOf(RequestFactory::fromGlobals($canonicalLast, '')));
    }

    public function testNumericHeaderNamesAreKept(): void
    {
        $request = RequestFactory::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'HTTP_123' => 'n'], '');

        self::assertSame('n', $request->header('123'));
    }

    public function testEmptyContentTypeAndLengthAreNotHeaders(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'CONTENT_TYPE' => '',
            'CONTENT_LENGTH' => '',
        ], '');

        self::assertSame([], $this->headersOf($request));
    }

    public function testOtherServerVariablesAreIgnored(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'REMOTE_ADDR' => '10.0.0.1',
            'SERVER_NAME' => 'x',
            'PATH' => '/usr/bin',
            'AWS_SECRET_ACCESS_KEY' => 'must-never-appear',
            'REDIRECT_HTTP_X_FOO' => 'bar',
            'XHTTP_X_FOO' => 'bar',
            'HTTPS' => 'on',
            'HTTP_X_OK' => 'yes',
        ], '');

        self::assertSame(['x-ok' => 'yes'], $this->headersOf($request));
    }

    public function testTheProxyHeaderIsDropped(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_PROXY' => 'http://evil.example',
        ], '');

        self::assertNull($request->header('proxy'));
    }

    public function testNonStringServerValuesAreIgnored(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/',
            'HTTP_X_ARRAY' => ['a'],
            'HTTP_X_INT' => 5,
            'HTTP_X_NULL' => null,
        ], '');

        self::assertSame([], $this->headersOf($request));
    }

    public function testThePathIsKeptRawAndTheQueryIsCutOff(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/a%2Fb/c%20d?x=%2F',
            'QUERY_STRING' => 'x=%2F',
        ], '');

        self::assertSame('/api/v1/a%2Fb/c%20d', $request->path);
        self::assertSame(['x' => '/'], $request->query);
    }

    public function testQueryComesFromQueryStringNotFromTheUri(): void
    {
        $request = RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/api/v1/x?a=1',
            'QUERY_STRING' => '',
        ], '');

        self::assertSame([], $request->query);
    }

    public function testAMissingQueryStringMeansNoQuery(): void
    {
        $request = RequestFactory::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'], '');

        self::assertSame([], $request->query);
    }

    public function testAnInvalidQueryStringGives400(): void
    {
        $this->expectException(HttpProblem::class);

        RequestFactory::fromGlobals([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/?a=1&a=2',
            'QUERY_STRING' => 'a=1&a=2',
        ], '');
    }

    public function testMissingMethodAndUriBecomeAnEmptyMethodAndPath(): void
    {
        $request = RequestFactory::fromGlobals([], '');

        self::assertSame('', $request->method);
        self::assertSame('', $request->path);
    }

    public function testNonStringMethodAndUriBecomeEmpty(): void
    {
        $request = RequestFactory::fromGlobals(['REQUEST_METHOD' => ['GET'], 'REQUEST_URI' => 5], '');

        self::assertSame('', $request->method);
        self::assertSame('', $request->path);
    }

    public function testAFragmentIsNotPartOfThePath(): void
    {
        $request = RequestFactory::fromGlobals(['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/v1/x#frag'], '');

        self::assertSame('/api/v1/x', $request->path);
    }

    /**
     * @return array<array-key, string>
     */
    private function headersOf(\App\Http\Request $request): array
    {
        return $request->headers;
    }
}
