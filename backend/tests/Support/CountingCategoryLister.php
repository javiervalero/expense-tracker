<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryLister;
use App\Infrastructure\DynamoDb\StorageFailure;

/** A lister that counts calls and can fail a number of times first. */
final class CountingCategoryLister implements CategoryLister
{
    public int $calls = 0;
    public int $failures = 0;

    /** @var list<string> */
    public array $owners = [];

    /** @param list<Category> $stored */
    public function __construct(private readonly array $stored) {}

    public function listAll(Principal $owner): array
    {
        ++$this->calls;
        $this->owners[] = $owner->userId;
        if ($this->failures > 0) {
            --$this->failures;

            throw new StorageFailure('Query', 'ProvisionedThroughputExceededException');
        }

        return $this->stored;
    }
}
