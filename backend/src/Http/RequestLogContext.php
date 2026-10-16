<?php

declare(strict_types=1);

namespace App\Http;

/**
 * What the request line should say about the request but only a middleware further down knows. The request is
 * immutable and the logging middleware sits outside the others, so it hands them this one small mutable holder (write-once per field) (the
 * request attribute {@see self::ATTRIBUTE}) and reads it back when the request ends. Per request, never shared.
 */
final class RequestLogContext
{
    public const ATTRIBUTE = 'request.logContext';

    private ?string $clientIp = null;
    private bool $clientIpRecorded = false;

    /** Records the canonical client address (null if unknown). Write-once: a second call is ignored. */
    public function recordClientIp(?string $ip): void
    {
        if ($this->clientIpRecorded) {
            return;
        }
        $this->clientIp = $ip;
        $this->clientIpRecorded = true;
    }

    /** The canonical client address; null while unknown (also for requests rejected before ClientIp runs). */
    public function clientIp(): ?string
    {
        return $this->clientIp;
    }
}
