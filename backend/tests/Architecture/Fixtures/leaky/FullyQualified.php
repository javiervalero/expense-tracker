<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Leaky;

final class FullyQualified
{
    public function handle(): object
    {
        return new \Bref\Context\Context('id', 0, 'arn', 'trace');
    }
}
