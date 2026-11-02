<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptOwnerPartition\Leaky;

use App\Auth\AuthKeys;

final class Leaky
{
    public function run(string $userId): array
    {
        return [
            AuthKeys::receiptOwnerPartition($userId),
            \App\Auth\AuthKeys::RECEIPTOWNERPARTITION($userId),
            self::receiptOwnerPartition($userId),
            $this->receiptOwnerPartition($userId),
            $this->{'receiptOwnerPartition'}($userId),
            [$this, 'receiptOwnerPartition'],
            'AuthKeys::receiptOwnerPartition',
            $name = 'receiptOwnerPartition',
            $this?->receiptOwnerPartition($userId),
            $this->receiptOwnerPartition(...),
        ];
    }
}
