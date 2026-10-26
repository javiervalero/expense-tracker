<?php

declare(strict_types=1);

namespace App\Tests\Integration\Expenses;

use App\Tests\Support\Api\ApiTestCase;
use App\Tests\Support\AuthMarkers;
use Psr\Http\Message\ResponseInterface;

/**
 * One user's whole journey, end to end (nginx -> php-fpm -> DynamoDB Local), every call through requestAs(), so each
 * response is also checked against the bundled contract: category -> expense -> list -> rename category -> patch
 * expense -> delete category (the expense becomes Uncategorized) -> delete expense.
 *
 * The test also sends user content through every path (create, rename, patch, a duplicate name 409, a too long
 * description 422, a too long `q` 400, a cursor), each value unique per run, and writes the markers to the file that
 * `make test-integration` greps in the api-php logs: user content is never logged (B23). The descriptions and names are
 * checked in the nginx access log too; `q` and the cursor are not, because local nginx logs query strings, so they
 * appear there. The recipe needs the whole suite, not --filter.
 */
final class ExpenseFlowApiTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse staple 42';
    private const array JSON = ['Content-Type' => 'application/json'];
    private const int DESCRIPTION_TOO_LONG = 201;
    private const int QUERY_TOO_LONG = 101;

    public function testCategoryExpenseListRenamePatchDeleteCategoryDeleteExpense(): void
    {
        // Unique per run and not a substring of anything the stack logs, so a grep hit can only be a leak.
        $markers = [
            'contentDescription' => 'desc-marker-' . bin2hex(random_bytes(10)),
            'contentDescription2' => 'desc-marker-' . bin2hex(random_bytes(10)),
            'contentCategoryName' => 'cat-marker-' . bin2hex(random_bytes(10)),
            'contentCategoryName2' => 'cat-marker-' . bin2hex(random_bytes(10)),
            'contentQuery' => 'q-marker-' . bin2hex(random_bytes(10)),
        ];
        $this->signedIn();

        [$categoryId, $expense, $searchedId] = $this->createWithRefusals($markers);
        $expenseId = self::text($expense, 'id');
        $markers['contentCursor'] = $this->listAndSearch(
            $markers['contentQuery'],
            $categoryId,
            $expenseId,
            $searchedId,
        );
        $this->renameAndPatch($markers, $categoryId, $expense);
        $this->deleteCategoryThenExpense($categoryId, $expenseId, $searchedId);

        AuthMarkers::write($markers);
    }

    /**
     * Creates the category and the two expenses, and sends the refused variants that carry the same content (a
     * duplicate name, a 201 character description, which starts with the marker).
     *
     * @param array<string, string> $markers
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string} the category id, the first expense, the second
     *                                                              expense id
     */
    private function createWithRefusals(array $markers): array
    {
        $category = $this->send('POST', '/categories', '/categories', ['name' => $markers['contentCategoryName']], 201);
        self::assertSame($markers['contentCategoryName'], $category['name']);
        $categoryId = self::text($category, 'id');
        $this->send('POST', '/categories', '/categories', ['name' => $markers['contentCategoryName']], 409);

        $expense = $this->send('POST', '/expenses', '/expenses', [
            'date' => '2026-10-20',
            'amountCents' => 1250,
            'description' => $markers['contentDescription'],
            'categoryId' => $categoryId,
        ], 201);
        $tooLong = str_pad($markers['contentDescription'], self::DESCRIPTION_TOO_LONG, 'x');
        $this->send('POST', '/expenses', '/expenses', [
            'date' => '2026-10-20', 'amountCents' => 1, 'description' => $tooLong,
        ], 422);
        $searched = $this->send('POST', '/expenses', '/expenses', [
            'date' => '2026-10-19', 'amountCents' => 300, 'description' => 'Dinner ' . $markers['contentQuery'],
        ], 201);

        return [$categoryId, $expense, self::text($searched, 'id')];
    }

    /**
     * @return string a non-null `nextCursor`, for the log check
     */
    private function listAndSearch(string $query, string $categoryId, string $expenseId, string $searchedId): string
    {
        self::assertSame([$expenseId], $this->listedIds('category=' . $categoryId));
        self::assertSame([$searchedId], $this->listedIds('q=' . $query));
        self::assertSame([$expenseId, $searchedId], $this->listedIds(''));
        $tooLong = str_pad($query, self::QUERY_TOO_LONG, 'x');
        self::assertSame(400, $this->bare('GET', '/expenses', '/expenses?q=' . $tooLong)->getStatusCode());

        $firstPage = $this->decode($this->bare('GET', '/expenses', '/expenses?limit=1'));
        $cursor = $firstPage['nextCursor'] ?? null;
        self::assertIsString($cursor);
        // The cursor is sent back: valid for its own filters, refused (hash mismatch) with another filter.
        $replayed = $this->bare('GET', '/expenses', '/expenses?limit=1&cursor=' . rawurlencode($cursor));
        self::assertSame(200, $replayed->getStatusCode());
        $otherFilter = $this->bare('GET', '/expenses', '/expenses?limit=1&q=other&cursor=' . rawurlencode($cursor));
        self::assertSame(400, $otherFilter->getStatusCode());

        return $cursor;
    }

    /**
     * @param array<string, string> $markers
     * @param array<string, mixed>  $expense the expense as created
     */
    private function renameAndPatch(array $markers, string $categoryId, array $expense): void
    {
        $expenseId = self::text($expense, 'id');
        $read = $this->bare('GET', '/expenses/{id}', '/expenses/' . $expenseId);
        self::assertSame(200, $read->getStatusCode());
        self::assertSame($expense, $this->decode($read));

        $renamed = $this->send('PATCH', '/categories/{id}', '/categories/' . $categoryId, [
            'name' => $markers['contentCategoryName2'], 'version' => 1,
        ], 200);
        self::assertSame(2, $renamed['version']);
        self::assertSame($markers['contentCategoryName2'], $renamed['name']);
        self::assertSame([$markers['contentCategoryName2']], $this->categoryNames());

        $patched = $this->send('PATCH', '/expenses/{id}', '/expenses/' . $expenseId, [
            'version' => 1, 'amountCents' => 1999, 'description' => $markers['contentDescription2'],
        ], 200);
        self::assertSame(1999, $patched['amountCents']);
        self::assertSame($markers['contentDescription2'], $patched['description']);
        self::assertSame($categoryId, $patched['categoryId']);
        self::assertSame(2, $patched['version']);
    }

    private function deleteCategoryThenExpense(string $categoryId, string $expenseId, string $searchedId): void
    {
        self::assertSame(204, $this->bare('DELETE', '/categories/{id}', '/categories/' . $categoryId)->getStatusCode());
        self::assertSame([], $this->categoryNames());
        $afterDeleteResponse = $this->bare('GET', '/expenses/{id}', '/expenses/' . $expenseId);
        self::assertSame(200, $afterDeleteResponse->getStatusCode());
        $afterDelete = $this->decode($afterDeleteResponse);
        self::assertNull($afterDelete['categoryId']);
        self::assertSame(2, $afterDelete['version'], 'Deleting a category does not rewrite the expense.');
        self::assertSame([$expenseId, $searchedId], $this->listedIds('category=none'));
        self::assertSame([], $this->listedIds('category=' . $categoryId));

        self::assertSame(204, $this->bare('DELETE', '/expenses/{id}', '/expenses/' . $expenseId)->getStatusCode());
        self::assertSame(404, $this->bare('GET', '/expenses/{id}', '/expenses/' . $expenseId)->getStatusCode());
        self::assertSame([$searchedId], $this->listedIds(''));
    }

    /**
     * @return list<mixed> the names GET /categories lists
     */
    private function categoryNames(): array
    {
        $response = $this->bare('GET', '/categories', '/categories');
        self::assertSame(200, $response->getStatusCode());
        $items = $this->problemBody($response)['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(
            static fn(mixed $item): mixed => is_array($item) ? $item['name'] ?? null : null,
            $items,
        ));
    }

    private function signedIn(): void
    {
        // Registering while carrying a session ends that session, so the user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode([
                'email' => 'flow-' . bin2hex(random_bytes(6)) . '@example.com',
                'password' => self::PASSWORD,
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function send(string $method, string $template, string $path, array $body, int $expectedStatus): array
    {
        $response = $this->requestAs($method, $template, $method, '/api/v1' . $path, [
            'headers' => self::JSON,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        self::assertSame($expectedStatus, $response->getStatusCode());

        return $this->decode($response);
    }

    private function bare(string $method, string $template, string $path): ResponseInterface
    {
        return $this->requestAs($method, $template, $method, '/api/v1' . $path);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return $this->problemBody($response);
    }

    /**
     * @param array<string, mixed> $resource
     */
    private static function text(array $resource, string $key): string
    {
        self::assertIsString($resource[$key] ?? null);

        return $resource[$key];
    }

    /**
     * @return list<string> the ids of one page of GET /expenses
     */
    private function listedIds(string $queryString): array
    {
        $path = '/api/v1/expenses' . ($queryString === '' ? '' : '?' . $queryString);
        $response = $this->requestAs('GET', '/expenses', 'GET', $path);
        self::assertSame(200, $response->getStatusCode());
        $items = $this->problemBody($response)['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(
            static fn(mixed $item): string => is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '',
            $items,
        ));
    }
}
