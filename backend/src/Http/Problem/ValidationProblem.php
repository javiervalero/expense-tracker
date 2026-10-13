<?php

declare(strict_types=1);

namespace App\Http\Problem;

use InvalidArgumentException;

/**
 * 422 with the invalid fields listed in the `errors` extension member: `[{pointer, detail}, ...]`.
 */
final class ValidationProblem extends HttpProblem
{
    private const STATUS = 422;
    private const DETAIL = 'One or more fields are invalid.';

    /**
     * @param list<FieldError> $errors
     * @param array<string, list<string>> $headers
     */
    public function __construct(array $errors, array $headers = [])
    {
        if ($errors === []) {
            throw new InvalidArgumentException('A validation problem lists at least one field error.');
        }

        parent::__construct(new ProblemDetails(
            status: self::STATUS,
            title: ProblemDetails::forStatus(self::STATUS)->title,
            detail: self::DETAIL,
            extensions: ['errors' => array_map(static fn(FieldError $error): array => $error->toArray(), $errors)],
        ), headers: $headers);
    }
}
