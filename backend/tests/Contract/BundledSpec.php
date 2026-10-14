<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Tests\Support\Contract\OpenApiDocument;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;

/**
 * Loads the real bundle (contract/dist/openapi.json). A missing bundle skips locally and fails when `CI` is set, so
 * a CI run can never pass by silently skipping.
 */
final class BundledSpec
{
    public static function document(): OpenApiDocument
    {
        $file = __DIR__ . '/../../../contract/dist/openapi.json';
        if (!is_file($file)) {
            $message = 'contract/dist/openapi.json is missing; run `make contract-bundle`.';
            if ((string) getenv('CI') !== '') {
                Assert::fail($message);
            }
            throw new SkippedWithMessageException($message);
        }

        return OpenApiDocument::fromFile($file);
    }
}
