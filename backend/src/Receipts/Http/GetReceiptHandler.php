<?php

declare(strict_types=1);

namespace App\Receipts\Http;

use App\Auth\Session\Principal;
use App\Http\Handler;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\S3\ViewUrlSigner;
use App\Receipts\ReceiptRepository;
use JsonException;
use LogicException;

/**
 * `GET /expenses/{id}/receipt`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route
 * id (a malformed one is the generic `404`, no storage call), the read (the key holds the caller's partition, so another
 * user's receipt, an expense without one, and a pending or failed one that expired (C3) are all the same `404`), then
 * the answer, with signed addresses only for a ready receipt ({@see ReceiptResponse}). Signing is local, no network
 * call. Storage failures are not caught; the StorageUnavailable middleware answers them.
 *
 * The answer holds signatures. Nothing here logs the addresses (C15); the kernel adds `Cache-Control: no-store`.
 */
final readonly class GetReceiptHandler implements Handler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private ViewUrlSigner $urls,
        private Clock $clock,
    ) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $expenseId = RouteId::uuidOrNotFound($request);

        $receipt = $this->receipts->find($principal, $expenseId, $this->clock->now()) ?? throw RouteId::notFound();

        return ReceiptResponse::ok($receipt, $this->urls);
    }
}
