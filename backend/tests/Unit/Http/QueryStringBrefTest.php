<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Problem\HttpProblem;
use App\Http\QueryString;
use Bref\Event\Http\HttpRequestEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * On Lambda the query string does not reach PHP as sent: Bref rebuilds it from the payload (format 2.0 carries
 * `rawQueryString`) through PHP's own `parse_str`/`http_build_query` semantics. These tests pin what production
 * really feeds to {@see QueryString::parse()}, so a Bref upgrade that changes it is noticed.
 */
final class QueryStringBrefTest extends TestCase
{
    /**
     * @param array<string, string>|string $expected the parsed query, or the 400 detail
     */
    #[DataProvider('queries')]
    public function testWhatQueryStringSeesAfterBrefNormalisedTheRawQuery(
        string $raw,
        string $expectedBrefQuery,
        array|string $expected,
    ): void {
        $event = new HttpRequestEvent([
            'version' => '2.0',
            'rawPath' => '/api/v1/expenses',
            'rawQueryString' => $raw,
            'headers' => [],
            'requestContext' => ['http' => ['method' => 'GET', 'path' => '/api/v1/expenses']],
            'isBase64Encoded' => false,
        ]);

        $brefQuery = $event->getQueryString();

        self::assertSame($expectedBrefQuery, $brefQuery);
        if (is_string($expected)) {
            try {
                QueryString::parse($brefQuery);
                self::fail('Expected a 400 problem.');
            } catch (HttpProblem $problem) {
                self::assertSame(400, $problem->problem->status);
                self::assertSame($expected, $problem->problem->detail);
            }

            return;
        }
        self::assertSame($expected, QueryString::parse($brefQuery));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>|string}>
     */
    public static function queries(): iterable
    {
        // Bref's parse_str keeps the last duplicate silently: the "repeated name" 400 never happens on Lambda.
        yield 'name repeated through an encoded spelling' => ['a=1&%61=2', 'a=2', ['a' => '2']];
        // An invalid escape is re-encoded as a literal "%": no 400 on Lambda, the handler sees "%zz".
        yield 'invalid escape' => ['a=%zz', 'a=%25zz', ['a' => '%zz']];
        // A pair with an empty name vanishes: no 400 on Lambda.
        yield 'empty name' => ['=x', '', []];
        // A repeated name becomes a[0], a[1]: rejected, but by the bracket rule, not the repeated-name rule.
        yield 'repeated name' => ['a=1&a=2', 'a%5B0%5D=1&a%5B1%5D=2', 'Query parameter names cannot contain "[" or "]".'];
        // "a+b" and "a%20b" are the same name after decoding, yet they stay two parameters here: the first keeps its
        // space ("a b"), the second arrives as "a_b" (PHP's name mangling), so no "repeated" 400 on Lambda either.
        yield 'plus and %20 in a name' => ['a+b=1&a%20b=2', 'a%20b=1&a_b=2', ['a b' => '1', 'a_b' => '2']];
    }
}
