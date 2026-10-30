<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\S3;

use App\Infrastructure\S3\S3ClientFactory;
use App\Infrastructure\S3\S3Settings;
use App\Infrastructure\S3\UploadPolicySigner;
use App\Receipts\ReceiptContentType;
use App\Receipts\ReceiptLimits;
use App\Receipts\ReceiptObjectKey;
use App\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class UploadPolicySignerTest extends TestCase
{
    private const USER = '0b3f1c1e-5d2a-4c8e-9a41-2f6d7b8c9d01';
    private const EXPENSE = '1c4f2d2f-6e3b-4d9f-8b52-3a7e8c9dae12';
    private const RECEIPT = '2d5a3e3a-7f4c-4eaa-9c63-4b8f9daebf23';
    private const BUCKET = 'expense-receipts';
    private const NOW = '2026-10-16T12:00:00+00:00';
    private const SIZE = 2048;

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

    private function signer(bool $local = true): UploadPolicySigner
    {
        $env = ['RECEIPTS_BUCKET' => self::BUCKET, 'THUMBNAILS_BUCKET' => 'expense-thumbnails', 'AWS_REGION' => 'eu-west-1'];
        if ($local) {
            $env += ['S3_ENDPOINT' => 'http://api:9100', 'S3_PUBLIC_ENDPOINT' => 'http://localhost:9000'];
        }

        return new UploadPolicySigner(S3ClientFactory::forSigning(S3Settings::fromEnvironment($env)), self::BUCKET, new FrozenClock(self::NOW));
    }

    private function key(): ReceiptObjectKey
    {
        return ReceiptObjectKey::from(self::USER, self::EXPENSE, self::RECEIPT);
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{expiration: string, conditions: list<mixed>}
     */
    private static function decodePolicy(array $fields): array
    {
        $decoded = json_decode((string) base64_decode($fields['Policy'], true), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['expiration'] ?? null);
        self::assertIsArray($decoded['conditions'] ?? null);

        return ['expiration' => $decoded['expiration'], 'conditions' => array_values($decoded['conditions'])];
    }

    public function testThePolicyHoldsExactlyTheFiveRulesOfC5(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Pdf, self::SIZE);

        $conditions = self::decodePolicy($upload->fields)['conditions'];

        self::assertSame(
            [
                ['bucket' => self::BUCKET],
                ['eq', '$key', 'receipts/' . self::USER . '/' . self::EXPENSE . '/' . self::RECEIPT],
                ['eq', '$Content-Type', 'application/pdf'],
                ['content-length-range', self::SIZE, self::SIZE],
            ],
            array_slice($conditions, 0, 4),
        );
    }

    public function testTheOnlyOtherConditionsAreTheThreeSignatureFieldsTheSdkAdds(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, self::SIZE);

        $conditions = self::decodePolicy($upload->fields)['conditions'];

        $names = array_map(static fn(mixed $condition): string => is_array($condition) ? (string) array_key_first($condition) : '?', array_slice($conditions, 4));
        self::assertSame(['X-Amz-Date', 'X-Amz-Credential', 'X-Amz-Algorithm'], $names);
        self::assertCount(7, $conditions);
    }

    public function testThePolicyExpiresPolicyTtlSecondsAfterTheInjectedClock(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Png, self::SIZE);

        $expiration = new \DateTimeImmutable(self::decodePolicy($upload->fields)['expiration']);

        self::assertSame((new \DateTimeImmutable(self::NOW))->getTimestamp() + 300, $expiration->getTimestamp());
        self::assertSame($expiration->getTimestamp(), $upload->expiresAt->getTimestamp());
    }

    public function testTheFormFieldsAreTheKeyTheTypeAndTheSignatureNothingElse(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Webp, self::SIZE);

        $names = array_keys($upload->fields);
        sort($names);
        self::assertSame(['Content-Type', 'Policy', 'X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date', 'X-Amz-Signature', 'key'], $names);
        self::assertSame('image/webp', $upload->fields['Content-Type']);
        self::assertSame($this->key()->originalKey(), $upload->fields['key']);
    }

    public function testThereIsNoAclAndNoRedirectAnywhere(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, self::SIZE);

        $everything = strtolower((string) base64_decode($upload->fields['Policy'], true) . implode(' ', array_keys($upload->fields)));
        self::assertStringNotContainsString('acl', $everything);
        self::assertStringNotContainsString('success_action', $everything);
        self::assertStringNotContainsString('redirect', $everything);
    }

    public function testLocallyTheFormPostsToTheBucketOnThePublicEndpointInPathStyle(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, self::SIZE);

        self::assertSame('http://localhost:9000/' . self::BUCKET, $upload->url);
    }

    public function testOnAwsTheFormPostsToTheBucketsOwnVirtualHostedOrigin(): void
    {
        $upload = $this->signer(local: false)->sign($this->key(), ReceiptContentType::Jpeg, self::SIZE);

        self::assertSame('https://' . self::BUCKET . '.s3.eu-west-1.amazonaws.com', $upload->url);
    }

    public function testEachTypeIsPinnedByItsOwnPolicy(): void
    {
        foreach (ReceiptContentType::cases() as $type) {
            $upload = $this->signer()->sign($this->key(), $type, self::SIZE);

            self::assertSame(['eq', '$Content-Type', $type->value], self::decodePolicy($upload->fields)['conditions'][2]);
            self::assertSame($type->value, $upload->fields['Content-Type']);
        }
    }

    public function testThePolicyPinsTheDeclaredSizeExactly(): void
    {
        $upload = $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, 777);

        self::assertSame(['content-length-range', 777, 777], self::decodePolicy($upload->fields)['conditions'][3]);
    }

    public function testTheBoundarySizesAreSigned(): void
    {
        foreach ([1, ReceiptLimits::MAX_BYTES] as $size) {
            $upload = $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, $size);

            self::assertSame(['content-length-range', $size, $size], self::decodePolicy($upload->fields)['conditions'][3]);
        }
    }

    public function testASizeOutsideOneToTheLimitIsRefusedBeforeAnythingIsSigned(): void
    {
        foreach ([0, -1, ReceiptLimits::MAX_BYTES + 1] as $size) {
            try {
                $this->signer()->sign($this->key(), ReceiptContentType::Jpeg, $size);
                self::fail('expected an InvalidArgumentException for size ' . $size);
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
