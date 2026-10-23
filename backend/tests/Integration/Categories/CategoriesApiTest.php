<?php

declare(strict_types=1);

namespace App\Tests\Integration\Categories;

use App\Categories\CategoryLimits;
use App\Http\Routing\RouteId;
use App\Tests\Support\Api\ApiTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The category endpoints end to end (nginx -> php-fpm -> DynamoDB Local), every call through request() or requestAs(),
 * so each response is also checked against the bundled contract. Each test registers its own users, so nothing is shared.
 * Run through `make test-integration`.
 */
final class CategoriesApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const UNKNOWN_ID = '9d1c7a52-0b3e-4f68-8a21-7e5d4c3b2a10';
    private const JSON = ['Content-Type' => 'application/json'];

    private function signedIn(): string
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'cat-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);

        return $cookie;
    }

    private function create(string $name): ResponseInterface
    {
        return $this->request('POST', '/categories', ['headers' => self::JSON, 'body' => json_encode(['name' => $name], JSON_THROW_ON_ERROR)]);
    }

    /**
     * @return array<mixed>
     */
    private function created(string $name): array
    {
        $response = $this->create($name);
        self::assertSame(201, $response->getStatusCode());

        return $this->problemBody($response);
    }

    private function rename(string $id, string $name, mixed $version): ResponseInterface
    {
        return $this->requestAs('PATCH', '/categories/{id}', 'PATCH', '/api/v1/categories/' . $id, [
            'headers' => self::JSON,
            'body' => json_encode(['name' => $name, 'version' => $version], JSON_THROW_ON_ERROR),
        ]);
    }

    private function remove(string $id): ResponseInterface
    {
        return $this->requestAs('DELETE', '/categories/{id}', 'DELETE', '/api/v1/categories/' . $id);
    }

    private function detailOf(ResponseInterface $response): string
    {
        $detail = $this->problemBody($response)['detail'] ?? null;
        self::assertIsString($detail);

        return $detail;
    }

    /**
     * @return list<string> the names in the order the list gives them
     */
    private function listedNames(): array
    {
        $response = $this->request('GET', '/categories');
        self::assertSame(200, $response->getStatusCode());
        $items = $this->problemBody($response)['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(static fn(mixed $item): string => is_array($item) && is_string($item['name'] ?? null) ? $item['name'] : '', $items));
    }

    public function testCreateAnswers201WithTheCategoryAndItsLocation(): void
    {
        $this->signedIn();

        $response = $this->create('  Food ');
        $body = $this->problemBody($response);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['id', 'name', 'version', 'createdAt', 'updatedAt'], array_keys($body));
        self::assertIsString($body['id']);
        self::assertSame(['/api/v1/categories/' . $body['id']], $response->getHeader('Location'));
        self::assertSame('Food', $body['name']);
        self::assertSame(1, $body['version']);
    }

    public function testTheSameNameWithAnotherCaseIsA409(): void
    {
        $this->signedIn();
        $this->created('Food');

        $response = $this->create('food');

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('A category with this name already exists.', $this->problemBody($response)['detail'] ?? null);
    }

    public function testTheSameNameIsFreeForAnotherUser(): void
    {
        $this->signedIn();
        $this->created('Food');
        $this->signedIn();

        self::assertSame(201, $this->create('Food')->getStatusCode());
    }

    public function testTheHundredAndFirstCategoryIsA409(): void
    {
        $this->signedIn();
        for ($number = 1; $number <= CategoryLimits::MAX_PER_USER; ++$number) {
            self::assertSame(201, $this->create('Category ' . $number)->getStatusCode(), 'category ' . $number);
        }

        $response = $this->create('One too many');

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('maximum of 100 categories', $this->detailOf($response));
        self::assertCount(CategoryLimits::MAX_PER_USER, $this->listedNames());
    }

    public function testRenameWithTheCurrentVersionAnswers200WithTheNextVersion(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);

        $response = $this->rename($food['id'], 'Groceries', 1);
        $body = $this->problemBody($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Groceries', $body['name']);
        self::assertSame(2, $body['version']);
        self::assertSame($food['id'], $body['id']);
        self::assertSame($food['createdAt'], $body['createdAt']);
    }

    public function testACaseOnlyRenameWorks(): void
    {
        $this->signedIn();
        $food = $this->created('food');
        self::assertIsString($food['id']);

        $response = $this->rename($food['id'], 'Food', 1);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Food', $this->problemBody($response)['name']);
    }

    public function testRenameWithAStaleVersionIsA409(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);
        self::assertSame(200, $this->rename($food['id'], 'Groceries', 1)->getStatusCode());

        $response = $this->rename($food['id'], 'Meals', 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('The category changed since you loaded it. Reload it and try again.', $this->problemBody($response)['detail'] ?? null);
    }

    public function testRenameToANameThatAnotherCategoryHoldsIsA409(): void
    {
        $this->signedIn();
        $this->created('Travel');
        $food = $this->created('Food');
        self::assertIsString($food['id']);

        $response = $this->rename($food['id'], 'TRAVEL', 1);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('A category with this name already exists.', $this->problemBody($response)['detail'] ?? null);
    }

    public function testDeleteAnswers204AndASecondDeleteIs404(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);

        $first = $this->remove($food['id']);
        $second = $this->remove($food['id']);

        self::assertSame(204, $first->getStatusCode());
        self::assertSame('', (string) $first->getBody());
        self::assertSame(404, $second->getStatusCode());
        self::assertSame([], $this->listedNames());
    }

    public function testADeletedNameCanBeUsedAgain(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);
        $this->remove($food['id']);

        self::assertSame(201, $this->create('food')->getStatusCode());
    }

    public function testAnotherUsersCategoryIdGivesTheSame404BodyAsAnUnknownId(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);
        $this->signedIn();

        $foreignRename = $this->rename($food['id'], 'Mine now', 1);
        $unknownRename = $this->rename(self::UNKNOWN_ID, 'Mine now', 1);
        $foreignDelete = $this->remove($food['id']);
        $unknownDelete = $this->remove(self::UNKNOWN_ID);
        $malformed = $this->remove('not-a-uuid');

        foreach ([$foreignRename, $unknownRename, $foreignDelete, $unknownDelete, $malformed] as $response) {
            self::assertSame(404, $response->getStatusCode());
            self::assertSame((string) $unknownRename->getBody(), (string) $response->getBody());
        }
        self::assertSame(RouteId::NOT_FOUND_DETAIL, $this->problemBody($unknownRename)['detail'] ?? null);
        self::assertSame([], $this->listedNames());
    }

    public function testTheOwnerStillHasTheCategoryAfterAnotherUsersAttempts(): void
    {
        $owner = $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);
        $this->signedIn();
        $this->remove($food['id']);
        $this->withSessionCookie($owner);

        self::assertSame(['Food'], $this->listedNames());
    }

    public function testANumberAsTheNameIs422AtName(): void
    {
        $this->signedIn();

        $response = $this->request('POST', '/categories', ['headers' => self::JSON, 'body' => '{"name":5}']);

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('/name', $errors[0]['pointer'] ?? null);
    }

    public function testAWrongTypedVersionIs422AtVersion(): void
    {
        $this->signedIn();
        $food = $this->created('Food');
        self::assertIsString($food['id']);

        $response = $this->rename($food['id'], 'Groceries', '1');

        self::assertSame(422, $response->getStatusCode());
        $errors = $this->problemBody($response)['errors'] ?? null;
        self::assertIsArray($errors);
        self::assertIsArray($errors[0]);
        self::assertSame('/version', $errors[0]['pointer'] ?? null);
    }

    public function testATextPlainBodyIs415(): void
    {
        $this->signedIn();

        $response = $this->request('POST', '/categories', ['headers' => ['Content-Type' => 'text/plain'], 'body' => '{"name":"Food"}']);

        self::assertSame(415, $response->getStatusCode());
    }

    public function testWithoutRequestedWithAStateChangeIs403(): void
    {
        $this->signedIn();
        $this->withoutRequestedWith();

        $response = $this->create('Food');

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->listedNames());
    }

    public function testWithoutACookieEveryOperationIs401(): void
    {
        $this->withSessionCookie(null);

        self::assertSame(401, $this->request('GET', '/categories')->getStatusCode());
        self::assertSame(401, $this->create('Food')->getStatusCode());
        self::assertSame(401, $this->rename(self::UNKNOWN_ID, 'x', 1)->getStatusCode());
        self::assertSame(401, $this->remove(self::UNKNOWN_ID)->getStatusCode());
        // The guard runs before the route id is looked at: a malformed id is a 401 too, not a 404.
        self::assertSame(401, $this->remove('not-a-uuid')->getStatusCode());
    }

    public function testTheListIsSortedByFoldedNameAndStartsEmpty(): void
    {
        $this->signedIn();
        self::assertSame([], $this->listedNames());
        foreach (['banana', 'Cherry', 'apple', 'Äpfel'] as $name) {
            $this->created($name);
        }

        self::assertSame(['apple', 'banana', 'Cherry', 'Äpfel'], $this->listedNames());
    }
}
