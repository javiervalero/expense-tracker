<?php

declare(strict_types=1);

namespace App\Receipts;

use App\Infrastructure\Id\Uuid;
use InvalidArgumentException;

/**
 * The name of a receipt in the buckets (C1): `receipts/<userId>/<expenseId>/<receiptId>` for the original upload and
 * `<userId>/<expenseId>/<receiptId>.jpg` for its thumbnail. Built only from three validated ids, never from a client
 * filename. {@see self::parse()} is the one way a key that arrives from outside (an S3 event) becomes a value, and it
 * accepts nothing but this exact shape.
 */
final readonly class ReceiptObjectKey
{
    private const string PREFIX = 'receipts/';

    private function __construct(
        private string $userId,
        private string $expenseId,
        private string $receiptId,
    ) {}

    /**
     * @throws InvalidArgumentException when an id is not a lower-case canonical UUID
     */
    public static function from(string $userId, string $expenseId, string $receiptId): self
    {
        return new self(
            Uuid::require($userId, 'user id'),
            Uuid::require($expenseId, 'expense id'),
            Uuid::require($receiptId, 'receipt id'),
        );
    }

    /**
     * An already-decoded key (`S3ObjectRef::$key`, which S3EventParser decodes once, or a stored `objectKey`), or null
     * when it is not exactly `receipts/<uuid>/<uuid>/<uuid>`. It never decodes: a key that still holds a `%`, upper
     * case, a fourth segment, `..`, a trailing slash or NUL is refused. A key that parses satisfies
     * `parse($k)->originalKey() === $k`.
     */
    public static function parse(string $key): ?self
    {
        $segment = '([^/]*)';
        if (preg_match('#\A' . self::PREFIX . $segment . '/' . $segment . '/' . $segment . '\z#', $key, $parts) !== 1) {
            return null;
        }
        [, $userId, $expenseId, $receiptId] = $parts;
        // The regex only splits the segments; Uuid::isValid alone decides what an id is.
        if (!Uuid::isValid($userId) || !Uuid::isValid($expenseId) || !Uuid::isValid($receiptId)) {
            return null;
        }

        return new self($userId, $expenseId, $receiptId);
    }

    public function originalKey(): string
    {
        return self::PREFIX . $this->userId . '/' . $this->expenseId . '/' . $this->receiptId;
    }

    public function thumbnailKey(): string
    {
        return $this->userId . '/' . $this->expenseId . '/' . $this->receiptId . '.jpg';
    }

    public function userId(): string
    {
        return $this->userId;
    }

    public function expenseId(): string
    {
        return $this->expenseId;
    }

    public function receiptId(): string
    {
        return $this->receiptId;
    }
}
