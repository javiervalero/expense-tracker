<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Auth\Session\Principal;
use App\Categories\Category;
use App\Categories\CategoryName;
use App\Categories\CategoryRepository;
use App\Http\Handler;
use App\Http\Middleware\ErrorHandlingMiddleware;
use App\Http\Middleware\StorageUnavailableMiddleware;
use App\Http\Pipeline;
use App\Http\Request;
use App\Http\Response;
use App\Infrastructure\DynamoDb\DynamoDbClientFactory;
use App\Infrastructure\DynamoDb\DynamoDbSettings;
use App\Infrastructure\DynamoDb\ItemStore;
use App\Infrastructure\Logging\JsonLogger;
use Aws\Command;
use Aws\CommandInterface;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use DateTimeImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/**
 * A real {@see CategoryRepository} over a scripted DynamoDB client, for the unit tests of the category handlers: the
 * test queues the answers (in the order the code will ask), and afterwards reads which commands were sent. Also builds
 * the stored shapes of a category and runs a handler through the two middleware that turn exceptions into responses.
 * Ids, names and times are invented.
 */
final class ScriptedCategoryStore
{
    public const string USER = '3f2b8c1e-5d4a-4b7e-9a60-1c2d3e4f5a6b';
    private const int SDK_ATTEMPTS = 3;
    public const string NOW = '2026-10-23T10:00:00+00:00';

    private MockHandler $mock;

    /** @var list<CommandInterface> */
    private array $commands = [];

    /** @var resource */
    private $log;

    public function __construct()
    {
        $this->mock = new MockHandler();
        $stream = fopen('php://memory', 'w+b');
        Assert::assertIsResource($stream);
        $this->log = $stream;
    }

    public function repository(): CategoryRepository
    {
        $settings = DynamoDbSettings::fromEnvironment(['DYNAMODB_TABLE' => 'expense-tracker-test', 'AWS_REGION' => 'eu-west-1']);
        $recording = function (CommandInterface $command, RequestInterface $request): PromiseInterface {
            $this->commands[] = $command;
            $promise = ($this->mock)($command, $request);
            Assert::assertInstanceOf(PromiseInterface::class, $promise);

            return $promise;
        };

        return new CategoryRepository(new ItemStore(DynamoDbClientFactory::create($settings, $recording), $settings->tableName));
    }

    public static function owner(): Principal
    {
        return new Principal(self::USER, str_repeat('a', 64));
    }

    /**
     * Queues the answers, in the order the code under test will ask for them.
     *
     * @param Result<string, mixed>|DynamoDbException ...$answers
     */
    public function answer(Result|DynamoDbException ...$answers): void
    {
        foreach ($answers as $answer) {
            $this->mock->append($answer);
        }
    }

    /** @return list<string> the DynamoDB operations sent so far, in order */
    public function sent(): array
    {
        return array_map(static fn(CommandInterface $command): string => $command->getName(), $this->commands);
    }

    /** The recorded command number `$index` (0 is the first one sent). */
    public function command(int $index): CommandInterface
    {
        return $this->commands[$index] ?? throw new \OutOfBoundsException('No command number ' . $index . ' was sent.');
    }

    /** Runs the handler inside ErrorHandling and StorageUnavailable, as the kernel does, so exceptions become responses. */
    public function serve(Handler $handler, Request $request): Response
    {
        $pipeline = new Pipeline(
            [new ErrorHandlingMiddleware(new JsonLogger($this->log)), new StorageUnavailableMiddleware(new JsonLogger($this->log))],
            $handler,
        );

        return $pipeline->handle($request);
    }

    /** Everything the middleware logged while serving. */
    public function logged(): string
    {
        rewind($this->log);

        return (string) stream_get_contents($this->log);
    }

    public static function category(string $id, string $name, int $version = 1): Category
    {
        $parsed = CategoryName::parse($name);
        Assert::assertInstanceOf(CategoryName::class, $parsed);
        $time = new DateTimeImmutable(self::NOW);

        return new Category($id, $parsed, $parsed->key(), $version, $time, $time);
    }

    /** @return Result<string, mixed> the answer of a `GetItem` that finds the category */
    public static function found(Category $category): Result
    {
        return new Result(['Item' => self::stored($category)]);
    }

    /** @return Result<string, mixed> the answer of a `GetItem` that finds nothing */
    public static function missing(): Result
    {
        return new Result([]);
    }

    /**
     * @param list<Category> $categories
     *
     * @return Result<string, mixed> the answer of a one-page `Query` for the user's categories
     */
    public static function listing(array $categories): Result
    {
        return new Result(['Items' => array_map(self::stored(...), $categories)]);
    }

    /** @return Result<string, mixed> the answer of a `GetItem` on a name lock held by `$holderId` */
    public static function lockHeldBy(string $holderId): Result
    {
        return new Result(['Item' => ['PK' => ['S' => 'USER#' . self::USER], 'SK' => ['S' => 'CATNAME#' . str_repeat('0', 64)], 'categoryId' => ['S' => $holderId]]]);
    }

    /** @return Result<string, mixed> */
    public static function ok(): Result
    {
        return new Result([]);
    }

    /** @param list<string> $codes one cancellation code per transaction item */
    public static function cancelled(array $codes): DynamoDbException
    {
        $reasons = array_map(static fn(string $code): array => ['Code' => $code], $codes);

        return new DynamoDbException('cancelled', new Command('TransactWriteItems'), ['code' => 'TransactionCanceledException', 'body' => ['CancellationReasons' => $reasons]]);
    }

    /** Queues a throttled answer for every attempt the SDK makes (it retries throttling before giving up). */
    public function answerThrottled(): void
    {
        for ($attempt = 0; $attempt < self::SDK_ATTEMPTS; ++$attempt) {
            $this->mock->append(new DynamoDbException('throttled', new Command('Query'), ['code' => 'ProvisionedThroughputExceededException']));
        }
    }

    /**
     * @param array<string, string> $routeParams
     */
    public static function request(string $method, string $path, string $body = '', ?Principal $principal = null, array $routeParams = [], string $contentType = 'application/json'): Request
    {
        $headers = $body === '' ? [] : ['content-type' => $contentType];
        $request = new Request($method, $path, [], $headers, $body, [Request::REQUEST_ID => 'req-1', Request::ROUTE_PARAMS => $routeParams]);

        return $principal === null ? $request : $request->withAttribute(Principal::ATTRIBUTE, $principal);
    }

    /** @return array<string, array{S?: string, N?: string}> */
    private static function stored(Category $category): array
    {
        return [
            'PK' => ['S' => 'USER#' . self::USER],
            'SK' => ['S' => 'CAT#' . $category->id],
            'categoryId' => ['S' => $category->id],
            'name' => ['S' => $category->name->value()],
            'nameKey' => ['S' => $category->nameKey],
            'version' => ['N' => (string) $category->version],
            'createdAt' => ['S' => $category->createdAt->format('Y-m-d\TH:i:s\Z')],
            'updatedAt' => ['S' => $category->updatedAt->format('Y-m-d\TH:i:s\Z')],
        ];
    }
}
