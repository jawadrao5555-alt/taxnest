<?php
namespace App\Support\Hr;

use InvalidArgumentException;

final class BiometricEventKey
{
    /** Stable replay key; include tenant and device to prevent cross-company collision. */
    public static function make(int $organizationId, string $deviceSerial, string $deviceEventId): string
    {
        if ($organizationId <= 0 || trim($deviceSerial) === '' || trim($deviceEventId) === '') throw new InvalidArgumentException('Missing biometric event identity');
        return hash('sha256', $organizationId . ':' . strlen($deviceSerial) . ':' . $deviceSerial . ':' . $deviceEventId);
    }
}
