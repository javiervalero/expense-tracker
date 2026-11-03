<?php

declare(strict_types=1);

namespace App\Tests\Integration\Reports;

use App\Tests\Support\Api\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /reports/monthly` end to end (nginx -> php-fpm -> DynamoDB Local), every call through requestAs(), so each
 * response is also checked against the bundled contract. Each test registers its own user. The lowered-cap cases are in
 * MonthlyReportCapTest (production wiring has no cap hook). Run through `make test-integration`.
 */
final class MonthlyReportApiTest extends ApiTestCase
{
    private const PASSWORD = 'correct horse staple 42';
    private const JSON = ['Content-Type' => 'application/json'];
    private const MONTH_DETAIL = 'The month parameter is not valid.';
    private const UNKNOWN_DETAIL = 'The query string has a parameter this operation does not accept.';

    private function signedIn(): void
    {
        // Registering while carrying a session ends that session, so each user registers without a cookie.
        $this->withSessionCookie(null);
        $response = $this->request('POST', '/auth/register', [
            'headers' => self::JSON,
            'body' => json_encode(['email' => 'rep-' . bin2hex(random_bytes(6)) . '@example.com', 'password' => self::PASSWORD], JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(201, $response->getStatusCode());
        $cookie = $this->sessionCookieFrom($response);
        self::assertNotNull($cookie);
        $this->withSessionCookie($cookie);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(string $path, array $body): string
    {
        $response = $this->request('POST', $path, ['headers' => self::JSON, 'body' => json_encode($body, JSON_THROW_ON_ERROR)]);
        self::assertSame(201, $response->getStatusCode());
        $id = $this->problemBody($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    private function add(string $date, int $cents, ?string $categoryId = null): string
    {
        $body = ['date' => $date, 'amountCents' => $cents, 'description' => 'Item ' . $date];

        return $this->create('/expenses', $categoryId === null ? $body : $body + ['categoryId' => $categoryId]);
    }

    private function addCategory(string $name): string
    {
        return $this->create('/categories', ['name' => $name]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function patch(string $path, array $body): void
    {
        $response = $this->requestAs('PATCH', str_contains($path, 'categories') ? '/categories/{id}' : '/expenses/{id}', 'PATCH', '/api/v1' . $path, [
            'headers' => self::JSON,
            'body' => json_encode($body, JSON_THROW_ON_ERROR),
        ]);
        self::assertSame(200, $response->getStatusCode());
    }

    private function get(string $query): ResponseInterface
    {
        return $this->requestAs('GET', '/reports/monthly', 'GET', '/api/v1/reports/monthly' . $query);
    }

    /**
     * @return array{month: string, totalCents: int, expenseCount: int, complete: bool, categories: list<array{categoryId: string|null, name: string|null, totalCents: int, expenseCount: int}>}
     */
    private function report(string $month): array
    {
        $response = $this->get('?month=' . $month);
        self::assertSame(200, $response->getStatusCode());
        /** @var array{month: string, totalCents: int, expenseCount: int, complete: bool, categories: list<array{categoryId: string|null, name: string|null, totalCents: int, expenseCount: int}>} $body */
        $body = $this->problemBody($response);

        return $body;
    }

    /**
     * @return array{int, int} [totalCents, expenseCount]
     */
    private function totals(string $month): array
    {
        $report = $this->report($month);

        return [$report['totalCents'], $report['expenseCount']];
    }

    public function testAnEmptyMonthIsA200WithNoRows(): void
    {
        $this->signedIn();

        $response = $this->get('?month=2026-10');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['month' => '2026-10', 'totalCents' => 0, 'expenseCount' => 0, 'complete' => true, 'categories' => []], $this->problemBody($response));
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testTheReportHasTheDocumentedShapeAndOrder(): void
    {
        $this->signedIn();
        $food = $this->addCategory('Food');
        $travel = $this->addCategory('Travel');
        $this->add('2026-10-05', 1000, $food);
        $this->add('2026-10-06', 2500, $travel);
        $this->add('2026-10-07', 300);
        $this->add('2026-10-08', 400, $food);

        $report = $this->report('2026-10');

        self::assertSame(4200, $report['totalCents']);
        self::assertSame(4, $report['expenseCount']);
        self::assertTrue($report['complete']);
        self::assertSame(
            [[$travel, 'Travel', 2500, 1], [$food, 'Food', 1400, 2], [null, null, 300, 1]],
            array_map(static fn(array $row): array => [$row['categoryId'], $row['name'], $row['totalCents'], $row['expenseCount']], $report['categories']),
        );
    }

    public function testMonthBoundariesPutEachDayInItsOwnMonth(): void
    {
        $this->signedIn();
        $this->add('2026-01-31', 101);
        $this->add('2026-02-01', 102);
        $this->add('2026-02-28', 104);
        $this->add('2026-03-01', 108);

        self::assertSame([101, 1], $this->totals('2026-01'));
        self::assertSame([206, 2], $this->totals('2026-02'));
        self::assertSame([108, 1], $this->totals('2026-03'));
    }

    public function testTheLastDayOfALeapFebruaryIsInFebruary(): void
    {
        $this->signedIn();
        $this->add('2024-02-29', 201);
        $this->add('2024-03-01', 202);
        $this->add('2024-02-01', 204);

        self::assertSame([405, 2], $this->totals('2024-02'));
        self::assertSame([202, 1], $this->totals('2024-03'));
    }

    public function testAnExpenseRedatedToAnotherMonthMovesWithIt(): void
    {
        $this->signedIn();
        $id = $this->add('2026-09-15', 700);
        self::assertSame([700, 1], $this->totals('2026-09'));

        $this->patch('/expenses/' . $id, ['version' => 1, 'date' => '2026-10-02']);

        self::assertSame([0, 0], $this->totals('2026-09'));
        self::assertSame([700, 1], $this->totals('2026-10'));
    }

    public function testARenamedCategoryShowsItsNewName(): void
    {
        $this->signedIn();
        $category = $this->addCategory('Old name');
        $this->add('2026-10-05', 500, $category);
        self::assertSame('Old name', $this->report('2026-10')['categories'][0]['name']);

        $this->patch('/categories/' . $category, ['name' => 'New name', 'version' => 1]);

        $rows = $this->report('2026-10')['categories'];
        self::assertCount(1, $rows);
        self::assertSame([$category, 'New name'], [$rows[0]['categoryId'], $rows[0]['name']]);
    }

    public function testADeletedCategorysExpensesMoveToUncategorized(): void
    {
        $this->signedIn();
        $gone = $this->addCategory('Gone');
        $this->add('2026-10-05', 500, $gone);
        $this->add('2026-10-06', 250);

        $response = $this->requestAs('DELETE', '/categories/{id}', 'DELETE', '/api/v1/categories/' . $gone);
        self::assertSame(204, $response->getStatusCode());

        $report = $this->report('2026-10');
        self::assertSame([[null, null, 750, 2]], array_map(static fn(array $row): array => [$row['categoryId'], $row['name'], $row['totalCents'], $row['expenseCount']], $report['categories']));
        self::assertStringNotContainsString($gone, json_encode($report, JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string, string}> query string, expected detail
     */
    public static function refusedQueries(): iterable
    {
        yield 'no query' => ['', self::MONTH_DETAIL];
        yield 'empty month' => ['?month=', self::MONTH_DETAIL];
        // Refused earlier, by the kernel's own rule on bracketed parameter names (the value is never read).
        yield 'array month' => ['?month[]=2026-10', 'Query parameter names cannot contain "[" or "]".'];
        yield 'one digit month' => ['?month=2026-1', self::MONTH_DETAIL];
        yield 'month 13' => ['?month=2026-13', self::MONTH_DETAIL];
        yield 'month 00' => ['?month=2026-00', self::MONTH_DETAIL];
        yield 'year before the range' => ['?month=1999-12', self::MONTH_DETAIL];
        yield 'year after the range' => ['?month=2100-01', self::MONTH_DETAIL];
        yield 'leading space' => ['?month=%202026-10', self::MONTH_DETAIL];
        yield 'trailing space' => ['?month=2026-10%20', self::MONTH_DETAIL];
        yield 'full-width digits' => ['?month=%EF%BC%92%EF%BC%90%EF%BC%92%EF%BC%96-10', self::MONTH_DETAIL];
        yield 'a full date' => ['?month=2026-10-01', self::MONTH_DETAIL];
        yield 'unknown parameter' => ['?month=2026-10&from=2026-10-01', self::UNKNOWN_DETAIL];
        yield 'only an unknown parameter' => ['?mont=2026-10', self::UNKNOWN_DETAIL];
        yield 'wrong key case' => ['?Month=2026-10', self::UNKNOWN_DETAIL];
        yield 'trailing newline' => ['?month=2026-10%0A', self::MONTH_DETAIL];
    }

    #[DataProvider('refusedQueries')]
    public function testARefusedQueryIs400WithAFixedDetail(string $query, string $detail): void
    {
        $this->signedIn();

        $response = $this->get($query);

        self::assertSame(400, $response->getStatusCode(), $query);
        self::assertSame($detail, $this->problemBody($response)['detail'] ?? null, $query);
    }

    public function testAnInvalidMonthIsNeverEchoed(): void
    {
        $this->signedIn();

        $response = $this->get('?month=SECRET-VALUE-9');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringNotContainsString('SECRET-VALUE-9', (string) $response->getBody());
    }

    public function testWithoutASessionTheAnswerIs401EvenForABadQuery(): void
    {
        $this->withSessionCookie(null);

        self::assertSame(401, $this->get('?month=2026-10')->getStatusCode());
        self::assertSame(401, $this->get('')->getStatusCode());
        self::assertSame(401, $this->get('?month=garbage')->getStatusCode());
    }
}
