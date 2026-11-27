<?php
namespace App\Support\Hr;

use InvalidArgumentException;

final class PayrollRules
{
    /** Pure arithmetic only; statutory deductions and lawful pay basis require separate configuration. */
    public static function net(float $basic, float $allowances, float $overtime, float $approvedDeductions, float $bonus = 0): float
    {
        foreach ([$basic, $allowances, $overtime, $approvedDeductions, $bonus] as $value) {
            if (!is_finite($value) || $value < 0) throw new InvalidArgumentException('Negative or invalid payroll amount');
        }
        return round($basic + $allowances + $overtime + $bonus - $approvedDeductions, 2);
    }

    public static function perfectAttendanceBonusEligible(int $expectedDays, int $verifiedDays, int $late, int $absent, int $leaveUsed, int $unauthorizedExits): bool
    {
        return $expectedDays > 0 && $verifiedDays === $expectedDays && $late === 0 && $absent === 0 && $leaveUsed === 0 && $unauthorizedExits === 0;
    }
}
