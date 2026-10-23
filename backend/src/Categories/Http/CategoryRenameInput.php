<?php

declare(strict_types=1);

namespace App\Categories\Http;

use App\Categories\CategoryName;

/** A valid rename body: the new name and the version the client last read. */
final readonly class CategoryRenameInput
{
    public function __construct(
        public CategoryName $name,
        public int $version,
    ) {}
}
