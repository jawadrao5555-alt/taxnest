<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SystemSetting;
use App\Models\User;

final class SchoolErpPanel
{
    public const PREFIX = 'school-erp';

    public const GUARD = 'schoolerp';

    public const LOGIN_PATH = '/school-erp/login';

    public const DASHBOARD_PATH = '/school-erp/dashboard';

    public static function ownsCompany(?Company $company): bool
    {
        return $company
            && NestErps::isProductType($company->product_type)
            && $company->getAttribute(NestErps::VERTICAL_COLUMN) === NestErps::SCHOOL;
    }

    public static function maySignIn(User $user): bool
    {
        return $user->is_active && in_array($user->role, ['company_admin', 'employee', 'viewer'], true);
    }

    public static function registrationOpen(): bool
    {
        return SystemSetting::get(SystemSetting::ERP_SCHOOL_SIGNUP, config('school-erp.registration_open') ? '1' : '0') === '1';
    }
}
