<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\AwsClient\Leaky;

use Aws\S3\S3Client;

final class LeakyS3
{
    public function run(): array
    {
        return [
            new S3Client(['region' => 'eu-west-1']),
            new \Aws\S3\S3Client(['region' => 'eu-west-1']),
        ];
    }
}
