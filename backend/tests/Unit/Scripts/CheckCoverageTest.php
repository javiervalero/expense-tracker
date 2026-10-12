<?php

declare(strict_types=1);

namespace App\Tests\Unit\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scripts\CoverageCheck;

require_once dirname(__DIR__, 4) . '/scripts/check-coverage.php';

final class CheckCoverageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/coverage-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testPassesWhenCoverageIsAboveTheThreshold(): void
    {
        $outcome = CoverageCheck::fromArguments([$this->clover(90, 100), '80']);

        self::assertSame(0, $outcome->exitCode);
        self::assertStringContainsString('90.00%', $outcome->message);
        self::assertStringContainsString('90/100', $outcome->message);
    }

    public function testPassesWhenCoverageEqualsTheThresholdExactly(): void
    {
        self::assertSame(0, CoverageCheck::fromArguments([$this->clover(80, 100), '80'])->exitCode);
    }

    public function testFailsJustBelowTheThreshold(): void
    {
        $outcome = CoverageCheck::fromArguments([$this->clover(79, 100), '80']);

        self::assertSame(1, $outcome->exitCode);
        self::assertStringContainsString('79.00%', $outcome->message);
        self::assertStringContainsString('below', $outcome->message);
    }

    public function testFailsWhenNothingIsCovered(): void
    {
        self::assertSame(1, CoverageCheck::fromArguments([$this->clover(0, 50), '80'])->exitCode);
    }

    public function testFailsByDefaultWhenThereAreNoExecutableStatements(): void
    {
        $outcome = CoverageCheck::fromArguments([$this->clover(0, 0), '80']);

        self::assertSame(1, $outcome->exitCode);
        self::assertStringContainsString('no executable statements measured', $outcome->message);
    }

    public function testAllowEmptyPermitsAReportWithoutExecutableStatements(): void
    {
        $outcome = CoverageCheck::fromArguments(['--allow-empty', $this->clover(0, 0), '80']);

        self::assertSame(0, $outcome->exitCode);
        self::assertStringContainsString('skipped: no executable statements', $outcome->message);
    }

    public function testAllowEmptyDoesNotRelaxTheThresholdWhenStatementsExist(): void
    {
        self::assertSame(1, CoverageCheck::fromArguments([$this->clover(10, 100), '80', '--allow-empty'])->exitCode);
        self::assertSame(0, CoverageCheck::fromArguments([$this->clover(90, 100), '80', '--allow-empty'])->exitCode);
    }

    public function testCommandLineFailsOnAnEmptyReportUnlessAllowed(): void
    {
        $script = dirname(__DIR__, 4) . '/scripts/check-coverage.php';
        $empty = $this->clover(0, 0);

        [$strictCode, $strictOutput] = $this->runScript($script, [$empty, '80']);
        [$allowedCode, $allowedOutput] = $this->runScript($script, ['--allow-empty', $empty, '80']);

        self::assertSame(1, $strictCode);
        self::assertStringContainsString('no executable statements measured', $strictOutput);
        self::assertSame(0, $allowedCode);
        self::assertStringContainsString('skipped: no executable statements', $allowedOutput);
    }

    public function testAcceptsDecimalThresholds(): void
    {
        self::assertSame(0, CoverageCheck::fromArguments([$this->clover(805, 1000), '80.5'])->exitCode);
        self::assertSame(1, CoverageCheck::fromArguments([$this->clover(804, 1000), '80.5'])->exitCode);
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('badArguments')]
    public function testRejectsBadUsage(array $arguments): void
    {
        $outcome = CoverageCheck::fromArguments($arguments);

        self::assertSame(2, $outcome->exitCode);
        self::assertStringContainsString('Usage', $outcome->message);
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function badArguments(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'missing threshold' => [['clover.xml']];
        yield 'extra argument' => [['clover.xml', '80', 'x']];
        yield 'non numeric threshold' => [['clover.xml', 'eighty']];
        yield 'negative threshold' => [['clover.xml', '-1']];
        yield 'threshold above 100' => [['clover.xml', '100.1']];
        yield 'empty threshold' => [['clover.xml', '']];
    }

    public function testReportsAMissingReport(): void
    {
        $outcome = CoverageCheck::fromArguments([$this->directory . '/nope.xml', '80']);

        self::assertSame(2, $outcome->exitCode);
        self::assertStringContainsString('Cannot read', $outcome->message);
    }

    public function testReportsMalformedXml(): void
    {
        $path = $this->write('broken.xml', '<coverage><project>');

        $outcome = CoverageCheck::fromArguments([$path, '80']);

        self::assertSame(2, $outcome->exitCode);
        self::assertStringContainsString('not valid XML', $outcome->message);
    }

    public function testReportsXmlWithoutProjectMetrics(): void
    {
        $path = $this->write('empty.xml', '<coverage><project/></coverage>');

        $outcome = CoverageCheck::fromArguments([$path, '80']);

        self::assertSame(2, $outcome->exitCode);
        self::assertStringContainsString('project metrics', $outcome->message);
    }

    public function testReportsNonNumericMetrics(): void
    {
        $path = $this->write('nan.xml', '<coverage><project><metrics statements="x" coveredstatements="1"/></project></coverage>');

        self::assertSame(2, CoverageCheck::fromArguments([$path, '80'])->exitCode);
    }

    public function testReportsMoreCoveredThanTotalStatements(): void
    {
        self::assertSame(2, CoverageCheck::fromArguments([$this->clover(11, 10), '80'])->exitCode);
    }

    public function testCommandLineExitCodesMatchTheOutcome(): void
    {
        $script = dirname(__DIR__, 4) . '/scripts/check-coverage.php';

        [$okCode, $okOutput] = $this->runScript($script, [$this->clover(85, 100), '80']);
        [$failCode, $failOutput] = $this->runScript($script, [$this->clover(10, 100), '80']);
        [$usageCode] = $this->runScript($script);

        self::assertSame(0, $okCode);
        self::assertStringContainsString('85.00%', $okOutput);
        self::assertSame(1, $failCode);
        self::assertStringContainsString('10.00%', $failOutput);
        self::assertSame(2, $usageCode);
    }

    private function clover(int $covered, int $total): string
    {
        $xml = sprintf(
            '<?xml version="1.0"?><coverage generated="0"><project timestamp="0"><metrics files="1" statements="%d" coveredstatements="%d"/></project></coverage>',
            $total,
            $covered,
        );

        return $this->write("clover-{$covered}-{$total}.xml", $xml);
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->directory . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string}
     */
    private function runScript(string $script, array $arguments = []): array
    {
        $command = [PHP_BINARY, $script, ...$arguments];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output];
    }
}
