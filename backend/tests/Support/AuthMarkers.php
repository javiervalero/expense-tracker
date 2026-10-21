<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The canonical markers of the auth integration tests, in `build/auth-test/markers.json` (one `"key": "value"` per
 * line), which `make test-integration` reads back to grep the api-php and nginx logs. Both auth tests write here, so a
 * write merges with what is already in the file, under an exclusive lock (the Makefile removes the directory at the
 * start of a run). Keep the key names in sync with the Makefile (`AUTH_MARKERS`).
 *
 * Every value must be safe for the Makefile's `sed` extraction and for JSON without escapes, so only characters of
 * {@see self::SAFE_VALUE} are accepted; anything else fails the test loudly.
 */
final class AuthMarkers
{
    private const string DIRECTORY = 'build/auth-test';
    private const string FILE = 'markers.json';
    private const string SAFE_KEY = '/\A[A-Za-z][A-Za-z0-9]*\z/';
    private const string SAFE_VALUE = '/\A[A-Za-z0-9._~+\/=:-]+\z/';

    /**
     * @param array<string, string> $markers
     */
    public static function write(array $markers): void
    {
        foreach ($markers as $key => $value) {
            Assert::assertMatchesRegularExpression(self::SAFE_KEY, $key, 'a marker key has characters the Makefile cannot read back');
            Assert::assertMatchesRegularExpression(self::SAFE_VALUE, $value, "the marker '$key' has characters the Makefile cannot read back");
        }
        $directory = dirname(__DIR__, 3) . '/' . self::DIRECTORY;
        Assert::assertTrue(is_dir($directory) || mkdir($directory, 0o775, true), 'cannot create ' . $directory);
        $handle = fopen($directory . '/' . self::FILE, 'c+');
        Assert::assertNotFalse($handle, 'cannot open the markers file');
        try {
            Assert::assertTrue(flock($handle, LOCK_EX), 'cannot lock the markers file');
            $current = stream_get_contents($handle);
            $existing = [];
            if ($current !== false && $current !== '') {
                $decoded = json_decode($current, true, 512, JSON_THROW_ON_ERROR);
                Assert::assertIsArray($decoded);
                /** @var array<string, string> $existing */
                $existing = $decoded;
            }
            $json = json_encode($markers + $existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
            Assert::assertTrue(ftruncate($handle, 0) && rewind($handle), 'cannot rewind the markers file');
            Assert::assertSame(strlen($json), fwrite($handle, $json), 'cannot write the markers');
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }
}
