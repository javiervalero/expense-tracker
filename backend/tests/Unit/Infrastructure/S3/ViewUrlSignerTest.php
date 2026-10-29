<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3Settings;
use App\Infrastructure\S3\ViewUrlSigner;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class ViewUrlSignerTest extends TestCase
{
    private const USER = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
    private const EXPENSE = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
    private const RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    private const NOW = '2026-10-16T12:00:00+00:00';

    /** @var array<string, string|false> */
    private array $savedEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'localdev', 'AWS_SECRET_ACCESS_KEY' => 'localdevsecret', 'AWS_EC2_METADATA_DISABLED' => 'true'] as $name => $value) {
            $this->savedEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private function signer(bool $local = true): ViewUrlSigner
    {
        $env = ['RECEIPTS_BUCKET' => 'expense-receipts', 'THUMBNAILS_BUCKET' => 'expense-thumbnails', 'AWS_REGION' => 'eu-west-1'];
        if ($local) {
            $env += ['S3_ENDPOINT' => 'http://api:9100', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000'];
        }
        $settings = S3Settings::fromEnvironment($env);

        return new ViewUrlSigner(S3ClientFactory::forSigning($settings), $settings->receiptsBucket, $settings->thumbnailsBucket, new FrozenClock(self::NOW));
    }

    private function key(): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);
    }

    /**
     * @return array<string, string>
     */
    private static function query(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $strings = [];
        foreach ($query as $name => $value) {
            if (is_string($value)) {
                $strings[(string) $name] = $value;
            }
        }

        return $strings;
    }

    public function testTheOriginalUrlForcesTheTypeAndNamesTheFile(): void
    {
        $signed = $this->signer()->original($this->key(), ReceiptContentType::Pdf);

        $query = self::query($signed->url);
        self::assertSame('application/pdf', $query['response-content-type'] ?? null);
        self::assertSame('inline; filename="receipt.pdf"', $query['response-content-disposition'] ?? null);
        self::assertSame('private, no-store', $query['response-cache-control'] ?? null);
    }

    public function testTheFilenameFollowsTheType(): void
    {
        $expected = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

        foreach (ReceiptContentType::cases() as $type) {
            $query = self::query($this->signer()->original($this->key(), $type)->url);

            self::assertSame('inline; filename="receipt.' . $expected[$type->value] . '"', $query['response-content-disposition'] ?? null);
        }
    }

    public function testTheUrlLivesFiveMinutes(): void
    {
        $signed = $this->signer()->original($this->key(), ReceiptContentType::Jpeg);

        self::assertSame((string) ReceiptLimits::VIEW_URL_TTL_SECONDS, self::query($signed->url)['X-Amz-Expires'] ?? null);
        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp() + 300, $signed->expiresAt->getTimestamp());
    }

    public function testTheOriginalUrlIsForTheReceiptsBucketAndTheExactKeyOnThePublicEndpoint(): void
    {
        $signed = $this->signer()->original($this->key(), ReceiptContentType::Jpeg);

        self::assertSame(
            'http://localhost:9000/expense-receipts/receipts/' . self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT,
            explode('?', $signed->url)[0],
        );
        self::assertArrayHasKey('X-Amz-Signature', self::query($signed->url));
    }

    public function testTheThumbnailUrlIsForTheThumbnailsBucketAsAJpeg(): void
    {
        $signed = $this->signer()->thumbnail($this->key());

        $query = self::query($signed->url);
        self::assertSame('http://localhost:9000/expense-thumbnails/' . self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT . '.jpg', explode('?', $signed->url)[0]);
        self::assertSame('image/jpeg', $query['response-content-type'] ?? null);
        self::assertSame('inline; filename="receipt.jpg"', $query['response-content-disposition'] ?? null);
        self::assertSame('300', $query['X-Amz-Expires'] ?? null);
    }

    public function testOnAwsTheUrlIsVirtualHosted(): void
    {
        $signed = $this->signer(local: false)->original($this->key(), ReceiptContentType::Png);

        self::assertStringStartsWith('https://expense-receipts.s3.eu-west-1.amazonaws.com/receipts/', $signed->url);
    }
}
