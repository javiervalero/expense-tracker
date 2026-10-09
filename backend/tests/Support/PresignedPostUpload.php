<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use GuzzleHttp\Client as HttpClient;
use PHPUnit\Framework\Assert;

/**
 * A browser-style upload for the pipeline tests: a presigned POST form (built like the front-end's will be) sent as
 * multipart, the file part last, to the S3 emulator.
 */
final class PresignedPostUpload
{
    public const CONTENT_TYPE = 'image/jpeg';

    /**
     * @return int the HTTP status of the POST (204 when the object was stored)
     */
    public static function send(S3Client $s3, HttpClient $http, string $bucket, string $key): int
    {
        $form = new PostObjectV4(
            $s3,
            $bucket,
            ['key' => $key, 'Content-Type' => self::CONTENT_TYPE],
            [
                ['bucket' => $bucket],
                ['eq', '$key', $key],
                ['eq', '$Content-Type', self::CONTENT_TYPE],
                ['content-length-range', 10, 1024],
            ],
            '+10 minutes',
        );
        $action = $form->getFormAttributes()['action'] ?? null;
        Assert::assertIsString($action);
        $multipart = [];
        foreach ($form->getFormInputs() as $name => $value) {
            $multipart[] = ['name' => (string) $name, 'contents' => $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => str_repeat('x', 100), 'filename' => 'receipt.jpg'];

        return $http->post($action, ['multipart' => $multipart])->getStatusCode();
    }
}
