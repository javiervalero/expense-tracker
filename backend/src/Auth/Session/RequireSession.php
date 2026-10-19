<?php

declare(strict_types=1);

namespace App\Auth\Session;

use App\Http\Handler;
use App\Http\Problem\ProblemDetails;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;

/**
 * The route guard (A7): wraps one protected handler. A request without a valid session gets `401` with
 * `WWW-Authenticate: Session` and the inner handler is not called; if it carried a session cookie, that cookie is
 * cleared. The problem never says why the session failed. A valid one reaches the handler with the
 * {@see Principal} as the `auth.principal` attribute, and its user id goes to the request log line.
 */
final readonly class RequireSession implements Handler
{
    private const CHALLENGE = 'Session';

    public function __construct(private Handler $inner, private SessionAuthenticator $authenticator) {}

    public function handle(Request $request): Response
    {
        $authentication = $this->authenticator->authenticate($request);
        $principal = $authentication->principal;
        if ($principal === null) {
            return $this->unauthorized($authentication);
        }
        $context = $request->attribute(RequestLogContext::ATTRIBUTE);
        if ($context instanceof RequestLogContext) {
            $context->recordUserId($principal->userId);
        }

        return $this->inner->handle($request->withAttribute(Principal::ATTRIBUTE, $principal));
    }

    private function unauthorized(Authentication $authentication): Response
    {
        $headers = ['WWW-Authenticate' => [self::CHALLENGE]];
        if ($authentication->cookieWasSent()) {
            $headers['Set-Cookie'] = [SessionCookie::clear()];
        }

        return Response::problem(ProblemDetails::forStatus(401), $headers);
    }
}
