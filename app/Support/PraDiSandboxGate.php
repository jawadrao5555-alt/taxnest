<?php

namespace App\Support;

use App\Models\Company;
use App\Models\PosDiSandboxProfile;
use Illuminate\Support\Facades\DB;

final class PraDiSandboxGate
{
    public static function available(?int $companyId = null, ?string $environment = null): bool
    {
        if (!self::setupAvailable()) return false;
        if ($environment !== null && !in_array($environment, ['sandbox', 'production'], true)) return false;
        if (!$companyId) return self::localAvailable() && $environment !== 'production';
        $profile = PosDiSandboxProfile::where('company_id', $companyId)->first();
        if (!$profile) return self::localAvailable() && $environment !== 'production';
        if (!$profile->enabled) return false;
        $selected = $profile->environment ?? 'sandbox';
        if (!in_array($selected, ['sandbox', 'production'], true)) return false;
        if ($environment !== null && $selected !== $environment) return false;
        if ($selected === 'production' && (!config('pra_di_sandbox.production_enabled', false)
            || !$profile->production_confirmed_at)) return false;

        return Company::whereKey($companyId)->where('product_type', 'pos')
            ->where('status', 'approved')->where('company_status', 'active')->exists();
    }

    public static function environment(int $companyId): string
    {
        return PosDiSandboxProfile::where('company_id', $companyId)->value('environment') ?? 'sandbox';
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

    public static function enforce(?int $companyId = null, ?string $environment = null): void
    {
        abort_unless(self::available($companyId, $environment), 404);
    }
}
