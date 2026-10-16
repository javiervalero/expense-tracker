<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http\Routing;

use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Http\Routing\RouteResult;
use App\Http\Routing\Router;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testAStaticRouteMatches(): void
    {
        $health = $this->route('GET', '/health');

        $result = $this->router($health)->match($this->request('GET', '/api/v1/health'));

        self::assertTrue($result->isMatch());
        self::assertSame($health, $result->route);
        self::assertSame([], $result->params);
    }

    public function testATemplatedRouteExtractsItsParameters(): void
    {
        $route = $this->route('GET', '/categories/{categoryId}/expenses/{expenseId}');

        $result = $this->router($route)->match($this->request('GET', '/api/v1/categories/c1/expenses/e-2'));

        self::assertSame($route, $result->route);
        self::assertSame(['categoryId' => 'c1', 'expenseId' => 'e-2'], $result->params);
    }

    public function testTheMethodSelectsAmongRoutesOnTheSamePath(): void
    {
        $get = $this->route('GET', '/expenses');
        $post = $this->route('POST', '/expenses');
        $router = $this->router($get, $post);

        self::assertSame($post, $router->match($this->request('POST', '/api/v1/expenses'))->route);
        self::assertSame($get, $router->match($this->request('GET', '/api/v1/expenses'))->route);
    }

    public function testTheFirstRegisteredRouteWinsWhenALiteralAndAParameterBothMatch(): void
    {
        $literal = $this->route('GET', '/expenses/export');
        $param = $this->route('GET', '/expenses/{id}');

        self::assertSame($literal, $this->router($literal, $param)->match($this->request('GET', '/api/v1/expenses/export'))->route);
        self::assertSame($param, $this->router($literal, $param)->match($this->request('GET', '/api/v1/expenses/other'))->route);
    }

    public function testAnUnknownPathIsNotFound(): void
    {
        $result = $this->router($this->route('GET', '/health'))->match($this->request('GET', '/api/v1/nope'));

        self::assertTrue($result->isNotFound());
        self::assertFalse($result->isMatch());
    }

    #[DataProvider('pathsThatAreNotFound')]
    public function testPathsThatAreNotFound(string $path): void
    {
        $router = $this->router($this->route('GET', '/health'), $this->route('GET', '/expenses/{id}'));

        self::assertTrue($router->match($this->request('GET', $path))->isNotFound(), $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsThatAreNotFound(): iterable
    {
        yield 'trailing slash' => ['/api/v1/health/'];
        yield 'trailing slash on templated' => ['/api/v1/expenses/1/'];
        yield 'double slash inside' => ['/api/v1//health'];
        yield 'double slash before prefix' => ['//api/v1/health'];
        yield 'double slash after prefix' => ['/api/v1//expenses/1'];
        yield 'other version' => ['/api/v2/health'];
        yield 'longer version' => ['/api/v10/health'];
        yield 'no version' => ['/api/health'];
        yield 'outside the api' => ['/health'];
        yield 'root' => ['/'];
        yield 'empty path' => [''];
        yield 'prefix only' => ['/api/v1'];
        yield 'prefix and slash only' => ['/api/v1/'];
        yield 'no leading slash' => ['api/v1/health'];
        yield 'upper case' => ['/API/V1/HEALTH'];
        yield 'dot segments' => ['/api/v1/expenses/../health'];
        yield 'single dot segment' => ['/api/v1/./health'];
        yield 'extra segment' => ['/api/v1/health/extra'];
        yield 'missing segment' => ['/api/v1/expenses'];
        yield 'empty parameter' => ['/api/v1/expenses/'];
        yield 'encoded slash in the prefix' => ['/api%2Fv1/health'];
        yield 'encoded slash makes the literal differ' => ['/api/v1%2Fhealth'];
        yield 'encoded literal does not match the literal' => ['/api/v1/%68ealth'];
        yield 'absolute form uri' => ['http://example.test/api/v1/health'];
        yield 'query string inside the path is not a query' => ['/api/v1/health?x=1'];
        yield 'fragment inside the path' => ['/api/v1/health#x'];
        yield 'backslash' => ['/api/v1\\health'];
        yield 'unicode' => ["/api/v1/h\u{e9}alth"];
        yield 'sql characters' => ["/api/v1/health' OR '1'='1"];
        yield 'space' => ['/api/v1/health '];
    }

    public function testAParameterNeverMatchesAnEmptyString(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertTrue($router->match($this->request('GET', '/api/v1/expenses/'))->isNotFound());
    }

    public function testAParameterNeverSpansASlash(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertTrue($router->match($this->request('GET', '/api/v1/expenses/a/b'))->isNotFound());
    }

    #[DataProvider('unsafeParameterValues')]
    public function testAParameterThatDecodesToAPathOrInvisibleCharacterIsNotFound(string $segment): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertTrue($router->match($this->request('GET', '/api/v1/expenses/' . $segment))->isNotFound(), $segment);
        self::assertTrue($router->match($this->request('DELETE', '/api/v1/expenses/' . $segment))->isNotFound(), $segment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeParameterValues(): iterable
    {
        yield 'encoded traversal' => ['..%2F..%2Fusers%2F42'];
        yield 'encoded slash' => ['a%2Fb'];
        yield 'encoded slash in lower case' => ['a%2fb'];
        yield 'dot dot' => ['..'];
        yield 'encoded dot dot' => ['%2e%2e'];
        yield 'dot' => ['.'];
        yield 'encoded backslash' => ['a%5Cb'];
        yield 'bidi override' => ['%E2%80%AE'];
        yield 'next line control' => ['%C2%85'];
        yield 'zero width joiner (format)' => ['a%E2%80%8Db'];
        yield 'private use' => ['a%EE%80%80b'];
        yield 'unassigned' => ['a%EF%BF%BEb'];
        yield 'encoded utf-16 surrogate code point (invalid utf-8)' => ['a%ED%A0%80b'];
        yield 'mixed-case encoded dot dot' => ['%2E%2e'];
        yield 'literal dot then encoded dot' => ['.%2e'];
        yield 'overlong encoded dot (invalid utf-8)' => ['%C0%AE'];
        yield 'line separator U+2028' => ['a%E2%80%A8b'];
        yield 'paragraph separator U+2029' => ['a%E2%80%A9b'];
    }

    public function testHarmlessParameterValuesWithDotsAndHashesStillMatch(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertSame(['id' => '...'], $router->match($this->request('GET', '/api/v1/expenses/...'))->params);
        self::assertSame(['id' => 'a.b'], $router->match($this->request('GET', '/api/v1/expenses/a.b'))->params);
        self::assertSame(['id' => 'a#b'], $router->match($this->request('GET', '/api/v1/expenses/a%23b'))->params);
    }

    public function testAnEncodedSlashIsNeverASeparatorBetweenTemplateSegments(): void
    {
        $router = $this->router($this->route('GET', '/categories/{id}/expenses'));

        self::assertTrue($router->match($this->request('GET', '/api/v1/categories%2F1/expenses'))->isNotFound());
        self::assertTrue($router->match($this->request('GET', '/api/v1/categories/1%2Fexpenses'))->isNotFound());
    }

    public function testParametersArePercentDecodedOnceAndPlusStaysAPlus(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertSame(['id' => 'a b+c'], $router->match($this->request('GET', '/api/v1/expenses/a%20b+c'))->params);
        self::assertSame(['id' => "caf\u{e9}"], $router->match($this->request('GET', '/api/v1/expenses/caf%C3%A9'))->params);
        self::assertSame(['id' => '%41'], $router->match($this->request('GET', '/api/v1/expenses/%2541'))->params);
    }

    public function testADoubleEncodedDotDotIsDecodedOnlyOnce(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        self::assertSame(['id' => '%2e%2e'], $router->match($this->request('GET', '/api/v1/expenses/%252e%252e'))->params);
    }

    #[DataProvider('undecodablePaths')]
    public function testPathsWithBadEncodingOrControlCharactersAreNotFound(string $path): void
    {
        $router = $this->router($this->route('GET', '/health'), $this->route('GET', '/expenses/{id}'));

        self::assertTrue($router->match($this->request('GET', $path))->isNotFound(), $path);
        self::assertTrue($router->match($this->request('DELETE', $path))->isNotFound(), 'any method: ' . $path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function undecodablePaths(): iterable
    {
        yield 'raw null byte' => ["/api/v1/expenses/a\0b"];
        yield 'null byte after a static route' => ["/api/v1/health\0"];
        yield 'encoded null byte' => ['/api/v1/expenses/a%00b'];
        yield 'percent without digits' => ['/api/v1/expenses/%'];
        yield 'percent with one digit' => ['/api/v1/expenses/%4'];
        yield 'percent with non-hex digits' => ['/api/v1/expenses/%zz'];
        yield 'invalid escape in a literal segment' => ['/api/v1/he%alth'];
        yield 'invalid utf-8 after decoding' => ['/api/v1/expenses/%FF'];
        yield 'truncated utf-8' => ['/api/v1/expenses/%C3'];
        yield 'overlong slash' => ['/api/v1/expenses/%C0%AF'];
        yield 'raw invalid utf-8 byte' => ["/api/v1/expenses/\xFF"];
        yield 'line feed' => ["/api/v1/expenses/a\nb"];
        yield 'carriage return' => ["/api/v1/expenses/a\rb"];
        yield 'delete character' => ["/api/v1/expenses/a\x7Fb"];
        yield 'encoded line feed' => ['/api/v1/expenses/a%0Ab'];
    }

    public function testAnOverlongPathIsNotFound(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));

        $path = '/api/v1/expenses/' . str_repeat('a', Router::MAX_PATH_LENGTH);

        self::assertTrue($router->match($this->request('GET', $path))->isNotFound());
    }

    public function testAPathOfExactlyTheMaximumLengthStillMatches(): void
    {
        $router = $this->router($this->route('GET', '/expenses/{id}'));
        $prefix = '/api/v1/expenses/';

        $result = $router->match($this->request('GET', $prefix . str_repeat('a', Router::MAX_PATH_LENGTH - strlen($prefix))));

        self::assertTrue($result->isMatch());
    }

    public function testTheQueryIsIgnoredForRouting(): void
    {
        $route = $this->route('GET', '/health');

        $result = $this->router($route)->match(new Request('GET', '/api/v1/health', ['a' => '1', 'b' => ''], [], ''));

        self::assertSame($route, $result->route);
        self::assertSame([], $result->params);
    }

    public function testAKnownPathWithAnotherMethodIsMethodNotAllowedWithTheAllowList(): void
    {
        $router = $this->router($this->route('GET', '/expenses'), $this->route('POST', '/expenses'));

        $result = $router->match($this->request('DELETE', '/api/v1/expenses'));

        self::assertTrue($result->isMethodNotAllowed());
        self::assertSame(['GET', 'POST'], $result->allow);
    }

    public function testAllowListCoversEveryTemplateThatMatchesThePath(): void
    {
        $router = $this->router(
            $this->route('GET', '/expenses/export'),
            $this->route('DELETE', '/expenses/{id}'),
            $this->route('GET', '/expenses/{id}'),
        );

        $result = $router->match($this->request('PUT', '/api/v1/expenses/export'));

        self::assertSame(['GET', 'DELETE'], $result->allow);
    }

    public function testALowerCaseMethodIsMethodNotAllowedNotAMatch(): void
    {
        $result = $this->router($this->route('GET', '/health'))->match($this->request('get', '/api/v1/health'));

        self::assertTrue($result->isMethodNotAllowed());
        self::assertSame(['GET'], $result->allow);
    }

    public function testHeadIsNeverImplicitlyGet(): void
    {
        $result = $this->router($this->route('GET', '/health'))->match($this->request('HEAD', '/api/v1/health'));

        self::assertTrue($result->isMethodNotAllowed());
        self::assertSame(['GET'], $result->allow);
    }

    public function testHeadMatchesWhenRegisteredExplicitly(): void
    {
        $head = $this->route('HEAD', '/health');

        $result = $this->router($this->route('GET', '/health'), $head)->match($this->request('HEAD', '/api/v1/health'));

        self::assertSame($head, $result->route);
    }

    public function testOptionsIsMethodNotAllowed(): void
    {
        $result = $this->router($this->route('GET', '/health'))->match($this->request('OPTIONS', '/api/v1/health'));

        self::assertTrue($result->isMethodNotAllowed());
    }

    public function testAnEmptyMethodIsMethodNotAllowedOnAKnownPathAndNotFoundOtherwise(): void
    {
        $router = $this->router($this->route('GET', '/health'));

        self::assertTrue($router->match($this->request('', '/api/v1/health'))->isMethodNotAllowed());
        self::assertTrue($router->match($this->request('', '/api/v1/other'))->isNotFound());
    }

    public function testRoutesAreExposedInRegistrationOrder(): void
    {
        $a = $this->route('GET', '/a');
        $b = $this->route('POST', '/a');

        self::assertSame([$a, $b], $this->router($a, $b)->routes());
    }

    public function testNotFoundResultBuildsAProblemResponse(): void
    {
        $response = RouteResult::notFound()->problemResponse();

        self::assertSame(404, $response->status);
        self::assertSame(['application/problem+json'], $response->headerValues('Content-Type'));
        self::assertSame([], $response->headerValues('Allow'));
        self::assertSame('{"type":"about:blank","title":"Not Found","status":404}', $response->body);
    }

    public function testMethodNotAllowedResultBuildsAProblemResponseWithAllow(): void
    {
        $response = RouteResult::methodNotAllowed(['GET', 'HEAD'])->problemResponse();

        self::assertSame(405, $response->status);
        self::assertSame(['application/problem+json'], $response->headerValues('Content-Type'));
        self::assertSame(['GET, HEAD'], $response->headerValues('Allow'));
        self::assertSame('{"type":"about:blank","title":"Method Not Allowed","status":405}', $response->body);
    }

    public function testAMatchedResultHasNoProblemResponse(): void
    {
        $this->expectException(LogicException::class);

        RouteResult::matched($this->route('GET', '/a'), [])->problemResponse();
    }

    #[DataProvider('invalidRoutes')]
    public function testInvalidRoutesAreRejected(string $method, string $template): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->route($method, $template);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'lower case method' => ['get', '/a'];
        yield 'empty method' => ['', '/a'];
        yield 'method with a space' => ['GE T', '/a'];
        yield 'empty template' => ['GET', ''];
        yield 'root template' => ['GET', '/'];
        yield 'no leading slash' => ['GET', 'a'];
        yield 'trailing slash' => ['GET', '/a/'];
        yield 'empty segment' => ['GET', '/a//b'];
        yield 'empty parameter name' => ['GET', '/a/{}'];
        yield 'parameter name with a dash' => ['GET', '/a/{my-id}'];
        yield 'parameter name starting with a digit' => ['GET', '/a/{1id}'];
        yield 'unclosed parameter' => ['GET', '/a/{id'];
        yield 'parameter mixed with literal text' => ['GET', '/a/x{id}'];
        yield 'duplicate parameter name' => ['GET', '/a/{id}/b/{id}'];
        yield 'the prefix is added by the router' => ['GET', '/api/v1/a'];
        yield 'encoded character in the template' => ['GET', '/a%2Fb'];
        yield 'dot segment in the template' => ['GET', '/a/./b'];
        yield 'dot dot segment in the template' => ['GET', '/a/..'];
        yield 'lone dot template' => ['GET', '/.'];
        yield 'trailing newline in the template' => ['GET', "/a\n"];
        yield 'trailing newline in the method' => ["GET\n", '/a'];
    }

    public function testAParameterRouteShadowingALaterRouteOfTheSameMethodIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->router($this->route('GET', '/expenses/{id}'), $this->route('GET', '/expenses/export'));
    }

    public function testAShadowingParameterRouteIsRejectedEvenWhenTheLaterRouteHasParametersToo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->router($this->route('GET', '/a/{x}/{y}'), $this->route('GET', '/a/b/{z}'));
    }

    public function testRoutesThatOnlyPartlyOverlapOrUseAnotherMethodAreAccepted(): void
    {
        $router = $this->router(
            $this->route('GET', '/expenses/export'),
            $this->route('GET', '/expenses/{id}'),
            $this->route('DELETE', '/expenses/{id}'),
            $this->route('GET', '/{kind}/b'),
            $this->route('GET', '/a/{y}'),
            $this->route('GET', '/expenses/{id}/lines'),
        );

        self::assertCount(6, $router->routes());
    }

    public function testDuplicateRoutesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->router($this->route('GET', '/a/{id}'), $this->route('GET', '/a/{other}'));
    }

    public function testTheSamePathWithAnotherMethodIsNotADuplicate(): void
    {
        $router = $this->router($this->route('GET', '/a/{id}'), $this->route('DELETE', '/a/{id}'));

        self::assertCount(2, $router->routes());
    }

    private function route(string $method, string $template): Route
    {
        return new Route($method, $template, new class implements Handler {
            public function handle(Request $request): Response
            {
                return Response::empty(204);
            }
        });
    }

    private function router(Route ...$routes): Router
    {
        return new Router(array_values($routes));
    }

    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], '');
    }
}
