<?php

declare(strict_types=1);

namespace LocalAws\Tests\Unit\Runner;

use LocalAws\Runner\RunnerSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RunnerSettingsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function environment(): array
    {
        return [
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'SQS_ENDPOINT' => 'http://sqs:9324',
            'SQS_RECEIPTS_QUEUE_URL' => 'http://sqs:9324/000000000000/receipts',
            'WORKER_INVOCATION_URL' => 'http://worker:8080/2015-03-31/functions/function/invocations',
        ];
    }

    public function testReadsEveryValueAndBuildsTheLocalPlaceholderArn(): void
    {
        $settings = RunnerSettings::fromEnvironment(self::environment());

        self::assertSame('eu-west-1', $settings->region);
        self::assertSame('localdev', $settings->accessKeyId);
        self::assertSame('localdevsecret', $settings->secretAccessKey);
        self::assertSame('http://sqs:9324', $settings->sqsEndpoint);
        self::assertSame('http://sqs:9324/000000000000/receipts', $settings->queueUrl);
        self::assertSame('http://worker:8080/2015-03-31/functions/function/invocations', $settings->invocationUrl);
        self::assertSame('arn:aws:sqs:eu-west-1:000000000000:receipts', $settings->eventSourceArn);
    }

    public function testTheQueueNameComesFromTheLastPathSegmentEvenWithATrailingSlash(): void
    {
        $settings = RunnerSettings::fromEnvironment(['SQS_RECEIPTS_QUEUE_URL' => 'http://localhost:9324/000000000000/receipts/'] + self::environment());

        self::assertSame('arn:aws:sqs:eu-west-1:000000000000:receipts', $settings->eventSourceArn);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requiredVariables(): iterable
    {
        foreach (['AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'WORKER_INVOCATION_URL'] as $variable) {
            yield $variable => [$variable];
        }
    }

    #[DataProvider('requiredVariables')]
    public function testRefusesAMissingOrEmptyRequiredVariableNamingIt(string $variable): void
    {
        foreach ([null, ''] as $value) {
            $environment = self::environment();
            unset($environment[$variable]);
            if ($value !== null) {
                $environment[$variable] = $value;
            }

            try {
                RunnerSettings::fromEnvironment($environment);
                self::fail('expected an exception for ' . $variable);
            } catch (\RuntimeException $e) {
                self::assertStringContainsString($variable, $e->getMessage());
            }
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedUrls(): iterable
    {
        foreach (['SQS_ENDPOINT', 'SQS_RECEIPTS_QUEUE_URL', 'WORKER_INVOCATION_URL'] as $variable) {
            yield "$variable real AWS" => [$variable, 'https://sqs.eu-west-1.amazonaws.com/123456789012/receipts'];
            yield "$variable public host" => [$variable, 'http://example.com:8080/000000000000/receipts'];
            yield "$variable hidden by credentials" => [$variable, 'http://lambda.amazonaws.com@worker:8080/x'];
            yield "$variable not http" => [$variable, 'ftp://worker:8080/x'];
        }
    }

    #[DataProvider('refusedUrls')]
    public function testRefusesAnyUrlThatIsNotALocalStandIn(string $variable, string $value): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($variable);

        RunnerSettings::fromEnvironment([$variable => $value] + self::environment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badQueueUrls(): iterable
    {
        yield 'no path' => ['http://sqs:9324'];
        yield 'only a slash' => ['http://sqs:9324/'];
        yield 'name with a colon (would corrupt the ARN)' => ['http://sqs:9324/000000000000/re:ceipts'];
        yield 'name with a space' => ['http://sqs:9324/000000000000/my%20queue'];
        yield 'name too long' => ['http://sqs:9324/000000000000/' . 'q' . '-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'];
    }

    #[DataProvider('badQueueUrls')]
    public function testRefusesAQueueUrlWithoutAUsableQueueName(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQS_RECEIPTS_QUEUE_URL');

        RunnerSettings::fromEnvironment(['SQS_RECEIPTS_QUEUE_URL' => $url] + self::environment());
    }
}
