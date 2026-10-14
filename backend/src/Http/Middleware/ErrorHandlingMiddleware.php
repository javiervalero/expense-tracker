<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ProblemDetails;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;
use Throwable;

/**
 * The outermost middleware: nothing thrown below it reaches the client as anything but a problem response.
 *
 *  - a 4xx {@see HttpProblem} is rendered as it describes itself (its message is written to be shown);
 *  - a 5xx {@see HttpProblem} becomes the generic problem for its status (no detail, no extensions) and keeps only
 *    the headers of an allow-list; the detail goes to the log;
 *  - any other Throwable becomes a generic 500 with no message, previous exception or trace. What happened goes
 *    to the log only: request id, class, message, file and line. Never `getPrevious()`, the trace or its
 *    arguments, which can hold secrets or user data.
 */
final readonly class ErrorHandlingMiddleware implements Middleware
{
    private const MAX_MESSAGE_BYTES = 1024;
    private const SERVER_PROBLEM_HEADERS = ['retry-after'];

    public function __construct(private JsonLogger $logger) {}

    public function process(Request $request, Handler $next): Response
    {
        try {
            return $next->handle($request);
        } catch (HttpProblem $problem) {
            return $this->fromProblem($request, $problem);
        } catch (Throwable $error) {
            return $this->internalError($request, $error);
        }
    }

    private function fromProblem(Request $request, HttpProblem $problem): Response
    {
        $details = $problem->problem;
        $headers = $problem->headers;
        if ($details->status >= 500) {
            $this->logger->error('request failed with a server problem', $this->context($request, $problem));
            $details = ProblemDetails::forStatus($details->status);
            $headers = array_filter(
                $headers,
                static fn(string $name): bool => in_array(strtolower($name), self::SERVER_PROBLEM_HEADERS, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        try {
            return Response::problem($details, $headers);
        } catch (Throwable $error) {
            return $this->internalError($request, $error);
        }
    }

    private function internalError(Request $request, Throwable $error): Response
    {
        $this->logger->error('unhandled exception', $this->context($request, $error));

        return Response::problem(ProblemDetails::forStatus(500));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(Request $request, Throwable $error): array
    {
        return [
            'requestId' => $request->attribute(Request::REQUEST_ID),
            'status' => $error instanceof HttpProblem ? $error->problem->status : 500,
            'exception' => $error::class,
            'exceptionMessage' => mb_strcut($error->getMessage(), 0, self::MAX_MESSAGE_BYTES, 'UTF-8'),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }
}
