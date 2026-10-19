<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\ApiFactory;
use App\Api\ApiServices;
use App\Http\Handler;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\Route;
use App\Infrastructure\Config\ApiConfig;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * Proves the wiring: a request through the kernel built by ApiFactory reaches the ItemStore that ApiFactory builds from
 * the configuration (table name, endpoint, region and the SDK's credentials), against DynamoDB Local. The only route
 * is a test one, handed to the factory's route seam; the API has no public endpoint for it.
 */
final class ApiFactoryStoreWiringTest extends TestCase
{
    private const SECRET = 'wiring-slot-a-0123456789-0123456789';

    private TemporaryTable $table;

    protected function setUp(): void
    {
        $this->table = TemporaryTable::create(LocalAws::fromGlobals()->dynamoDb());
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    public function testARouteReachesTheItemStoreBuiltByTheFactory(): void
    {
        $endpoint = getenv('DYNAMODB_ENDPOINT');
        self::assertIsString($endpoint);
        $config = ApiConfig::fromEnvironment([
            'ORIGIN_VERIFY_SECRETS' => self::SECRET . ',wiring-slot-b-0123456789-0123456789',
            'CLIENT_IP_HEADER' => 'CloudFront-Viewer-Address',
            'APP_ORIGINS' => 'http://localhost:5173',
            'DYNAMODB_TABLE' => $this->table->name,
            'DYNAMODB_ENDPOINT' => $endpoint,
            'AWS_REGION' => 'eu-west-1',
        ]);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $kernel = ApiFactory::fromConfigLoader(
            static fn(): ApiConfig => $config,
            $stream,
            static fn(ApiServices $services): array => [new Route('GET', '/store-wiring', new class ($services) implements Handler {
                public function __construct(private readonly ApiServices $services) {}

                public function handle(Request $request): Response
                {
                    $key = ['PK' => 'WIRING#1', 'SK' => 'WIRING'];
                    $this->services->items->putItem($key + ['seenAt' => $this->services->clock->now()->format('c')]);

                    return Response::json(200, ['stored' => $this->services->items->getItem($key) !== null]);
                }
            })],
        );

        $response = $kernel->handle(new Request('GET', '/api/v1/store-wiring', [], ['x-origin-verify' => self::SECRET, 'cloudfront-viewer-address' => '198.51.100.20:5000'], ''));

        self::assertSame(200, $response->status);
        self::assertSame('{"stored":true}', $response->body);
    }
}
