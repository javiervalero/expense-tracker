<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\ForbiddenReference;
use App\Tests\Support\ForbiddenReferenceScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ADR 0012: the Bref runtime is confined to the Lambda entrypoints and the Docker/Terraform
 * configuration. Application code in src/ and public/ must not import Bref classes or read
 * Lambda-only environment variables, so it runs unchanged under php-fpm, the local worker and Lambda.
 */
final class BrefBoundaryTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    public function testCleanFixtureHasNoForbiddenReferences(): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanDirectory(self::FIXTURES . '/clean');

        self::assertSame([], $this->describe($hits));
    }

    /**
     * @param list<array{int, string}> $expected line and rule of every leak in the fixture
     */
    #[DataProvider('leakyFixtures')]
    public function testLeakyFixtureIsFlaggedWithFileAndLine(string $file, array $expected): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanFile(self::FIXTURES . '/leaky/' . $file);

        self::assertSame($expected, array_map(
            static fn(ForbiddenReference $hit): array => [$hit->line, $hit->rule],
            $hits,
        ));
        foreach ($hits as $hit) {
            self::assertSame(self::FIXTURES . '/leaky/' . $file, $hit->file);
        }
    }

    /**
     * @return iterable<string, array{string, list<array{int, string}>}>
     */
    public static function leakyFixtures(): iterable
    {
        yield 'use statements' => ['UseStatement.php', [
            [7, ForbiddenReferenceScanner::RULE_CLASS],
            [8, ForbiddenReferenceScanner::RULE_CLASS],
        ]];
        yield 'fully qualified class' => ['FullyQualified.php', [
            [11, ForbiddenReferenceScanner::RULE_CLASS],
        ]];
        yield 'group use' => ['GroupUse.php', [
            [7, ForbiddenReferenceScanner::RULE_CLASS],
        ]];
        yield 'lambda environment' => ['EnvAccess.php', [
            [11, ForbiddenReferenceScanner::RULE_ENV],
            [12, ForbiddenReferenceScanner::RULE_ENV],
            [13, ForbiddenReferenceScanner::RULE_ENV],
            [14, ForbiddenReferenceScanner::RULE_ENV],
            [15, ForbiddenReferenceScanner::RULE_ENV],
        ]];
        yield 'docblock type' => ['Docblock.php', [
            [10, ForbiddenReferenceScanner::RULE_CLASS],
        ]];
        yield 'class name in a string' => ['StringClassName.php', [
            [9, ForbiddenReferenceScanner::RULE_CLASS],
        ]];
    }

    public function testLeakyFixtureDirectoryFailsTheBoundaryCheck(): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanDirectory(self::FIXTURES . '/leaky');

        self::assertNotSame([], $hits);
        self::assertCount($this->expectedLeakCount(), $hits);
    }

    /**
     * entrypoints/ is the Bref edge (ADR 0001, ADR 0012): it is allowed to use Bref, so it is deliberately
     * not scanned. If it is ever added to the guarded list, this test must be changed on purpose.
     */
    public function testEntrypointsDirectoryIsDeliberatelyNotGuarded(): void
    {
        $guarded = array_map(static fn(array $case): string => $case[0], iterator_to_array(self::guardedDirectories()));

        self::assertSame(['src', 'public'], array_values($guarded));
        self::assertNotContains('entrypoints', $guarded);
    }

    private function expectedLeakCount(): int
    {
        return array_sum(array_map(
            static fn(array $case): int => count($case[1]),
            iterator_to_array(self::leakyFixtures()),
        ));
    }

    #[DataProvider('guardedDirectories')]
    public function testApplicationCodeDoesNotReferenceBrefOrLambdaEnvironment(string $directory): void
    {
        $hits = (new ForbiddenReferenceScanner())->scanDirectory(dirname(__DIR__, 2) . '/' . $directory);

        self::assertSame([], $this->describe($hits), "Bref boundary violated in {$directory}/ (ADR 0012)");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function guardedDirectories(): iterable
    {
        yield 'src' => ['src'];
        yield 'public' => ['public'];
    }

    /**
     * @param list<ForbiddenReference> $hits
     *
     * @return list<string>
     */
    private function describe(array $hits): array
    {
        return array_map(static fn(ForbiddenReference $hit): string => (string) $hit, $hits);
    }
}
