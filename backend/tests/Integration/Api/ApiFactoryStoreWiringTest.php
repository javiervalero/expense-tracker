<?php

declare(strict_types=1);

namespace App\Tests\Integration\Api;

use App\Api\ApiFactory;
use App\Auth\AuthKeys;
use App\Auth\Email;
use App\Http\Request;
use App\Infrastructure\Config\ApiConfig;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Tests\Support\LocalAws;
use App\Tests\Support\TemporaryTable;
use PHPUnit\Framework\TestCase;

/**
 * Proves the wiring through a real route: `POST /auth/register` through the kernel that ApiFactory builds reaches the
 * ItemStore that ApiFactory builds from the configuration (table name, endpoint, region and the SDK's credentials),
 * against DynamoDB Local, into a temporary table. The user, its e-mail lock and its session are found there afterwards.
 */
final class ApiFactoryStoreWiringTest extends TestCase
{
    private const SECRET = 'wiring-slot-a-0123456789-0123456789';
    private const ORIGIN = 'http://localhost:5173';

    private TemporaryTable $table;

    protected function setUp(): void
    {
        $this->table = TemporaryTable::create(LocalAws::fromGlobals()->dynamoDb());
    }

    protected function tearDown(): void
    {
        $this->table->drop();
    }

    public function testRegisteringThroughTheFactoryWritesToTheConfiguredTable(): void
    {
        $endpoint = getenv('DYNAMODB_ENDPOINT');
        self::assertIsString($endpoint);
        $config = ApiConfig::fromEnvironment([
            'ORIGIN_VERIFY_SECRETS' => self::SECRET . ',wiring-slot-b-0123456789-0123456789',
            'CLIENT_IP_HEADER' => 'CloudFront-Viewer-Address',
            'APP_ORIGINS' => self::ORIGIN,
            'DYNAMODB_TABLE' => $this->table->name,
            'DYNAMODB_ENDPOINT' => $endpoint,
            'AWS_REGION' => 'eu-west-1',
        ]);
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $kernel = ApiFactory::fromConfigLoader(static fn(): ApiConfig => $config, $stream);
        $email = 'wiring-' . bin2hex(random_bytes(4)) . '@example.com';

        $response = $kernel->handle(new Request('POST', '/api/v1/auth/register', [], [
            'x-origin-verify' => self::SECRET,
            'cloudfront-viewer-address' => '198.51.100.20:5000',
            'origin' => self::ORIGIN,
            'x-requested-with' => 'XMLHttpRequest',
            'content-type' => 'application/json',
        ], json_encode(['email' => $email, 'password' => 'correct horse staple 42'], JSON_THROW_ON_ERROR)));

        self::assertSame(201, $response->status);
        $parsed = Email::parse($email);
        self::assertInstanceOf(Email::class, $parsed);
        $items = new ItemStore(LocalAws::fromGlobals()->dynamoDb(), $this->table->name);
        $lock = $items->getItem(AuthKeys::emailLock($parsed));
        self::assertNotNull($lock);
        self::assertIsString($lock['userId'] ?? null);
        $user = $items->getItem(AuthKeys::user($lock['userId']));
        self::assertSame($email, $user['email'] ?? null);
        $cookie = $response->headerValues('Set-Cookie')[0] ?? '';
        self::assertSame(1, preg_match('/\A__Host-session=([A-Za-z0-9_-]{43});/', $cookie, $match), 'a session cookie is set');
        self::assertNotNull($items->getItem(AuthKeys::session(hash('sha256', $match[1]))));
        self::assertStringNotContainsString($email, (string) stream_get_contents($stream, null, 0));
    }
}
