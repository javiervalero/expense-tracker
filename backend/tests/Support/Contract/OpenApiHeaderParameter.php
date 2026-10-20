<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

/** A header parameter of one operation, `$ref` followed: whether it is required and its `const`, if the schema has one. */
final readonly class OpenApiHeaderParameter
{
    public function __construct(
        public bool $required,
        public mixed $constant,
    ) {}
}
