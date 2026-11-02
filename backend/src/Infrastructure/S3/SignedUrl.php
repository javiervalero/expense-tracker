<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

/**
 * A presigned GET URL and the instant it stops working. It is a credential: never log it.
 */
final readonly class SignedUrl
{
    public function __construct(
        public string $url,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
