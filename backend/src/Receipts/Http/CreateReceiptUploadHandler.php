<?php

declare(strict_types=1);

namespace App\Receipts\Http;

use App\Auth\Session\Principal;
use App\Expenses\ExpenseNotFound;
use App\Http\Handler;
use App\Http\JsonBody;
use App\Http\Problem\HttpProblem;
use App\Http\Problem\ValidationProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\Clock\Clock;
use App\Infrastructure\DynamoDb\StorageFailure;
use App\Infrastructure\DynamoDb\TransactionCancelled;
use App\Infrastructure\Id\IdGenerator;
use App\Infrastructure\S3\UploadPolicySigner;
use App\Infrastructure\Time\StoredTime;
use App\Receipts\Receipt;
use App\Receipts\ReceiptChanged;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectCleaner;
use App\Receipts\ReceiptObjectKey;
use App\Receipts\ReceiptRepository;
use App\Receipts\ReceiptStatus;
use JsonException;
use LogicException;

/**
 * `POST /expenses/{id}/receipt/upload`, behind {@see \App\Auth\Session\RequireSession} (C4). In this order: the
 * principal, the route id (a malformed one is the generic `404`, no storage call), the body (415, 413, 400, 422: no
 * storage is touched), the repository (the expense must be the caller's: a missing, deleted or foreign one is the same
 * `404`, and nothing is signed for it), then, only after the commit, the signing (local, no network call) and the removal of
 * the replaced receipt's files, and the `201`. The removal runs even if signing throws: the record already points at the new
 * key, so the old files are unreferenced either way (the `500` then leaves a pending record the user can replace).
 *
 * Every call mints a fresh v4 receipt id: the key of the new upload is never the old one's, so a late file of the
 * replaced upload cannot land on the new record, and the repository refuses an id equal to the current one.
 *
 * A receipt that changed between the repository's read and write is `409` (reload and try again). A transaction
 * cancelled by contention (`TransactionConflict`) and DynamoDB overload are not caught here: like the other
 * endpoints they end at {@see \App\Http\Middleware\StorageUnavailableMiddleware} as `503` with `Retry-After`, and
 * any other cancellation is a bug and a generic `500`. In none of those cases is a file removed or a policy signed.
 *
 * The answer holds a signature. Nothing here logs the policy, the signature, the URL or the fields (C15); the
 * kernel adds `Cache-Control: no-store`.
 */
final readonly class CreateReceiptUploadHandler implements Handler
{
    private const string LOCATION_PREFIX = '/api/v1/expenses/';
    private const string LOCATION_SUFFIX = '/receipt';
    private const string CHANGED = 'The receipt changed since you loaded it. Reload and try again.';

    public function __construct(
        private ReceiptRepository $receipts,
        private ReceiptObjectCleaner $cleaner,
        private UploadPolicySigner $signer,
        private IdGenerator $ids,
        private Clock $clock,
    ) {}

    /**
     * @throws HttpProblem
     * @throws ValidationProblem
     * @throws StorageFailure
     * @throws TransactionCancelled
     * @throws JsonException
     * @throws LogicException when the guard did not run (a wiring bug, never a client error)
     */
    public function handle(Request $request): Response
    {
        $principal = Principal::of($request);
        $expenseId = RouteId::uuidOrNotFound($request);
        $input = ReceiptUploadInput::fromBody(JsonBody::decode($request->header('content-type'), $request->body));

        $now = $this->clock->now();
        $key = ReceiptObjectKey::from($principal->userId, $expenseId, $this->ids->generate());
        $pending = new Receipt(
            $key,
            ReceiptStatus::Pending,
            $input->contentType,
            $input->size,
            $now,
            $now->modify('+' . ReceiptLimits::PENDING_TTL_SECONDS . ' seconds'),
            null,
            null,
        );
        try {
            $replaced = $this->receipts->startUpload($principal, $expenseId, $pending);
        } catch (ExpenseNotFound) {
            throw RouteId::notFound();
        } catch (ReceiptChanged) {
            throw HttpProblem::of(409, self::CHANGED);
        }
        try {
            // Signing is local (no network call). The record already points at the new key, so the old files are
            // unreferenced whether or not it works: they are removed in `finally`, after the signing attempt.
            $upload = $this->signer->sign($key, $input->contentType, $input->size);
        } finally {
            if ($replaced !== null) {
                // A failure here is logged, not thrown.
                $this->cleaner->removeReceipt($replaced);
            }
        }

        return Response::json(201, [
            'receiptId' => $key->receiptId(),
            'url' => $upload->url,
            'fields' => $upload->fields,
            'expiresAt' => StoredTime::format($upload->expiresAt),
            'maxBytes' => ReceiptLimits::MAX_BYTES,
        ], ['Location' => [self::LOCATION_PREFIX . $expenseId . self::LOCATION_SUFFIX]]);
    }
}
