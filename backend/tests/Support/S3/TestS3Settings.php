<?php

declare(strict_types=1);

namespace App\Tests\Support\S3;

use App\Infrastructure\S3\S3Settings;

/** Settings with short bucket names (`rcp`, `thumbs`) for tests that use an in-memory store. */
final class TestS3Settings
{
    public const RECEIPTS = 'rcp';
    public const THUMBNAILS = 'thumbs';

    public static function create(): S3Settings
    {
        return S3Settings::fromEnvironment(['RECEIPTS_BUCKET' => self::RECEIPTS, 'THUMBNAILS_BUCKET' => self::THUMBNAILS, 'AWS_REGION' => 'eu-west-1']);
    }
}
