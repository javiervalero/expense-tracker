<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\PartitionCall\Leaky;

use App\Auth\AuthKeys;

final class Leaky
{
    public function run(string $userId): array
    {
        return [
            AuthKeys::userPartition($userId),
            \App\Auth\AuthKeys::USERPARTITION($userId),
            self::userPartition($userId),
            $this->userPartition($userId),
        ];
    }
}
