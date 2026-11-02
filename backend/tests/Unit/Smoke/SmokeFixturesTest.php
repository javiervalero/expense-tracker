<?php

declare(strict_types=1);

namespace App\Tests\Unit\Smoke;

use App\Receipts\FailureReason;
use App\Receipts\Processing\FileInspector;
use App\Receipts\ReceiptContentType;
use PHPUnit\Framework\TestCase;

/**
 * The two files scripts/smoke.sh uploads (a 1x1 PNG and a short text declared as PNG) are literals in the script. This
 * test pulls them out of the script and runs them through the worker's own FileInspector, so a tightened worker rule
 * fails here, in `make test-unit`, and not in a smoke run against AWS (plan phase 6, D20).
 */
final class SmokeFixturesTest extends TestCase
{
    private const string SCRIPT = '/scripts/smoke.sh';

    public function testTheSmokePngIsAcceptedAsAPng(): void
    {
        $png = base64_decode($this->literal('PNG_BASE64', '[A-Za-z0-9+\/=]+'), true);
        self::assertIsString($png, 'PNG_BASE64 is valid base64');

        $inspection = (new FileInspector())->inspect($png, ReceiptContentType::Png);

        self::assertTrue($inspection->isAccepted());
        self::assertSame(ReceiptContentType::Png, $inspection->type);
        self::assertSame(1, $inspection->width);
        self::assertSame(1, $inspection->height);
    }

    public function testTheSmokeTextDeclaredAsPngIsRefusedAsUnsupportedType(): void
    {
        $text = $this->literal('INVALID_TEXT', "[ -&(-~]+");

        $inspection = (new FileInspector())->inspect($text, ReceiptContentType::Png);

        self::assertFalse($inspection->isAccepted());
        self::assertSame(FailureReason::UnsupportedType, $inspection->failure);
    }

    public function testTheSmokeTextIsShortPlainAscii(): void
    {
        $text = $this->literal('INVALID_TEXT', "[ -&(-~]+");

        self::assertStringNotContainsString("'", $text, 'a quote would end the shell literal early');
        self::assertLessThanOrEqual(64, strlen($text));
        self::assertGreaterThanOrEqual(10, strlen($text));
    }

    private function literal(string $name, string $characters): string
    {
        $script = file_get_contents(dirname(__DIR__, 4) . self::SCRIPT);
        self::assertIsString($script, 'scripts/smoke.sh exists');
        self::assertSame(
            1,
            preg_match_all('/^readonly ' . $name . "='/m", $script),
            $name . ' is assigned once, as readonly NAME=\'literal\' on its own line',
        );
        self::assertSame(1, preg_match('/^readonly ' . $name . "='(" . $characters . ")'$/m", $script, $match), $name . ' has the expected shape');

        return $match[1];
    }
}
