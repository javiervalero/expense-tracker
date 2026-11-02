<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptWorkerCall\Leaky;

final class Leaky
{
    public function run(object $receipts, object $key): array
    {
        return [
            $receipts->findByKey($key),
            $receipts?->MARKREADY($key),
            self::markFailed($key),
            Receipts::findByKey($key),
            $receipts->{'findByKey'}($key),
            [$receipts, 'markReady'],
            'Receipts::markFailed',
            $name = 'findByKey',
            $receipts->findByKey(...),
        ];
    }
}
