<?php

declare(strict_types=1);

namespace App\Tests\Conformance\S3;

/**
 * The notification rule set by `local-aws-init` (not by the test): `receipts` sends ObjectCreated events for keys
 * under `receipts/` to the webhook target ARN of .env, and `receipt-thumbnails` has no rule at all (a rule there
 * would feed the worker its own output).
 */
final class BucketNotificationTest extends S3ConformanceCase
{
    public function testReceiptsBucketNotifiesTheConfiguredTargetOfObjectsCreatedUnderReceipts(): void
    {
        $arn = getenv('S3_NOTIFICATION_ARN');
        self::assertIsString($arn);
        self::assertNotSame('', $arn, 'S3_NOTIFICATION_ARN must be set (see .env.example)');

        $configuration = $this->s3->getBucketNotificationConfiguration(['Bucket' => self::RECEIPTS_BUCKET]);

        $rules = $configuration->get('QueueConfigurations');
        self::assertIsArray($rules);
        self::assertCount(1, $rules, 'exactly one rule');
        self::assertSame(
            ['QueueArn' => $arn, 'Events' => ['s3:ObjectCreated:*'], 'FilterRules' => [['prefix', 'receipts/']]],
            self::describe($rules[0]),
        );
        self::assertEmpty($configuration->get('TopicConfigurations') ?? []);
        self::assertEmpty($configuration->get('LambdaFunctionConfigurations') ?? []);
    }

    public function testThumbnailsBucketHasNoNotificationRule(): void
    {
        $configuration = $this->s3->getBucketNotificationConfiguration(['Bucket' => self::THUMBNAILS_BUCKET]);

        self::assertEmpty($configuration->get('QueueConfigurations') ?? []);
        self::assertEmpty($configuration->get('TopicConfigurations') ?? []);
        self::assertEmpty($configuration->get('LambdaFunctionConfigurations') ?? []);
    }

    /**
     * The parts of a queue rule that matter, in a fixed shape (filter rule names compared case-insensitively).
     *
     * @return array{QueueArn: mixed, Events: mixed, FilterRules: list<array{string, mixed}>}
     */
    private static function describe(mixed $rule): array
    {
        self::assertIsArray($rule);
        $filter = $rule['Filter'] ?? [];
        self::assertIsArray($filter);
        $key = $filter['Key'] ?? [];
        self::assertIsArray($key);
        $filterRules = $key['FilterRules'] ?? [];
        self::assertIsArray($filterRules);

        $described = [];
        foreach ($filterRules as $filterRule) {
            self::assertIsArray($filterRule);
            self::assertIsString($filterRule['Name'] ?? null);
            $described[] = [strtolower($filterRule['Name']), $filterRule['Value'] ?? null];
        }

        return ['QueueArn' => $rule['QueueArn'] ?? null, 'Events' => $rule['Events'] ?? null, 'FilterRules' => $described];
    }
}
