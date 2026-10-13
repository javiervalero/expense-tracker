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
     * @return array<string, string>
     */
    private function headersOf(\App\Http\Request $request): array
    {
        return $request->headers;
    }
}
