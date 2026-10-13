<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

/** A documented response of one operation, with `$ref`s already followed. */
final readonly class OpenApiResponse
{
    /**
     * @param string                          $pointer       JSON pointer of the response object inside the document
     * @param string                          $description
     * @param list<string>                    $requiredHeaders
     * @param array<string, string>           $schemaPointers media type (lower case) => pointer of its schema
     */
    public function __construct(
        public string $pointer,
        private string $description,
        private array $requiredHeaders,
        private array $schemaPointers,
    ) {}

    public function description(): string
    {
        return $this->description;
    }

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

    public function schemaPointer(string $mediaType): ?string
    {
        return $this->schemaPointers[$mediaType] ?? null;
    }
}
