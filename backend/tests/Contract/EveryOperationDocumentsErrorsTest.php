<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;

/**
 * Every operation documents the problems the middleware can answer for it. The validator fails a real response whose
 * status the contract does not list, so this keeps that list complete:
 *
 *  - 403 (origin and cross-site checks) and 500 on every operation;
 *  - 503 (storage overload, A18) on every operation except `/health`, which touches no storage;
 *  - 400, 413, 415 and 422 on every operation that reads a request body (JsonBody and field validation);
 *  - and every state-changing operation declares the `X-Requested-With: XMLHttpRequest` header the CSRF check wants.
 */
final class EveryOperationDocumentsErrorsTest extends TestCase
{
    private const array EVERY_OPERATION = [403, 500];
    private const array BODY_OPERATION = [400, 413, 415, 422];
    private const string HEALTH = '/health';
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function testEveryOperationDocumentsTheErrorStatuses(): void
    {
        $missing = $this->missingStatuses(static fn(string $method, string $template, bool $hasBody): array => self::EVERY_OPERATION);

        self::assertSame([], $missing, 'Undocumented responses: ' . implode(', ', $missing));
    }

    public function testEveryOperationExceptHealthDocumentsServiceUnavailable(): void
    {
        $missing = $this->missingStatuses(static fn(string $method, string $template, bool $hasBody): array => $template === self::HEALTH ? [] : [503]);

        self::assertSame([], $missing, 'Undocumented responses: ' . implode(', ', $missing));
    }

    public function testEveryOperationWithARequestBodyDocumentsTheBodyErrors(): void
    {
        $missing = $this->missingStatuses(static fn(string $method, string $template, bool $hasBody): array => $hasBody ? self::BODY_OPERATION : []);

        self::assertSame([], $missing, 'Undocumented responses: ' . implode(', ', $missing));
    }

    public function testEveryStateChangingOperationDeclaresTheRequestedWithHeader(): void
    {
        $document = BundledSpec::document();
        $wrong = [];
        foreach ($document->operations() as [$method, $template]) {
            if (in_array($method, self::SAFE_METHODS, true)) {
                continue;
            }
            $header = $document->headerParameters($method, $template)['x-requested-with'] ?? null;
            if ($header === null || !$header->required || $header->constant !== 'XMLHttpRequest') {
                $wrong[] = "$method $template";
            }
        }

        self::assertSame([], $wrong, 'No required X-Requested-With (const XMLHttpRequest): ' . implode(', ', $wrong));
    }

    /**
     * @param callable(string, string, bool): list<int> $required statuses an operation must document
     *
     * @return list<string>
     */
    private function missingStatuses(callable $required): array
    {
        $document = BundledSpec::document();
        $operations = $document->operations();
        self::assertNotSame([], $operations, 'The contract has no operations.');
        $missing = [];
        foreach ($operations as [$method, $template]) {
            foreach ($required($method, $template, $document->hasRequestBody($method, $template)) as $status) {
                if ($document->response($method, $template, $status) === null) {
                    $missing[] = "$method $template $status";
                }
            }
        }

        return $missing;
    }
}
