<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Tests\Support\ForbiddenReference;
use App\Tests\Support\ForbiddenReferenceScanner;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ForbiddenReferenceScannerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/scanner-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    /**
     * @param list<array{int, string, string}> $expected line, rule and matched text
     */
    #[DataProvider('leaks')]
    public function testFlagsLeakWithLineRuleAndMatch(string $code, array $expected): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanSource("<?php\n" . $code, 'sample.php');

        self::assertSame($expected, array_map(
            static fn(ForbiddenReference $hit): array => [$hit->line, $hit->rule, $hit->match],
            $hits,
        ));
    }

    /**
     * @return iterable<string, array{string, list<array{int, string, string}>}>
     */
    public static function leaks(): iterable
    {
        $class = ForbiddenReferenceScanner::RULE_CLASS;
        $env = ForbiddenReferenceScanner::RULE_ENV;

        yield 'use statement' => ["use Bref\\Context\\Context;\n", [[2, $class, 'Bref\\Context\\Context']]];
        yield 'use function' => ["use function Bref\\helper;\n", [[2, $class, 'Bref\\helper']]];
        yield 'group use' => ["use Bref\\{A, B};\n", [[2, $class, 'Bref\\']]];
        yield 'fully qualified' => ["\$a = new \\Bref\\Context();\n", [[2, $class, '\\Bref\\Context']]];
        yield 'static call' => ["Bref\\Runtime::boot();\n", [[2, $class, 'Bref\\Runtime']]];
        yield 'namespace declaration' => ["namespace Bref\\Mine;\n", [[2, $class, 'Bref\\Mine']]];
        yield 'class name is case insensitive' => ["use BREF\\Context;\n", [[2, $class, 'BREF\\Context']]];
        yield 'string class name' => ["\$c = 'Bref\\\\Context\\\\Context';\n", [[2, $class, 'Bref\\\\']]];
        yield 'string class name, single backslash' => ["\$c = 'Bref\\Context';\n", [[2, $class, 'Bref\\']]];
        yield 'docblock type' => ["/**\n * @param \\Bref\\Context\\Context \$c\n */\n", [[3, $class, '\\Bref\\']]];
        yield 'getenv' => ["getenv('AWS_LAMBDA_FUNCTION_NAME');\n", [[2, $env, 'AWS_LAMBDA_FUNCTION_NAME']]];
        yield 'server superglobal' => ["\$_SERVER['AWS_LAMBDA_RUNTIME_API'];\n", [[2, $env, 'AWS_LAMBDA_RUNTIME_API']]];
        yield 'env superglobal' => ["\$_ENV['AWS_LAMBDA_FUNCTION_VERSION'];\n", [[2, $env, 'AWS_LAMBDA_FUNCTION_VERSION']]];
        yield 'any call with the name' => ["isset(\$x) && array_key_exists('AWS_LAMBDA_X', \$x);\n", [[2, $env, 'AWS_LAMBDA_X']]];
        yield 'interpolated string' => ["\$v = \"AWS_LAMBDA_LOG_\$name\";\n", [[2, $env, 'AWS_LAMBDA_LOG_']]];
        yield 'heredoc, line inside the body' => ["\$v = <<<TXT\nfirst\nAWS_LAMBDA_FUNCTION_NAME\nTXT;\n", [[4, $env, 'AWS_LAMBDA_FUNCTION_NAME']]];
        yield 'multi-line string, line inside' => ["\$v = 'a\nb AWS_LAMBDA_X c';\n", [[3, $env, 'AWS_LAMBDA_X']]];
        yield 'task root variable' => ["getenv('LAMBDA_TASK_ROOT');\n", [[2, $env, 'LAMBDA_TASK_ROOT']]];
        yield 'runtime dir variable' => ["getenv('LAMBDA_RUNTIME_DIR');\n", [[2, $env, 'LAMBDA_RUNTIME_DIR']]];
        yield 'bref variable' => ["getenv('BREF_RUNTIME');\n", [[2, $env, 'BREF_RUNTIME']]];
        yield 'two leaks on one line, in order' => [
            "\$a = [new \\Bref\\A(), getenv('BREF_PING_DISABLE')];\n",
            [[2, $class, '\\Bref\\A'], [2, $env, 'BREF_PING_DISABLE']],
        ];
        yield 'later line numbers are kept' => [
            "\n\nuse Bref\\X;\n\n\$a = getenv('AWS_LAMBDA_Y');\n",
            [[4, $class, 'Bref\\X'], [6, $env, 'AWS_LAMBDA_Y']],
        ];
    }

    #[DataProvider('innocentCode')]
    public function testIgnoresCodeThatIsNotALeak(string $code): void
    {
        self::assertSame([], (new ForbiddenReferenceScanner())->scanSource("<?php\n" . $code));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function innocentCode(): iterable
    {
        yield 'own namespace containing the word' => ["use App\\Bref\\Thing;\n"];
        yield 'own fully qualified class' => ["\$a = \\App\\Bref\\Thing::class;\n"];
        yield 'relative name' => ["\$a = namespace\\Bref\\Thing::class;\n"];
        yield 'bare identifier' => ["use Bref;\n\$a = Bref::X;\n"];
        yield 'similar identifier' => ["use Brefs\\Thing;\nconst BREFFER = 1;\n"];
        yield 'plain comment' => ["// Bref\\Context and AWS_LAMBDA_X are fine in comments\n"];
        yield 'block comment' => ["/* getenv('AWS_LAMBDA_X') */\n"];
        yield 'docblock prose without a namespace' => ["/** Runs on Bref and on AWS_LAMBDA_FUNCTION_NAME-less hosts. */\n"];
        yield 'string in own namespace' => ["\$c = 'App\\\\Bref\\\\Thing';\n"];
        yield 'prefixed variable name' => ["\$a = 'MY_AWS_LAMBDA_X';\n"];
        yield 'suffixed variable name' => ["\$a = 'XBREF_RUNTIME';\n"];
        yield 'other environment' => ["getenv('APP_ENV'); \$_SERVER['REQUEST_METHOD'];\n"];
        yield 'word Bref inside a string' => ["\$a = 'Bref is a library';\n"];
        yield 'inline html' => ["?>AWS_LAMBDA_X Bref\\Y<?php\n"];
    }

    public function testEmptyAndNonPhpSourcesHaveNoHits(): void
    {
        $scanner = new ForbiddenReferenceScanner();

        self::assertSame([], $scanner->scanSource(''));
        self::assertSame([], $scanner->scanSource('plain text with AWS_LAMBDA_X'));
    }

    public function testViolationCarriesFileAndFormatsAsFileLineAndRule(): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanSource("<?php\nuse Bref\\X;\n", 'src/A.php');

        self::assertCount(1, $hits);
        self::assertSame('src/A.php', $hits[0]->file);
        self::assertSame('src/A.php:2: ' . ForbiddenReferenceScanner::RULE_CLASS . ' (Bref\\X)', (string) $hits[0]);
    }

    public function testScanFileReadsTheFileAndReportsItsPath(): void
    {
        $path = $this->directory . '/Leak.php';
        file_put_contents($path, "<?php\n\nuse Bref\\X;\n");

        $hits = (new ForbiddenReferenceScanner())->scanFile($path);

        self::assertCount(1, $hits);
        self::assertSame($path, $hits[0]->file);
        self::assertSame(3, $hits[0]->line);
    }

    public function testScanFileRejectsMissingFile(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ForbiddenReferenceScanner())->scanFile($this->directory . '/missing.php');
    }

    public function testScanDirectoryRejectsMissingDirectory(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ForbiddenReferenceScanner())->scanDirectory($this->directory . '/missing');
    }

    public function testScanDirectoryRecursesSortsByPathAndOnlyReadsPhpFiles(): void
    {
        mkdir($this->directory . '/b/deep', 0o777, true);
        mkdir($this->directory . '/a');
        file_put_contents($this->directory . '/b/deep/Z.php', "<?php\nuse Bref\\Z;\n");
        file_put_contents($this->directory . '/a/A.php', "<?php\nuse Bref\\A;\n");
        file_put_contents($this->directory . '/a/notes.txt', 'use Bref\\Ignored;');
        file_put_contents($this->directory . '/a/.gitkeep', '');

        $hits = (new ForbiddenReferenceScanner())->scanDirectory($this->directory);

        self::assertSame(
            [$this->directory . '/a/A.php', $this->directory . '/b/deep/Z.php'],
            array_map(static fn(ForbiddenReference $hit): string => $hit->file, $hits),
        );
    }

    public function testScanDirectoryOfEmptyDirectoryHasNoHits(): void
    {
        self::assertSame([], (new ForbiddenReferenceScanner())->scanDirectory($this->directory));
    }

    public function testHandlesLargeFilesQuickly(): void
    {
        $code = "<?php\n" . str_repeat("\$a = getenv('APP_ENV') . 'x';\n", 20000) . "use Bref\\Last;\n";

        $hits = (new ForbiddenReferenceScanner())->scanSource($code);

        self::assertCount(1, $hits);
        self::assertSame(20002, $hits[0]->line);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
