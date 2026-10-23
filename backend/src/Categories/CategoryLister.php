<?php

declare(strict_types=1);

namespace App\Categories;

use App\Auth\Session\Principal;
use App\Infrastructure\DynamoDb\StorageFailure;
use InvalidArgumentException;

/**
 * The one read the {@see CategoryResolver} needs: every category of the owner. {@see CategoryRepository} is the real
 * implementation; the interface is the seam for unit tests.
 */
interface CategoryLister
{
    /**
     * @return list<Category>
     *
     * @throws InvalidArgumentException when the principal's user id is not a lower-case UUID
     * @throws StorageFailure
     */
    public function listAll(Principal $owner): array;
}
