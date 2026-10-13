<?php

declare(strict_types=1);

namespace App\Tests\Support\Contract;

/** One way a real HTTP response differs from the OpenAPI contract. */
final readonly class ContractViolation
{
    public const string OPERATION = 'operation';
    public const string STATUS = 'status';
    public const string CONTENT_TYPE = 'content-type';
    public const string HEADER = 'header';
    public const string BODY = 'body';

    /**
     * @param string $location one of the constants above
     */
    public function __construct(public string $location, public string $message) {}
}
