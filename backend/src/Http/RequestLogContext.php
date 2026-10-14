<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What the request line should say about the request but only a middleware further down knows. The request is
 * immutable and the logging middleware sits outside the others, so it hands them this one mutable holder (the
 * request attribute {@see self::ATTRIBUTE}) and reads it back when the request ends. Per request, never shared.
 */
final class RequestLogContext
{
    public const ATTRIBUTE = 'request.logContext';

    /** The canonical client address; null while unknown (also for requests rejected before ClientIp runs). */
    public ?string $clientIp = null;
}
