<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\ReceiptStorageWrite\Leaky;

use Aws\S3\S3Client;

final class Leaky
{
    public function run(S3Client $client, object $store): void
    {
        $client->putObject(['Bucket' => 'receipts']);
        $store->put('receipts', 'k', 'x', 'text/plain');
        $client->upload('receipts', 'k', 'x');
        $client->{'copyObject'}([]);
        $client->getCommand('PutObject', []);
        $callable = [$store, 'put'];
        $class = 'Aws\\S3\\S3Client';
        $other = 'ObjectStore';
        $url = 's3://receipts/key';
    }
}
