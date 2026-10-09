<?php

declare(strict_types=1);

namespace Spike;

use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use GuzzleHttp\Client as Http;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../bootstrap.php';

/** Shared helpers: SDK client, presigned POST building/sending, webhook capture access, result recording. */
abstract class S3TestCase extends TestCase
{
    protected const MIN_SIZE = 10;
    protected const MAX_SIZE = 1024;
    protected const CONTENT_TYPE = 'image/jpeg';
    protected const SETTLE_SECONDS = 2;

    protected S3Client $s3;
    protected Http $http;

    protected function setUp(): void
    {
        $this->s3 = \s3_client();
        $this->http = new Http(['http_errors' => false, 'timeout' => 20]);
    }

    protected function captureUrl(): string
    {
        return \env_or('CAPTURE_URL', 'http://capture:8080');
    }

    protected function endpoint(): string
    {
        return \env_or('S3_ENDPOINT', 'http://s3:9000');
    }

    protected function newKey(string $prefix = 'receipts'): string
    {
        $id = bin2hex(random_bytes(4));

        return "{$prefix}/user-{$id}/expense-{$id}/receipt-{$id}";
    }

    /** @param array<string, mixed> $overrides */
    protected function presignedPost(string $key, string $expiration = '+10 minutes', array $overrides = []): PostObjectV4
    {
        $inputs = ['key' => $key, 'Content-Type' => self::CONTENT_TYPE] + $overrides;
        $conditions = [
            ['bucket' => BUCKET_RECEIPTS],
            ['eq', '$key', $key],
            ['eq', '$Content-Type', self::CONTENT_TYPE],
            ['content-length-range', self::MIN_SIZE, self::MAX_SIZE],
        ];

        return new PostObjectV4($this->s3, BUCKET_RECEIPTS, $inputs, $conditions, $expiration);
    }

    /**
     * Sends the form as multipart (file part last, as S3 requires).
     *
     * @param array<string, string> $fieldOverrides applied after signing (tampering)
     * @param array<string, string> $extraFields    unsigned extra fields
     * @return array{status: int, code: ?string, message: ?string, body: string}
     */
    protected function sendPost(PostObjectV4 $form, string $body, array $fieldOverrides = [], array $extraFields = []): array
    {
        $fields = array_merge($form->getFormInputs(), $fieldOverrides, $extraFields);
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => (string) $name, 'contents' => (string) $value];
        }
        $multipart[] = ['name' => 'file', 'contents' => $body, 'filename' => 'receipt.jpg'];

        // the form action points at the endpoint the SDK client was built with
        $response = $this->http->post($form->getFormAttributes()['action'], ['multipart' => $multipart]);

        return $this->summarise($response->getStatusCode(), (string) $response->getBody());
    }

    /** @return array{status: int, code: ?string, message: ?string, body: string} */
    protected function summarise(int $status, string $body): array
    {
        preg_match('#<Code>(.*?)</Code>#s', $body, $code);
        preg_match('#<Message>(.*?)</Message>#s', $body, $message);

        return ['status' => $status, 'code' => $code[1] ?? null, 'message' => $message[1] ?? null, 'body' => $body];
    }

    protected function objectExists(string $bucket, string $key): bool
    {
        return $this->s3->doesObjectExistV2($bucket, $key);
    }

    protected function validBody(): string
    {
        return str_repeat('x', 100);
    }

    /** @param array<string, mixed> $data */
    protected function record(string $name, array $data): void
    {
        $dir = \env_or('RESULTS_DIR', '/app/results');
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $dir . '/results.jsonl',
            json_encode(['test' => static::class . '::' . $name] + $data, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /** @return list<array{headers: array<string, string>, raw_body: string, received_at: float, body: array<string, mixed>}> */
    protected function captures(): array
    {
        $response = $this->http->get($this->captureUrl() . '/captures');
        $this->assertSame(200, $response->getStatusCode(), 'capture endpoint must be reachable');
        $list = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return array_map(static function (array $c): array {
            $c['body'] = json_decode($c['raw_body'], true) ?? [];

            return $c;
        }, $list);
    }

    /** Captures whose first record concerns this (decoded) key, in the given bucket. */
    protected function capturesFor(string $key, string $bucket = BUCKET_RECEIPTS): array
    {
        return array_values(array_filter($this->captures(), static function (array $c) use ($key, $bucket): bool {
            $object = $c['body']['Records'][0]['s3']['object']['key'] ?? null;
            $name = $c['body']['Records'][0]['s3']['bucket']['name'] ?? null;

            return $object !== null && urldecode($object) === $key && $name === $bucket;
        }));
    }

    protected function waitForCaptures(string $key, int $atLeast, int $timeoutSeconds = 20): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $found = $this->capturesFor($key);
            if (count($found) >= $atLeast) {
                return $found;
            }
            usleep(250_000);
        } while (microtime(true) < $deadline);

        return $found;
    }

    /** Waits for a sentinel upload's webhook (proves earlier events had time to arrive), then settles. */
    protected function assertNoWebhookFor(string $key, string $bucket = BUCKET_RECEIPTS): void
    {
        $sentinel = $this->newKey();
        $this->s3->putObject(['Bucket' => BUCKET_RECEIPTS, 'Key' => $sentinel, 'Body' => $this->validBody()]);
        $this->assertCount(1, $this->waitForCaptures($sentinel, 1), 'sentinel webhook must arrive');
        sleep(self::SETTLE_SECONDS);
        $this->assertSame([], $this->capturesFor($key, $bucket), "no webhook expected for {$bucket}/{$key}");
    }

    protected function uploadValid(string $key): array
    {
        return $this->sendPost($this->presignedPost($key), $this->validBody());
    }
}
