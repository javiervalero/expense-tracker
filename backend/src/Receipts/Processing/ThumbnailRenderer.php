<?php

declare(strict_types=1);

namespace App\Receipts\Processing;

/**
 * Makes the JPEG thumbnail of an image that {@see FileInspector} already accepted. A failure is a RuntimeException, or
 * the ErrorException of the processor's error handler: the processor then fails the receipt as unreadable instead of
 * retrying.
 */
interface ThumbnailRenderer
{
    /**
     * @throws \RuntimeException
     * @throws \ErrorException
     */
    public function make(string $bytes): string;
}
