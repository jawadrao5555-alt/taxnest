<?php
namespace App\Support\Hr;

use DateTimeImmutable;

final class DutyLocationPolicy
{
    /** Never record off-duty location, including after checkout. Consent must be explicit and current. */
    public static function canCollect(DateTimeImmutable $now, DateTimeImmutable $dutyStart, DateTimeImmutable $dutyEnd, bool $consented, bool $osPermission, bool $checkedOut = false): bool
    {
        return $consented && $osPermission && !$checkedOut
            && $now->getTimestamp() >= $dutyStart->getTimestamp()
            && $now->getTimestamp() < $dutyEnd->getTimestamp();
    }

    public static function observationStatus(bool $locationAvailable, ?float $accuracyMeters, ?float $distanceMeters, float $radiusMeters): string
    {
        if (!$locationAvailable || $accuracyMeters === null || $distanceMeters === null) return 'unavailable';
        if ($accuracyMeters < 0 || $distanceMeters < 0 || $radiusMeters <= 0) return 'invalid';
        if ($accuracyMeters > $radiusMeters) return 'uncertain';
        return $distanceMeters <= $radiusMeters ? 'inside' : 'outside';
    }
}
