<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Http\Routing\RouteId;
use App\Tests\Support\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * Creating and reading one expense end to end (nginx -> php-fpm -> DynamoDB Local), every call through request() or
 * requestAs(), so each response is also checked against the bundled contract. Each test registers its own users.
 * Run through `make test-integration`.
 */
final class ExpensesApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const UNKNOWN_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const JSON = ['Content-Type' => 'application/json'];
    private const VALID = '{"date":"2026-10-20","amountCents":1250,"description":"Lunch"}';

    private function signedIn(): string
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'exp-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);

        return $cookie;
    }

    private function post(string $rawBody): ResponseInterface
    {
        return $this->request('POST', '/expenses', ['headers' => self::JSON, 'body' => $rawBody]);
    }

    /**
     * @return array<mixed>
     */
    private function created(string $rawBody = self::VALID): array
    {
        $response = $this->post($rawBody);
        self::assertSame(201, $response->getStatusCode());

        return $this->problemBody($response);
    }

    private function read(string $id): ResponseInterface
    {
        return $this->requestAs('GET', '/expenses/{id}', 'GET', '/api/v1/expenses/' . $id);
    }

    private function createCategory(string $name): string
    {
        $response = $this->request('POST', '/categories', ['headers' => self::JSON, 'body' => json_encode(['name' => $name], JSON_THROW_ON_ERROR)]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return list<string> the pointers of a 422
     */
    private function pointersOf(ResponseInterface $response): array
    {
        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);

        return array_values(array_map(static fn(mixed $error): string => is_array($error) && is_string($error['pointer'] ?? null) ? $error['pointer'] : '', $errors));
    }

    public function testCreateAnswers201WithLocationAndAGetReadsTheSameBody(): void
    {
        $this->signedIn();

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"  Lunch "}');
        $body = $this->problemBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id', 'date', 'amountCents', 'description', 'categoryId', 'version', 'createdAt', 'updatedAt'], array_keys($body));
        self::assertIsString($body['id']);
        self::assertSame(['/api/v1/expenses/' . $body['id']], $response->getHeader('Location'));
        self::assertSame('Lunch', $body['description']);
        self::assertSame(1250, $body['amountCents']);
        self::assertNull($body['categoryId']);
        self::assertSame(1, $body['version']);

        $read = $this->read($body['id']);

        self::assertSame(200, $read->getStatusCode());
        self::assertSame($body, $this->problemBody($read));
    }

    public function testACategoryOfTheCallerIsStoredAndReadBack(): void
    {
        $this->signedIn();
        $food = $this->createCategory('Food');

        $body = $this->created('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . $food . '"}');
        self::assertIsString($body['id']);

        self::assertSame($food, $body['categoryId']);
        self::assertSame($food, $this->problemBody($this->read($body['id']))['categoryId'] ?? null);
    }

    public function testAnExplicitNullCategoryIsUncategorized(): void
    {
        $this->signedIn();

        $body = $this->created('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":null}');

        self::assertNull($body['categoryId']);
    }

    public function testACategoryDeletedAfterTheCreateReadsBackAsNull(): void
    {
        $this->signedIn();
        $food = $this->createCategory('Food');
        $body = $this->created('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . $food . '"}');
        self::assertIsString($body['id']);
        $deleted = $this->requestAs('DELETE', '/categories/{id}', 'DELETE', '/api/v1/categories/' . $food);
        self::assertSame(204, $deleted->getStatusCode());

        $read = $this->read($body['id']);

        self::assertSame(200, $read->getStatusCode());
        $readBody = $this->problemBody($read);
        self::assertArrayHasKey('categoryId', $readBody);
        self::assertNull($readBody['categoryId']);
    }

    public function testAnotherUsersCategoryIsA422AtCategoryId(): void
    {
        $this->signedIn();
        $foreign = $this->createCategory('Food');
        $this->signedIn();

        $response = $this->post('{"date":"2026-10-20","amountCents":1250,"description":"Lunch","categoryId":"' . $foreign . '"}');

        self::assertSame(['/categoryId'], $this->pointersOf($response));
        self::assertSame([], $response->getHeader('Location'));
        self::assertStringNotContainsString($foreign, (string) $response->getBody());
    }

    public function testAnUnknownAndAMalformedCategoryIdAreBoth422AtCategoryId(): void
    {
        $this->signedIn();

        self::assertSame(['/categoryId'], $this->pointersOf($this->post('{"date":"2026-10-20","amountCents":1,"description":"x","categoryId":"' . self::UNKNOWN_ID . '"}')));
        self::assertSame(['/categoryId'], $this->pointersOf($this->post('{"date":"2026-10-20","amountCents":1,"description":"x","categoryId":"nope"}')));
        self::assertSame(['/categoryId'], $this->pointersOf($this->post('{"date":"2026-10-20","amountCents":1,"description":"x","categoryId":5}')));
        self::assertSame(['/categoryId'], $this->pointersOf($this->post('{"date":"2026-10-20","amountCents":1,"description":"x","categoryId":["a"]}')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badAmounts(): iterable
    {
        yield 'a fraction' => ['12.5'];
        yield 'exponent notation' => ['1e2'];
        yield 'a whole float' => ['12.0'];
        yield 'a string' => ['"12"'];
        yield 'true' => ['true'];
        yield 'null' => ['null'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'above the maximum' => ['100000001'];
        yield 'beyond 64 bits' => ['99999999999999999999'];
    }

    #[DataProvider('badAmounts')]
    public function testABadAmountIs422AtAmountCents(string $amountJson): void
    {
        $this->signedIn();

        $response = $this->post('{"date":"2026-10-20","amountCents":' . $amountJson . ',"description":"x"}');

        self::assertSame(['/amountCents'], $this->pointersOf($response));
    }

    public function testTheAmountBoundsAreAccepted(): void
    {
        $this->signedIn();

        self::assertSame(1, $this->created('{"date":"2026-10-20","amountCents":1,"description":"x"}')['amountCents']);
        self::assertSame(100000000, $this->created('{"date":"2099-12-31","amountCents":100000000,"description":"x"}')['amountCents']);
    }

    public function testAMissingDescriptionIs422AtDescription(): void
    {
        $this->signedIn();

        self::assertSame(['/description'], $this->pointersOf($this->post('{"date":"2026-10-20","amountCents":1250}')));
    }

    public function testBadTypesForDateAndDescriptionAreReportedTogether(): void
    {
        $this->signedIn();

        $response = $this->post('{"date":20261020,"amountCents":1250,"description":null}');

        self::assertSame(['/date', '/description'], $this->pointersOf($response));
    }

    public function testAnUnknownIdAndAMalformedIdGiveIdentical404Bodies(): void
    {
        $this->signedIn();

        $unknown = $this->read(self::UNKNOWN_ID);
        $malformed = $this->read('not-a-uuid');

        self::assertSame(404, $unknown->getStatusCode());
        self::assertSame(404, $malformed->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $malformed->getBody());
        self::assertSame(RouteId::NOT_FOUND_DETAIL, $this->problemBody($unknown)['detail'] ?? null);
    }

    public function testAnotherUsersExpenseGivesTheSame404BodyAsAnUnknownId(): void
    {
        $this->signedIn();
        $mine = $this->created();
        self::assertIsString($mine['id']);
        $this->signedIn();

        $foreign = $this->read($mine['id']);
        $unknown = $this->read(self::UNKNOWN_ID);

        self::assertSame(404, $foreign->getStatusCode());
        self::assertSame((string) $unknown->getBody(), (string) $foreign->getBody());
    }

    public function testATextPlainBodyIs415(): void
    {
        $this->signedIn();

        $response = $this->request('POST', '/expenses', ['headers' => ['Content-Type' => 'text/plain'], 'body' => self::VALID]);

        self::assertSame(415, $response->getStatusCode());
    }

    public function testABodyThatIsNotAnObjectIs400(): void
    {
        $this->signedIn();

        self::assertSame(400, $this->post('[]')->getStatusCode());
        self::assertSame(400, $this->post('{nope')->getStatusCode());
    }

    public function testWithoutRequestedWithACreateIs403(): void
    {
        $this->signedIn();
        $this->withoutRequestedWith();

        self::assertSame(403, $this->post(self::VALID)->getStatusCode());
    }

    public function testWithoutACookieBothOperationsAre401EvenForAMalformedId(): void
    {
        $this->withSessionCookie(null);

        self::assertSame(401, $this->post(self::VALID)->getStatusCode());
        self::assertSame(401, $this->read(self::UNKNOWN_ID)->getStatusCode());
        self::assertSame(401, $this->read('not-a-uuid')->getStatusCode());
    }
}
