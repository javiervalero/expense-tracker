<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Http\ApiKernel;
use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseEmitter;
use App\Infrastructure\Logging\JsonLogger;
use App\Tests\Support\Contract\ContractValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A real 500, produced in process (a handler that throws, behind the error middleware and the kernel, then written
 * by the emitter as php-fpm would), is checked against the bundled contract. The HTTP tests cannot trigger a 500 on
 * demand, so this is where the documented 500 meets the code that produces it.
 */
final class ServerErrorMatchesContractTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    #[DataProvider('methods')]
    public function testAThrownErrorAnswersTheDocumentedProblem500(string $method): void
    {
        [$status, $headers, $body] = $this->emitted($method);

        $violations = (new ContractValidator(BundledSpec::document()))->validate($method, '/health', $status, $headers, $body);

        self::assertSame(500, $status);
        self::assertSame([], array_map(static fn($violation): string => $violation->location . ': ' . $violation->message, $violations));
        self::assertSame($method === 'HEAD', $body === '');
    }

    /** @return array{int, array<string, string>, string} */
    private function emitted(string $method): array
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $logger = new JsonLogger($stream);
        $throwing = new class implements Handler {
            public function handle(Request $request): Response
            {
                throw new RuntimeException('boom');
            }
        };
        $kernel = new ApiKernel(new Pipeline([new ErrorHandlingMiddleware($logger)], $throwing), null, $logger);
        $response = $kernel->handle(new Request($method, '/api/v1/health', [], [], ''));

        $status = 0;
        $headers = [];
        $body = '';
        (new ResponseEmitter(
            static function (int $code) use (&$status): void {
                $status = $code;
            },
            static function (string $line) use (&$headers): void {
                [$name, $value] = explode(': ', $line, 2);
                $headers[$name] = $value;
            },
            static function (string $chunk) use (&$body): void {
                $body .= $chunk;
            },
        ))->emit($response, $method);

        return [$status, $headers, $body];
    }
}
