<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

use App\Receipts\FailureReason;
use App\Receipts\ReceiptContentType;

/**
 * What {@see FileInspector} found: either an accepted file (its detected type, and for images its size in pixels), or
 * the reason it was refused. Build one with {@see self::accepted()} or {@see self::refused()}.
 */
final readonly class Inspection
{
    private function __construct(
        public ?ReceiptContentType $type,
        public ?int $width,
        public ?int $height,
        public ?FailureReason $failure,
    ) {}

    public static function accepted(ReceiptContentType $type, ?int $width = null, ?int $height = null): self
    {
        return new self($type, $width, $height, null);
    }

    public static function refused(FailureReason $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function isAccepted(): bool
    {
        return $this->failure === null;
    }
}
