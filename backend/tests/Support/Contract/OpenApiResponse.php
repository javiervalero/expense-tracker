<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

/** A documented response of one operation, with `$ref`s already followed. */
final readonly class OpenApiResponse
{
    /**
     * @param list<string>           $requiredHeaders
     * @param array<string, ?string> $schemaPointers media type (lower case) => pointer of its schema, null when the
     *                                               media type documents no schema (any body is accepted)
     */
    public function __construct(
        private array $requiredHeaders,
        private array $schemaPointers,
    ) {}

    /** @return list<string> */
    public function requiredHeaders(): array
    {
        return $this->requiredHeaders;
    }

    /** @return list<string> */
    public function mediaTypes(): array
    {
        return array_keys($this->schemaPointers);
    }

    public function documents(string $mediaType): bool
    {
        return array_key_exists($mediaType, $this->schemaPointers);
    }

    /** Null when the media type is undocumented or documents no schema. */
    public function schemaPointer(string $mediaType): ?string
    {
        return $this->schemaPointers[$mediaType] ?? null;
    }
}
