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
    /** The one place that knows where the bundle is (also used by ApiTestCase, which never skips). */
    public const string FILE = __DIR__ . '/../../../contract/dist/openapi.json';

    public static function document(): OpenApiDocument
    {
        if (!is_file(self::FILE)) {
            $message = 'contract/dist/openapi.json is missing; run `make contract-bundle`.';
            if ((string) getenv('CI') !== '') {
                Assert::fail($message);
            }
            throw new SkippedWithMessageException($message);
        }

        return OpenApiDocument::fromFile(self::FILE);
    }
}
