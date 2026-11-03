<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PosDiSandboxProfile;
use Illuminate\Support\Facades\DB;

final class PraDiSandboxGate
{
    public static function available(?int $companyId = null): bool
    {
        if (self::localAvailable()) {
            if (!$companyId) return true;
            $profile = PosDiSandboxProfile::where('company_id', $companyId)->first();
            return $profile ? $profile->enabled : true;
        }
        if (!$companyId || !self::hostedAvailable()) return false;

        return Company::whereKey($companyId)->where('product_type', 'pos')
            ->where('status', 'approved')->where('company_status', 'active')->exists()
            && PosDiSandboxProfile::where('company_id', $companyId)->where('enabled', true)->exists();
    }

    public static function hostedAvailable(): bool
    {
        return config('pra_di_sandbox.hosted_enabled', false)
            && app()->environment(['production', 'staging']);
    }

    public static function setupAvailable(): bool
    {
        return self::localAvailable() || self::hostedAvailable();
    }

    private static function localAvailable(): bool
    {
        if (!config('pra_di_sandbox.enabled', false)) return false;
        if (app()->environment('testing')) {
            $connection = DB::connection()->getConfig();
            return ($connection['driver'] ?? '') === 'sqlite'
                && ($connection['database'] ?? '') === ':memory:'
                && empty($connection['url']);
        }

        return app()->environment('local') && DevStagingGuard::problems() === [];
    }

    public static function enforce(?int $companyId = null): void
    {
        abort_unless(self::available($companyId), 404);
    }
}
