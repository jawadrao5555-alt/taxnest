<?php
namespace App\Support\Hr;

final class TenantAccess
{
    /** No access is inferred from matching email, CNIC, phone or TaxNest company grouping. */
    public static function allows(int $requestedOrganizationId, int $membershipOrganizationId, string $role, string $capability, bool $active = true): bool
    {
        if (!$active || $requestedOrganizationId <= 0 || $requestedOrganizationId !== $membershipOrganizationId) return false;
        $grants = [
            'owner' => ['*'],
            'hr_admin' => ['employee.read','employee.write','policy.read','policy.write','attendance.read','attendance.approve','leave.approve','payroll.read','payroll.approve'],
            'manager' => ['employee.read','attendance.read','leave.approve'],
            'employee' => ['self.read','self.attendance','self.leave','self.location'],
        ];
        $allowed = $grants[$role] ?? [];
        return in_array('*', $allowed, true) || in_array($capability, $allowed, true);
    }
}
