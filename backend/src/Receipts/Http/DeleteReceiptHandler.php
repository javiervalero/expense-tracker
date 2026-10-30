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
use App\Infrastructure\Logging\JsonLogger;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptRepository;
use LogicException;

/**
 * `DELETE /expenses/{id}/receipt`, behind {@see \App\Auth\Session\RequireSession}. In this order: the principal, the route
 * id (a malformed one is the generic `404`, no storage call), the record's deletion (C7: unconditional, it returns the old
 * record), then, only after the record is gone, the files.
 *
 * - No record: `404`, nothing else to do.
 * - A record that had expired (C3): `404` like GET, but its files are still removed.
 * - A live record: its two files are removed and the answer is `204`.
 * - The cleaner never throws (C9): an S3 failure is logged as `receipt cleanup failed` and the answer is still `204`.
 * - `CORRUPT_ITEM`: the delete succeeded but the old record cannot be read, so its object key is unknown. Only THIS
 *   expense's two folders are cleaned, built from the session's user id (never the user's whole prefix), the case is
 *   logged as a warning, and the answer is `204`: a record existed and is gone, which is what the caller asked for. The
 *   sweep could also remove a file that the same user uploads to this expense at that moment. That is accepted: it is
 *   the user's own data, and it needs a corrupt record first.
 * - Any other StorageFailure: the outcome is unknown and the record may still exist. Nothing is deleted, the failure
 *   propagates to the middleware (5xx), and a retry is safe.
 */
final readonly class DeleteReceiptHandler implements Handler
{
    public function __construct(
        private ReceiptRepository $receipts,
        private ReceiptObjectCleaner $cleaner,
        private JsonLogger $logger,
        private Clock $clock,
    ) {}

    /**
     * @throws HttpProblem
     * @throws StorageFailure
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $expenseId = RouteId::uuidOrNotFound($request);

        try {
            $removed = $this->receipts->remove($principal, $expenseId);
        } catch (StorageFailure $failure) {
            if ($failure->awsErrorCode !== StorageFailure::CORRUPT_ITEM) {
                throw $failure;
            }
            $this->logger->warning('receipt record was unreadable and has been deleted', [
                'requestId' => $request->attribute(Request::REQUEST_ID),
                'operation' => $failure->operation,
                'errorCode' => $failure->awsErrorCode,
            ]);
            $this->cleaner->removeExpense($principal->userId, $expenseId);

            return Response::empty(204);
        }
        if ($removed === null) {
            throw RouteId::notFound();
        }
        $this->cleaner->removeReceipt($removed->objectKey);

        return $removed->isLive($this->clock->now()) ? Response::empty(204) : throw RouteId::notFound();
    }
}
