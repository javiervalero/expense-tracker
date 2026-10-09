<?php

declare(strict_types=1);

/**
 * Fails when the line coverage in a PHPUnit Clover report is below a threshold.
 *
 * Usage: php scripts/check-coverage.php [--allow-empty] <clover.xml> <threshold-percent>
 *
 * Exit codes: 0 = passed (or skipped), 1 = coverage below the threshold, 2 = bad usage or unreadable report.
 *
 * The figure is the project-level `coveredstatements / statements` of the report. PHPUnit only measures
 * the files under the `<source>` of phpunit.xml.dist (backend/src), so test code does not count.
 *
 * Strict by default: a report with no executable statements at all (`statements="0"`) exits 1 ("no
 * executable statements measured"), because an empty measurement usually means coverage was not collected
 * (wrong source path, stale or truncated report) and must not pass silently. `--allow-empty` turns that
 * single case into a pass (exit 0, "skipped: no executable statements"); callers use it only when they
 * know src/ legitimately holds no PHP yet. The threshold is never relaxed when statements exist.
 */

namespace Scripts;

final readonly class CoverageOutcome
{
    public function __construct(
        public int $exitCode,
        public string $message,
    ) {}
}

final class CoverageCheck
{
    private const USAGE = 'Usage: php scripts/check-coverage.php [--allow-empty] <clover.xml> <threshold-percent 0-100>';

    /**
     * @param list<string> $arguments report path and threshold, without the script name
     */
    public static function fromArguments(array $arguments): CoverageOutcome
    {
        $allowEmpty = in_array('--allow-empty', $arguments, true);
        $arguments = array_values(array_filter($arguments, static fn(string $argument): bool => $argument !== '--allow-empty'));
        if (count($arguments) !== 2) {
            return new CoverageOutcome(2, self::USAGE);
        }
        [$path, $thresholdText] = $arguments;
        if (!is_numeric($thresholdText) || (float) $thresholdText < 0 || (float) $thresholdText > 100) {
            return new CoverageOutcome(2, self::USAGE . "\nInvalid threshold: '{$thresholdText}'");
        }

        $xml = is_file($path) ? file_get_contents($path) : false;
        if ($xml === false) {
            return new CoverageOutcome(2, "Cannot read coverage report: {$path}");
        }

        return self::evaluate($xml, (float) $thresholdText, $allowEmpty);
    }

    private static function evaluate(string $xml, float $threshold, bool $allowEmpty): CoverageOutcome
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            return new CoverageOutcome(2, 'Coverage report is not valid XML.');
        }

        $metrics = $document->project->metrics ?? null;
        if ($metrics === null || !isset($metrics['statements'], $metrics['coveredstatements'])) {
            return new CoverageOutcome(2, 'Coverage report has no project metrics (statements, coveredstatements).');
        }
        $total = (string) $metrics['statements'];
        $covered = (string) $metrics['coveredstatements'];
        if (!ctype_digit($total) || !ctype_digit($covered) || (int) $covered > (int) $total) {
            return new CoverageOutcome(2, "Coverage report has inconsistent metrics: {$covered} of {$total}.");
        }

        return self::judge((int) $covered, (int) $total, $threshold, $allowEmpty);
    }

    private static function judge(int $covered, int $total, float $threshold, bool $allowEmpty): CoverageOutcome
    {
        if ($total === 0) {
            return $allowEmpty
                ? new CoverageOutcome(0, 'Coverage check skipped: no executable statements (--allow-empty).')
                : new CoverageOutcome(1, 'Coverage check FAILED: no executable statements measured (pass --allow-empty only if the source is legitimately empty).');
        }

        $percent = $covered / $total * 100;
        $summary = sprintf('Line coverage %.2f%% (%d/%d), threshold %s%%', $percent, $covered, $total, self::trim($threshold));
        if ($percent < $threshold) {
            return new CoverageOutcome(1, $summary . ': FAILED, below the threshold.');
        }

        return new CoverageOutcome(0, $summary . ': OK.');
    }

    private static function trim(float $number): string
    {
        return rtrim(rtrim(sprintf('%.2f', $number), '0'), '.');
    }
}

// Run only when executed directly (php scripts/check-coverage.php), not when required by the unit test.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $outcome = CoverageCheck::fromArguments(array_slice($argv, 1));
    fwrite($outcome->exitCode === 0 ? STDOUT : STDERR, $outcome->message . "\n");
    exit($outcome->exitCode);
}
