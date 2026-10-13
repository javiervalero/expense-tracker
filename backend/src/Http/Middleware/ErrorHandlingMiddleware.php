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
 *  - an {@see HttpProblem} is rendered as it describes itself (its message is written to be shown);
 *  - any other Throwable becomes a generic 500 with no message, previous exception or trace. What happened goes
 *    to the log only: request id, class, message, file and line. Never `getPrevious()`, the trace or its
 *    arguments, which can hold secrets or user data.
 */
final readonly class ErrorHandlingMiddleware implements Middleware
{
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
        if ($problem->problem->status >= 500) {
            $this->logger->error('request failed with a server problem', $this->context($request, $problem));
        }

        try {
            return Response::problem($problem->problem, $problem->headers);
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
            'exceptionMessage' => $error->getMessage(),
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }
}
