<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\LocalAws;
use Aws\Credentials\CredentialsInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalAwsTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function validEnvironment(): array
    {
        return [
            'AWS_REGION' => 'eu-west-1',
            'AWS_ACCESS_KEY_ID' => 'localdev',
            'AWS_SECRET_ACCESS_KEY' => 'localdevsecret',
            'SQS_ENDPOINT' => 'http://sqs:9324',
            'DYNAMODB_ENDPOINT' => 'http://dynamodb:8000',
        ];
    }

    public function testBuildsClientsPointingAtTheExplicitEndpoints(): void
    {
        $localAws = LocalAws::fromEnvironment(self::validEnvironment());

        self::assertSame('http://sqs:9324', (string) $localAws->sqs()->getEndpoint());
        self::assertSame('http://dynamodb:8000', (string) $localAws->dynamoDb()->getEndpoint());
        self::assertSame('eu-west-1', $localAws->sqs()->getRegion());
    }

    public function testClientsUseTheLocalCredentialsFromTheEnvironment(): void
    {
        $localAws = LocalAws::fromEnvironment(self::validEnvironment());

        $credentials = $localAws->sqs()->getCredentials()->wait();
        self::assertInstanceOf(CredentialsInterface::class, $credentials);

        self::assertSame('localdev', $credentials->getAccessKeyId());
        self::assertSame('localdevsecret', $credentials->getSecretKey());
    }

    #[DataProvider('missingVariables')]
    public function testThrowsWhenAnEndpointOrSettingIsMissing(string $variable): void
    {
        $environment = self::validEnvironment();
        unset($environment[$variable]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($variable);

        LocalAws::fromEnvironment($environment);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingVariables(): iterable
    {
        foreach (['SQS_ENDPOINT', 'DYNAMODB_ENDPOINT', 'AWS_REGION', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $variable) {
            yield $variable => [$variable];
        }
    }

    public function testThrowsWhenAnEndpointIsEmpty(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SQS_ENDPOINT');

        LocalAws::fromEnvironment(['SQS_ENDPOINT' => ''] + self::validEnvironment());
    }

    #[DataProvider('realAwsEndpoints')]
    public function testRefusesEndpointsThatPointAtRealAws(string $variable, string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('real AWS');

        LocalAws::fromEnvironment([$variable => $endpoint] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function realAwsEndpoints(): iterable
    {
        yield 'sqs' => ['SQS_ENDPOINT', 'https://sqs.eu-west-1.amazonaws.com'];
        yield 'dynamodb' => ['DYNAMODB_ENDPOINT', 'https://dynamodb.eu-west-1.amazonaws.com'];
        yield 'uppercase host' => ['SQS_ENDPOINT', 'https://SQS.EU-WEST-1.AMAZONAWS.COM'];
        yield 'trailing dot' => ['SQS_ENDPOINT', 'https://sqs.eu-west-1.amazonaws.com.'];
        yield 'api.aws dual-stack' => ['DYNAMODB_ENDPOINT', 'https://dynamodb.us-east-1.api.aws'];
        yield 'china partition' => ['DYNAMODB_ENDPOINT', 'https://dynamodb.cn-north-1.amazonaws.com.cn'];
    }

    #[DataProvider('endpointsOutsideTheAllowlist')]
    public function testRefusesEndpointsThatAreNotAllowedLocalHosts(string $endpoint): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DYNAMODB_ENDPOINT');

        LocalAws::fromEnvironment(['DYNAMODB_ENDPOINT' => $endpoint] + self::validEnvironment());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endpointsOutsideTheAllowlist(): iterable
    {
        yield 'dotted non-AWS host' => ['http://example.com:8000'];
        yield 'userinfo hiding the real host' => ['http://localhost@dynamodb.eu-west-1.amazonaws.com'];
        yield 'userinfo with a local host' => ['http://user:pw@localhost:8000'];
        yield 'ftp scheme' => ['ftp://localhost:8000'];
        yield 'no scheme' => ['localhost:8000'];
        yield 'no host' => ['http://:8000'];
        yield 'single label with underscore' => ['http://my_host:8000'];
    }

    #[DataProvider('allowedLocalEndpoints')]
    public function testAcceptsLocalEndpoints(string $endpoint): void
    {
        $localAws = LocalAws::fromEnvironment(['DYNAMODB_ENDPOINT' => $endpoint] + self::validEnvironment());

        self::assertSame($endpoint, (string) $localAws->dynamoDb()->getEndpoint());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedLocalEndpoints(): iterable
    {
        yield 'compose service' => ['http://dynamodb:8000'];
        yield 'localhost' => ['http://localhost:8000'];
        yield 'loopback v4' => ['http://127.0.0.1:9324'];
        yield 'loopback v6' => ['http://[::1]:8000'];
        yield 'docker host alias' => ['http://host.docker.internal:8000'];
    }

    public function testRefusesAnEndpointThatIsNotAUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('DYNAMODB_ENDPOINT');

        LocalAws::fromEnvironment(['DYNAMODB_ENDPOINT' => 'not a url'] + self::validEnvironment());
    }
}
