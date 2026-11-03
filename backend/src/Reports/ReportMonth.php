<?php

declare(strict_types=1);

namespace App\Reports;

use App\Expenses\ExpenseDate;
use DateTimeImmutable;
use LogicException;

/**
 * The month of a monthly report (D1, D3): the calendar month of the expenses' dates, exactly `YYYY-MM` with ASCII
 * digits, month 01 to 12, from the month of {@see ExpenseDate::MIN} to the month of {@see ExpenseDate::MAX}. It has no
 * zone and the server never uses "now": the client says which month it means.
 */
final readonly class ReportMonth
{
    private const string FORMAT = '/\A(\d{4})-(\d{2})\z/D';
    private const int TEXT_LENGTH = 7;

    private function __construct(private string $value) {}

    public static function parse(string $input): ?self
    {
        if (preg_match(self::FORMAT, $input, $parts) !== 1) {
            return null;
        }
        $month = (int) $parts[2];
        if ($month < 1 || $month > 12) {
            return null;
        }

        $inRange = $input >= substr(ExpenseDate::MIN, 0, self::TEXT_LENGTH) && $input <= substr(ExpenseDate::MAX, 0, self::TEXT_LENGTH);

        return $inRange ? new self($input) : null;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function firstDay(): ExpenseDate
    {
        return self::day($this->value . '-01');
    }

    public function lastDay(): ExpenseDate
    {
        $daysInMonth = (new DateTimeImmutable($this->value . '-01'))->format('t');

        return self::day($this->value . '-' . $daysInMonth);
    }

    private static function day(string $text): ExpenseDate
    {
        return ExpenseDate::parse($text) ?? throw new LogicException('A report month always has valid first and last days.');
    }
}
