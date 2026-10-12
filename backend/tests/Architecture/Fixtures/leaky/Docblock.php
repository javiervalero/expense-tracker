<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Leaky;

final class Docblock
{
    /**
     * @param \Bref\Context\Context $context
     */
    public function handle(object $context): void
    {
    }
}
