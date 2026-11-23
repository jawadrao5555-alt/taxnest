<?php

namespace App\Services;

use App\Models\{Company, PaymentProof, PosAddon, PosDiSandboxProfile, PosImsProfile};
use Illuminate\Support\Facades\Schema;

final class PosFederalAddonAccess
{
    public const CODES = ['federal_di', 'federal_ims'];

    public static function isFederalCode(string $code): bool
    {
        return in_array($code, self::CODES, true);
    }

    /** A different or expired purchase requires a separate channel change review. */
    public static function selectionAllowed(Company $company, array $codes): bool
    {
        $selected = array_values(array_intersect(self::CODES, $codes));
        if (!$selected) return true;
        if (count($selected) !== 1) return false;
        $other = $selected[0] === 'federal_di' ? 'federal_ims' : 'federal_di';
        return !self::grants($company)->where('addon_code', $other)->exists()
            && !self::hasLegacyProfile($company, $other);
    }

    /** Keep already configured companies on their existing channel. */
    private static function hasLegacyProfile(Company $company, string $code): bool
    {
        $model = $code === 'federal_di' ? PosDiSandboxProfile::class : PosImsProfile::class;
        if (!Schema::hasTable((new $model)->getTable())) return false;
        $profiles = $model::where('company_id', $company->id);
        $cutoff = config('pra_di_sandbox.paid_addon_policy_starts_at');
        if (!$cutoff) return $profiles->where('enabled', true)->exists();

        return $profiles->where('created_at', '<', $cutoff)
            ->where(function ($configured) use ($code) {
                $configured->where('enabled', true)
                    ->orWhereNotNull('sandbox_token')->orWhereNotNull('production_token');
                if ($code === 'federal_ims') {
                    $configured->orWhere(fn ($agent) => $agent->whereNotNull('device_uid')->whereNotNull('local_ims_url'));
                }
            })->exists();
    }

    private static function grants(Company $company)
    {
        return PosAddon::where('company_id', $company->id)->whereIn('addon_code', self::CODES);
    }

    public static function state(Company $company, string $code): string
    {
        if (!PosAddonService::tableExists()) {
            return config('pra_di_sandbox.paid_addon_policy_starts_at') ? 'locked' : 'legacy';
        }
        $grants = self::grants($company)->get();
        $grant = $grants->firstWhere('addon_code', $code);
        if ($grants->count() === 1 && $grant && $grant->active && $grant->subscription_id && $grant->payment_proof_id
            && (float) $grant->amount > 0 && $grant->starts_at && $grant->starts_at->lte(now())
            && $grant->ends_at && $grant->ends_at->endOfDay()->isFuture()) {
            $sub = PosAddonService::activeSubscription($company);
            if (self::hasPaidPackage($company)
                && (int) $sub->id === (int) $grant->subscription_id
                && in_array($code, PaymentProof::posAddonKind()->whereKey($grant->payment_proof_id)
                    ->where('company_id', $company->id)->where('subscription_id', $sub->id)
                    ->where('status', 'verified')->first()?->addonCodeList() ?? [], true)) {
                return 'paid';
            }
        }
        if ($grants->isEmpty() && self::hasLegacyProfile($company, $code)) return 'legacy';
        $pending = Schema::hasTable('payment_proofs') ? array_intersect(PosAddonService::pendingCodes($company), self::CODES) : [];
        if (in_array($code, $pending, true)) return 'pending';
        if ($grant) return 'expired';
        if ($pending || $grants->isNotEmpty() || config('pra_di_sandbox.paid_addon_policy_starts_at')) return 'locked';
        return 'legacy';
    }

    public static function hasPaidPackage(Company $company): bool
    {
        $sub = PosAddonService::activeSubscription($company);
        if (!$sub || $sub->isExpired() || $sub->hasActiveOverride() || $sub->trial_ends_at
            || (float) $sub->final_price <= 0 || $sub->entitlementPlan()?->is_trial !== false) return false;
        return !$sub->hasLifecycleSnapshot() || (ProspectiveSubscriptionPolicy::display($sub)['phase'] ?? null) === 'paid';
    }

    public static function display(Company $company, string $code, bool $enabled): string
    {
        return match (self::state($company, $code)) {
            'paid' => $enabled ? 'Enabled' : 'Setup required',
            'pending' => 'Payment under review',
            'expired' => 'Add-on expired',
            'locked' => 'Paid add-on required',
            default => $enabled ? 'Enabled' : 'Disabled',
        };
    }

    public static function allows(Company $company, string $code): bool
    {
        return in_array(self::state($company, $code), ['legacy', 'paid'], true);
    }
}
