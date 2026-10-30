<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Receipts\OriginalObjects;
use App\Receipts\ThumbnailObjects;
use App\Tests\Architecture\Support\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5 (C13): nothing the worker runs can put an object into the receipts bucket. The originals arrive only by the
 * browser's presigned POST; the worker may read and delete them, and write thumbnails to the other bucket.
 *
 * In `src/Receipts/Processing` and `src/Worker` (the worker's code) it tokenises with PhpToken and flags:
 *  - any name or string that is an SDK or store operation able to write (`putObject`, `upload`, `copy`, `copyObject`,
 *    `createMultipartUpload`, `getCommand`, in any case), so a raw client call cannot be added;
 *  - any reference to the raw store or client classes (`ObjectStore`, `S3ObjectStore`, `S3Client`, `S3ClientFactory`)
 *    outside `Worker/WorkerFactory.php`, which builds the two ports and hands out nothing else, so only the ports reach
 *    the processor;
 *  - a call of a method named `put` whose receiver is not `thumbnails` or `$thumbnails` (the thumbnails port).
 * The ports themselves are pinned too: the originals port has no way to write, the thumbnails port none to read.
 * Not caught: a name built at run time; review catches that. On AWS, IAM enforces the same rule (Phase 7).
 */
final class ReceiptsBucketWriteTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures/receiptstoragewrite';
    private const WORKER_DIRECTORIES = ['Receipts/Processing', 'Worker'];
    /** The one file that may name the raw clients, because it builds the ports from them. */
    private const COMPOSITION_ROOT = 'Worker/WorkerFactory.php';
    private const WRITE_OPERATIONS = ['putobject', 'putobjectasync', 'upload', 'uploadasync', 'copy', 'copyobject', 'createmultipartupload', 'getcommand'];
    private const RAW_STORE_CLASSES = ['objectstore', 's3objectstore', 's3client', 's3clientfactory'];

    public function testCleanFixtureHasNoHit(): void
    {
        self::assertSame([], $this->scanFile(self::FIXTURES . '/clean/Clean.php', false));
    }

    public function testLeakyFixtureIsFlaggedWithFileAndLine(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        self::assertSame([
            $file . ':7: reference to Aws\S3\S3Client',
            $file . ':11: reference to S3Client',
            $file . ':13: putObject',
            $file . ':14: put( on a receiver other than the thumbnails port',
            $file . ':15: upload',
            $file . ":16: 'copyObject'",
            $file . ':17: getCommand',
            $file . ":17: 'PutObject'",
            $file . ":18: 'put'",
            $file . ":19: reference to 'Aws\\\\S3\\\\S3Client'",
            $file . ":20: reference to 'ObjectStore'",
            $file . ":21: 's3://receipts/key'",
        ], $this->scanFile($file, false));
    }

    public function testTheCompositionRootMayNameTheRawClientsButStillNotWrite(): void
    {
        $file = self::FIXTURES . '/leaky/Leaky.php';

        $hits = $this->scanFile($file, true);

        self::assertNotContains($file . ':7: reference to Aws\S3\S3Client', $hits);
        self::assertNotContains($file . ":20: reference to 'ObjectStore'", $hits);
        self::assertContains($file . ':13: putObject', $hits);
        self::assertContains($file . ":18: 'put'", $hits);
        self::assertContains($file . ":21: 's3://receipts/key'", $hits);
    }

    public function testTheWorkerDirectoriesExist(): void
    {
        foreach (self::WORKER_DIRECTORIES as $directory) {
            self::assertDirectoryExists(dirname(__DIR__, 2) . '/src/' . $directory);
        }
        self::assertFileExists(dirname(__DIR__, 2) . '/src/' . self::COMPOSITION_ROOT);
    }

    public function testNothingTheWorkerRunsCanWriteToTheReceiptsBucket(): void
    {
        $root = dirname(__DIR__, 2) . '/src';
        $hits = [];
        foreach (self::WORKER_DIRECTORIES as $directory) {
            foreach (TokenScanner::phpFiles($root . '/' . $directory) as $path) {
                $hits = [...$hits, ...$this->scanFile($path, $path === $root . '/' . self::COMPOSITION_ROOT)];
            }
        }

        self::assertSame([], $hits, 'The worker may only write thumbnails, through the ThumbnailObjects port.');
    }

    public function testTheOriginalsPortCannotWriteAndTheThumbnailsPortCannotRead(): void
    {
        self::assertSame(['fetch', 'etag', 'delete', 'deleteExpense'], array_map(static fn(\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(OriginalObjects::class))->getMethods()));
        self::assertSame(['put', 'delete', 'deleteExpense'], array_map(static fn(\ReflectionMethod $m): string => $m->getName(), (new \ReflectionClass(ThumbnailObjects::class))->getMethods()));
    }

    /**
     * @return list<string> "file:line: label" for each hit, in source order
     */
    private function scanFile(string $path, bool $mayNameRawClients): array
    {
        $tokens = TokenScanner::tokens($path);
        $hits = [];
        foreach ($tokens as $index => $token) {
            $label = $this->label($tokens, $index, $mayNameRawClients);
            if ($label !== null) {
                $hits[] = "{$path}:{$token->line}: {$label}";
            }
        }

        return $hits;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function label(array $tokens, int $index, bool $mayNameRawClients): ?string
    {
        $token = $tokens[$index];
        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            return $this->stringLabel($token->text, $mayNameRawClients);
        }
        if (!$token->is(TokenScanner::NAME_TOKENS)) {
            return null;
        }
        $name = TokenScanner::lastSegment($token->text);
        if (in_array($name, self::WRITE_OPERATIONS, true)) {
            return $token->text;
        }
        if (!$mayNameRawClients && in_array($name, self::RAW_STORE_CLASSES, true)) {
            return 'reference to ' . $token->text;
        }

        return $name === 'put' && $this->isCall($tokens, $index) && !$this->receiverIsThumbnails($tokens, $index)
            ? 'put( on a receiver other than the thumbnails port'
            : null;
    }

    /**
     * A string can name what a call cannot show: a write operation, `put` as a callable (`[$store, 'put']`), a raw store
     * or client class (also fully qualified, as `Aws\S3\S3Client`), or an `s3://` URL for the stream wrapper.
     */
    private function stringLabel(string $literal, bool $mayNameRawClients): ?string
    {
        $value = strtolower(trim($literal, '\'"'));
        $isRawClass = in_array(TokenScanner::lastSegment($value), self::RAW_STORE_CLASSES, true);
        $flagged = in_array($value, self::WRITE_OPERATIONS, true)
            || $value === 'put'
            || str_contains($value, 's3://')
            || (!$mayNameRawClients && $isRawClass);

        if (!$flagged) {
            return null;
        }

        return $isRawClass ? 'reference to ' . $literal : $literal;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isCall(array $tokens, int $index): bool
    {
        return TokenScanner::neighbour($tokens, $index, -1)?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON]) === true
            && TokenScanner::neighbour($tokens, $index, 1)?->text === '(';
    }

    /**
     * @param list<\PhpToken> $tokens the receiver is the name before the arrow: `->thumbnails->put(` or `$thumbnails->put(`
     */
    private function receiverIsThumbnails(array $tokens, int $index): bool
    {
        $arrow = $index - 1;
        while (isset($tokens[$arrow]) && $tokens[$arrow]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            --$arrow;
        }
        $receiver = TokenScanner::neighbour($tokens, $arrow, -1);

        return $receiver !== null && in_array($receiver->text, ['thumbnails', '$thumbnails'], true);
    }
}
