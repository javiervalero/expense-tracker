<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\ClientAddress;
use App\Http\ClientIp;
use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Request;
use App\Http\RequestLogContext;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Sets the request attribute {@see self::ATTRIBUTE} ({@see ClientAddress}) from the one header named by
 * `CLIENT_IP_HEADER`. A missing or invalid value is logged once as an error (reason and the value, redacted and then cut to 64 bytes;
 * the logger JSON-encodes it, so control characters cannot forge a line) and the request carries on with the
 * `unknown` address: it is not rejected (ADR 0012 section 4.3) and there is no other source.
 *
 * It runs after the origin check and the deferred problems, because only CloudFront-validated traffic can be
 * trusted to set the header.
 */
final readonly class ClientIpMiddleware implements Middleware
{
    public const ATTRIBUTE = 'clientAddress';
    private const MAX_LOGGED_VALUE_BYTES = 64;

    public function __construct(private JsonLogger $logger, private string $headerName) {}

    /**
     * The address this middleware set on the request. Without one (the middleware did not run, or the attribute is
     * not an address) it is the shared `unknown` address: such a request counts in the `unknown` bucket and is never
     * exempt from a throttle.
     */
    public static function addressOf(Request $request): ClientAddress
    {
        $address = $request->attribute(self::ATTRIBUTE);

        return $address instanceof ClientAddress ? $address : ClientAddress::unknown('missing');
    }

    public function process(Request $request, Handler $next): Response
    {
        $value = $request->header($this->headerName);
        $address = ClientIp::parse($value);
        if ($address->reason !== null) {
            $this->logger->error('client address unavailable', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'reason' => $address->reason,
            ] + ($value === null ? [] : ['value' => $this->logger->capValue($value, self::MAX_LOGGED_VALUE_BYTES)]));
        }
        $context = $request->attribute(RequestLogContext::ATTRIBUTE);
        if ($context instanceof RequestLogContext) {
            $context->recordClientIp($address->ip);
        }

        return $next->handle($request->withAttribute(self::ATTRIBUTE, $address));
    }
}
