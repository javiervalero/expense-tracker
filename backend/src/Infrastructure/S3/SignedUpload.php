<?php

declare(strict_types=1);

namespace App\Infrastructure\S3;

/**
 * A browser upload: POST the `fields` (then the file part, last) as multipart to `url`, before `expiresAt`.
 */
final readonly class SignedUpload
{
    /**
     * @param array<string, string> $fields
     */
    public function __construct(
        public string $url,
        public array $fields,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
