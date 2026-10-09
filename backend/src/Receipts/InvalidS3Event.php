<?php

declare(strict_types=1);

namespace App\Receipts;

/**
 * A queue message body that is not an S3 event notification (or lacks a field the worker needs).
 * Messages never echo the body: it comes from outside.
 */
final class InvalidS3Event extends \DomainException {}
