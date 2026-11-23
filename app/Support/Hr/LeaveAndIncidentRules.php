<?php
namespace App\Support\Hr;

use InvalidArgumentException;

final class LeaveAndIncidentRules
{
    public static function remaining(float $quota, float $approvedUsed): float
    {
        if ($quota < 0 || $approvedUsed < 0) throw new InvalidArgumentException('Invalid leave balance');
        return max(0, $quota - $approvedUsed);
    }

    /** Incidents require an explanation opportunity and a human decision; never auto-fine. */
    public static function canFinalizeFine(bool $explanationOpportunityGiven, bool $adminApproved, bool $legallyPermitted, float $amount): bool
    {
        return $explanationOpportunityGiven && $adminApproved && $legallyPermitted && $amount > 0;
    }
}
