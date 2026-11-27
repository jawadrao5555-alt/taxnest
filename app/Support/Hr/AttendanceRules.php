<?php
namespace App\Support\Hr;

use DateTimeImmutable;
use InvalidArgumentException;

final class AttendanceRules
{
    /** All times must be local timestamps with an explicit offset; shift start and end may cross midnight. */
    public static function classify(DateTimeImmutable $arrival, DateTimeImmutable $shiftStart, int $graceMinutes = 5, int $absentAfterMinutes = 30): array
    {
        if ($graceMinutes < 0 || $absentAfterMinutes < $graceMinutes) throw new InvalidArgumentException('Invalid attendance thresholds');
        $lateSeconds = $arrival->getTimestamp() - $shiftStart->getTimestamp();
        $lateMinutes = max(0, (int) ceil($lateSeconds / 60));
        $status = $lateSeconds <= $graceMinutes * 60 ? 'on_time' : ($lateSeconds <= $absentAfterMinutes * 60 ? 'late' : 'absent_cutoff');
        return ['status' => $status, 'late_minutes' => $lateMinutes, 'arrival_recorded' => true];
    }

    public static function canCheckout(DateTimeImmutable $checkout, DateTimeImmutable $shiftEnd): bool
    {
        return $checkout->getTimestamp() >= $shiftEnd->getTimestamp();
    }
}
