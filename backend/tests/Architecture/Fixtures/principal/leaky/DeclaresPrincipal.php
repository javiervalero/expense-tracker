<?php

declare(strict_types=1);

namespace App\Tests\Architecture\Fixtures\Principal\Leaky;

final readonly class Principal
{
    public function __construct(public string $userId) {}

    public static function copy(string $userId): self
    {
        return [new self($userId), new static($userId), new parent($userId), new \stdClass()][0];
    }
}
