<?php

declare(strict_types=1);

namespace App\Tests\Unit\Receipts\Http;

use App\Auth\Session\Principal;
use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Pipeline;
use App\Http\Problem\HttpProblem;
use App\Http\Request;
use App\Http\Response;
use App\Http\Routing\RouteId;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Logging\JsonLogger;
use App\Infrastructure\S3\ViewUrlSigner;
use App\Receipts\Http\GetReceiptHandler;
use App\Receipts\ReceiptRepository;
use App\Tests\Support\FrozenClock;
use App\Tests\Support\S3\TestS3Settings;
use App\Tests\Support\StoredReceipt;
use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * `GET /expenses/{id}/receipt` over a scripted DynamoDB client and a real view-URL signer whose credential lookup is
 * recorded, so a test can show that nothing was signed.
 */
final class GetReceiptHandlerTest extends TestCase
{
    private const string FIELDS = 'receiptId,status,contentType,size,createdAt,failureReason,viewUrl,thumbnailUrl,urlsExpireAt';

    private MockHandler $mock;

    /** @var list<string> */
    private array $sent = [];

    private int $signings = 0;

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $this->mock = new MockHandler();
        $this->sent = [];
        $this->signings = 0;
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        $this->log = $stream;
    }

    private function handler(): Handler
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->sent[] = $command->getName();
            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName);
        $signing = new S3Client([
            'version' => '2006-03-01',
            'region' => 'eu-west-1',
            'endpoint' => 'http://localhost:9000',
            'use_path_style_endpoint' => true,
            'credentials' => function (): PromiseInterface {
                ++$this->signings;

                return Create::promiseFor(new Credentials('localdev', 'localdevsecret'));
            },
        ]);
        $clock = new FrozenClock();

        return new GetReceiptHandler(
            new ReceiptRepository($items),
            new ViewUrlSigner($signing, TestS3Settings::RECEIPTS, TestS3Settings::THUMBNAILS, $clock),
            $clock,
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function serve(string $expenseId = StoredReceipt::EXPENSE, ?Principal $principal = new Principal(StoredReceipt::USER, 'sessionhash'), array $query = []): Response
    {
        $pipeline = new Pipeline(
            [new ErrorHandlingMiddleware(new JsonLogger($this->log)), new StorageUnavailableMiddleware(new JsonLogger($this->log))],
            $this->handler(),
        );
        $request = new Request('GET', '/api/v1/expenses/' . $expenseId . '/receipt', $query, [], '', [Request::REQUEST_ID => 'req-1', Request::ROUTE_PARAMS => ['id' => $expenseId]]);

        return $pipeline->handle($principal === null ? $request : $request->withAttribute(Principal::ATTRIBUTE, $principal));
    }

    /** @return array<string, mixed> */
    private static function decoded(Response $response): array
    {
        $data = json_decode($response->body, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return array<string, string> the query of a presigned URL */
    private static function query(mixed $url): array
    {
        self::assertIsString($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        /** @var array<string, string> $query */
        return $query;
    }

    private function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public function testNoReceiptIsTheGeneric404(): void
    {
        $this->mock->append(new Result([]));

        $response = $this->serve();

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame(['GetItem'], $this->sent);
        self::assertSame(0, $this->signings);
    }

    public function testAnExpiredPendingReceiptIsThe404OfNoReceipt(): void
    {
        $this->mock->append(StoredReceipt::found('pending', 'image/png', '2026-10-16T11:30:00+00:00'));

        $response = $this->serve();

        self::assertSame(404, $response->status);
        self::assertSame(0, $this->signings);
    }

    public function testAnExpiredFailedReceiptIsThe404OfNoReceipt(): void
    {
        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-16T11:59:59+00:00', 'unreadable', '2026-10-16T11:01:00Z'));

        self::assertSame(404, $this->serve()->status);
    }

    public function testTheReadUsesTheCallersPartition(): void
    {
        $this->mock->append(new Result([]));
        $commands = [];
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request) use (&$commands): PromiseInterface {
            $commands[] = $command->toArray();

            $promise = ($this->mock)($command, $request);
            self::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };
        $items = new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName);
        $clock = new FrozenClock();
        $signing = new S3Client(['version' => '2006-03-01', 'region' => 'eu-west-1', 'credentials' => new Credentials('localdev', 'localdevsecret')]);
        $handler = new GetReceiptHandler(new ReceiptRepository($items), new ViewUrlSigner($signing, 'rcp', 'thumbs', $clock), $clock);
        $request = new Request('GET', '/x', [], [], '', [Request::ROUTE_PARAMS => ['id' => StoredReceipt::EXPENSE]]);

        try {
            $handler->handle($request->withAttribute(Principal::ATTRIBUTE, new Principal(StoredReceipt::OTHER_USER, 'sessionhash')));
            self::fail('a missing receipt is a 404');
        } catch (HttpProblem) {
            self::assertCount(1, $commands);
            /** @var array{Key: array{PK: array{S: string}, SK: array{S: string}}} $get */
            $get = $commands[0];
            self::assertSame('USER#' . StoredReceipt::OTHER_USER, $get['Key']['PK']['S']);
            self::assertSame('RCPT#' . StoredReceipt::EXPENSE, $get['Key']['SK']['S']);
        }
    }

    public function testAPendingReceiptHasNoUrlsAndNothingIsSigned(): void
    {
        $this->mock->append(StoredReceipt::found());

        $response = $this->serve();

        self::assertSame(200, $response->status);
        $data = self::decoded($response);
        self::assertSame(explode(',', self::FIELDS), array_keys($data));
        self::assertSame(StoredReceipt::RECEIPT, $data['receiptId']);
        self::assertSame('pending', $data['status']);
        self::assertSame('image/png', $data['contentType']);
        self::assertSame(1234, $data['size']);
        self::assertSame(StoredReceipt::CREATED, $data['createdAt']);
        self::assertNull($data['failureReason']);
        self::assertNull($data['viewUrl']);
        self::assertNull($data['thumbnailUrl']);
        self::assertNull($data['urlsExpireAt']);
        self::assertSame(0, $this->signings, 'a pending receipt signs nothing');
    }

    public function testAFailedReceiptCarriesItsReasonAndNoUrls(): void
    {
        $this->mock->append(StoredReceipt::found('failed', 'image/png', '2026-10-23T11:01:00+00:00', 'unsupported-type', '2026-10-16T11:01:00Z'));

        $data = self::decoded($this->serve());

        self::assertSame('failed', $data['status']);
        self::assertSame('unsupported-type', $data['failureReason']);
        self::assertNull($data['viewUrl']);
        self::assertNull($data['thumbnailUrl']);
        self::assertNull($data['urlsExpireAt']);
        self::assertSame(0, $this->signings);
    }

    public function testAReadyImageHasASignedOriginalAndAThumbnail(): void
    {
        $this->mock->append(StoredReceipt::ready('image/png'));

        $data = self::decoded($this->serve());

        self::assertSame('ready', $data['status']);
        self::assertNull($data['failureReason']);
        self::assertSame('2026-10-16T12:05:00Z', $data['urlsExpireAt']);
        self::assertIsString($data['viewUrl']);
        self::assertIsString($data['thumbnailUrl']);
        self::assertStringContainsString('/rcp/' . StoredReceipt::key()->originalKey() . '?', $data['viewUrl']);
        self::assertStringContainsString('/thumbs/' . StoredReceipt::key()->thumbnailKey() . '?', $data['thumbnailUrl']);
        self::assertSame('image/png', self::query($data['viewUrl'])['response-content-type'] ?? null);
        self::assertSame('inline; filename="receipt.png"', self::query($data['viewUrl'])['response-content-disposition'] ?? null);
        self::assertSame('image/jpeg', self::query($data['thumbnailUrl'])['response-content-type'] ?? null);
        self::assertSame('300', self::query($data['viewUrl'])['X-Amz-Expires'] ?? null);
    }

    public function testAReadyPdfHasAViewUrlOnly(): void
    {
        $this->mock->append(StoredReceipt::ready('application/pdf'));

        $data = self::decoded($this->serve());

        self::assertSame('application/pdf', $data['contentType']);
        self::assertIsString($data['viewUrl']);
        self::assertSame('application/pdf', self::query($data['viewUrl'])['response-content-type'] ?? null);
        self::assertSame('inline; filename="receipt.pdf"', self::query($data['viewUrl'])['response-content-disposition'] ?? null);
        self::assertNull($data['thumbnailUrl']);
        self::assertSame('2026-10-16T12:05:00Z', $data['urlsExpireAt']);
    }

    public function testTheViewUrlIsSignedWithTheStoredTypeNeverWithAnythingFromTheRequest(): void
    {
        $this->mock->append(StoredReceipt::ready('image/webp'));

        $data = self::decoded($this->serve(query: ['contentType' => 'application/pdf', 'response-content-type' => 'text/html', 'type' => 'text/html']));

        self::assertIsString($data['viewUrl']);
        self::assertSame('image/webp', self::query($data['viewUrl'])['response-content-type'] ?? null);
        self::assertSame('image/webp', $data['contentType']);
        self::assertStringNotContainsString('text%2Fhtml', $data['viewUrl']);
    }

    public function testNoUrlOrSignatureIsLogged(): void
    {
        $this->mock->append(StoredReceipt::ready('image/png'));

        $data = self::decoded($this->serve());

        $logged = $this->logged();
        foreach (['viewUrl', 'thumbnailUrl'] as $name) {
            self::assertIsString($data[$name]);
            self::assertStringNotContainsString($data[$name], $logged);
            self::assertStringNotContainsString(self::query($data[$name])['X-Amz-Signature'] ?? 'missing', $logged);
        }
    }

    public function testACorruptRecordIsAGeneric500WithoutAUrlInTheLog(): void
    {
        $item = StoredReceipt::item('ready', 'image/png', null, null, '2026-10-16T11:01:00Z');
        $item['objectKey'] = ['S' => 'receipts/not/a/key'];
        $this->mock->append(new Result(['Item' => $item]));

        $response = $this->serve();

        self::assertSame(500, $response->status);
        self::assertSame(0, $this->signings);
        self::assertStringNotContainsString('X-Amz-Signature', $this->logged());
        self::assertStringNotContainsString('X-Amz-Signature', $response->body);
    }

    public function testAMalformedRouteIdIsTheSame404WithNoStorageCall(): void
    {
        $response = $this->serve(expenseId: 'not-a-uuid');

        self::assertSame(404, $response->status);
        self::assertStringContainsString(RouteId::NOT_FOUND_DETAIL, $response->body);
        self::assertSame([], $this->sent);
    }

    public function testWithoutThePrincipalTheGuardDidNotRunAndNothingHappens(): void
    {
        $request = new Request('GET', '/x', [], [], '', [Request::ROUTE_PARAMS => ['id' => StoredReceipt::EXPENSE]]);

        try {
            $this->handler()->handle($request);
            self::fail('expected a LogicException');
        } catch (LogicException) {
            self::assertSame([], $this->sent);
        }
    }
}
