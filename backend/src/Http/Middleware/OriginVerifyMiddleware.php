<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Handler;
use App\Http\Middleware;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\Logging\JsonLogger;

/**
 * Only CloudFront may call the API (ADR 0012 section 4): every request must carry `X-Origin-Verify` with one of the
 * configured secrets. No method and no path is exempt, `/health` and unknown paths included, so this runs before routing.
 *
 *  - The WHOLE header value is compared, never split on commas: API Gateway and nginx join a repeated header into
 *    `A, A`, and that must not match `A`.
 *  - Both sides are hashed with sha256 first, so the comparison time does not depend on the length of the secret,
 *    and every slot is compared with no early exit.
 *  - A missing, empty or wrong header gets the same generic 403, so the answer never says which. The log line says
 *    `missing` or `mismatch`, and never holds the header value.
 */
final readonly class OriginVerifyMiddleware implements Middleware
{
    private const HEADER = 'x-origin-verify';
    private const HASH = 'sha256';
    private const MAX_PATH_LENGTH = 256;
    private const KNOWN_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const UNKNOWN_METHOD = 'OTHER';

    /** @var list<string> sha256 digests of the accepted secrets */
    private array $digests;

    /**
     * @param list<string> $secrets the accepted values (1 or 2 slots, validated by ApiConfig)
     */
    public function __construct(private JsonLogger $logger, array $secrets)
    {
        $this->digests = array_map(static fn(string $secret): string => hash(self::HASH, $secret), $secrets);
    }

    public function process(Request $request, Handler $next): Response
    {
        $given = $request->header(self::HEADER) ?? '';
        if (!$this->matchesAnySlot($given)) {
            $this->logger->warning('origin verification failed', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'method' => in_array($request->method, self::KNOWN_METHODS, true) ? $request->method : self::UNKNOWN_METHOD,
                'path' => mb_strcut($request->path, 0, self::MAX_PATH_LENGTH, 'UTF-8'),
                'reason' => $given === '' ? 'missing' : 'mismatch',
            ]);

            throw HttpProblem::of(403);
        }

        return $next->handle($request);
    }

    private function matchesAnySlot(string $given): bool
    {
        $givenDigest = hash(self::HASH, $given);
        $matched = false;
        foreach ($this->digests as $digest) {
            // No early exit: the time does not tell which slot matched.
            $matched = hash_equals($digest, $givenDigest) || $matched;
        }

        return $matched;
    }
}
