<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Tests\Support\Api\ApiTestCase;

/**
 * The client address end to end (nginx -> php-fpm -> ClientIp). No route exposes the address, so the test sends three
 * requests and `make test-integration` then reads the `api-php` log for what ClientIp derived. All three are served
 * (200; the third: 404, an unknown path): a missing or invalid address is logged, never a reason to reject (ADR 0012 section 4.3).
 *
 *  1. one valid `CloudFront-Viewer-Address`: the request line must show that address as `clientIp`;
 *  2. two copies of the header, which nginx joins into "a, b": an error line `client address unavailable` with the
 *     reason `multiple-values`, and a request line with `clientIp` `unknown`;
 *  3. no header, which nginx fills from the socket: a request line with a private (Docker network) address. Its path
 *     (`/api/v1/socket-<hex>`, unique per run, an unknown path so 404) is what ties that line to this request.
 *
 * The values are unique per run and are written to `build/client-ip-test/markers.json`, one key per line, which
 * the Makefile reads; keep the names in sync with it. Run it through `make test-integration` (the stack must be up).
 */
final class ClientIpOverNginxTest extends ApiTestCase
{
    /** Read by the Makefile (`make test-integration`). */
    private const MARKER_DIRECTORY = 'build/client-ip-test';
    private const MARKER_FILE = 'markers.json';
    private const HEADER = 'CloudFront-Viewer-Address';

    /** @var array<string, string> */
    private static array $markers = [];

    public function testAValidViewerAddressIsServedAndRecordedForTheMakefile(): void
    {
        $unique = bin2hex(random_bytes(4));
        // Canonical form (inet_ntop): the API logs `2001:db8:0:a::1`, not `2001:db8:0000:000a::1`.
        $ip = (string) inet_ntop((string) inet_pton(sprintf('2001:db8:%s:%s::1', substr($unique, 0, 4), substr($unique, 4, 4))));

        $response = $this->request('GET', '/health', ['headers' => [self::HEADER => "[$ip]:" . random_int(1024, 65535)]]);

        self::assertSame(200, $response->getStatusCode());
        self::writeMarkers(['validIp' => $ip]);
    }

    public function testADuplicatedViewerAddressIsServedAndRecordedForTheMakefile(): void
    {
        $unique = bin2hex(random_bytes(4));
        $first = sprintf('2001:db8:%s:%s::2:%d', substr($unique, 0, 4), substr($unique, 4, 4), random_int(1024, 65535));
        $second = sprintf('198.51.100.%d:%d', random_int(1, 254), random_int(1024, 65535));

        $response = $this->request('GET', '/health', ['headers' => [self::HEADER => [$first, $second]]]);

        self::assertSame(200, $response->getStatusCode());
        self::writeMarkers(['duplicateValue' => "$first, $second"]);
    }

    public function testNoViewerAddressIsLoggedWithTheSocketAddressFromNginxForAUniquePath(): void
    {
        $path = '/api/v1/socket-' . bin2hex(random_bytes(4));

        $response = $this->requestAs('GET', '/health', 'GET', $path);

        self::assertSame(404, $response->getStatusCode());
        self::writeMarkers(['socketPath' => $path]);
    }

    /**
     * @param array<string, string> $markers
     */
    private static function writeMarkers(array $markers): void
    {
        self::$markers = $markers + self::$markers;
        $directory = dirname(__DIR__, 4) . '/' . self::MARKER_DIRECTORY;
        self::assertTrue(is_dir($directory) || mkdir($directory, 0o775, true), 'cannot create ' . $directory);
        $json = json_encode(self::$markers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        self::assertNotFalse(file_put_contents($directory . '/' . self::MARKER_FILE, $json . "\n"), 'cannot write the markers');
    }
}
