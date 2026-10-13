<?php

declare(strict_types=1);

namespace App\Http\Problem;

use InvalidArgumentException;

/**
 * One invalid field of a request body: a JSON Pointer (RFC 6901) to it and a message for the person
 * who sent it. The empty pointer means the whole body.
 */
final readonly class FieldError
{
    public function __construct(
        public string $pointer,
        public string $detail,
    ) {
        if ($pointer !== '' && $pointer[0] !== '/') {
            throw new InvalidArgumentException('A JSON Pointer is empty or starts with "/".');
        }
    }

    /**
     * @return array{pointer: string, detail: string}
     */
    public function toArray(): array
    {
        return ['pointer' => $this->pointer, 'detail' => $this->detail];
    }
}
