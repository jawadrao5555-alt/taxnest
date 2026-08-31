<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PosTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * "Company ON hai, magar aadha staff OFF hai" — khamosh khali jagah ka alert.
 *
 * Asal waqia (Frost and Brew, 31 Aug 2026): company ka PRA reporting switch ON
 * tha, NTN bhi laga hua tha, magar aik cashier ka APNA switch OFF tha. Us
 * cashier ne August mein 649 bill katay — Rs 5,64,000 se zyada — aur un mein se
 * ek bhi PRA tak nahi pohancha. Panel par kahin nahi likha tha ke aisa ho raha
 * hai, is liye maalik ko poora mahina yeh yaqeen raha ke sab kuch report ho raha
 * hai. Pata tab chala jab humein khud database dekhna para.
 *
 * Yeh service us khali jagah ko bharti hai: jab company ka switch ON ho magar
 * koi billing karne wala fard apne switch se OFF ho, to dashboard par saaf saaf
 * likha aata hai ke kaun OFF hai aur pichhle 30 din mein kitna karobar report
 * se bahar reh gaya.
 *
 * Ehtiyaat: yeh mehez ittila hai. Kisi ka switch khud nahi badalta — kisi live
 * dukan ki tax reporting apne aap chalu kar dena hamara kaam nahi. Aur yeh
 * kabhi dashboard ko tornay ka sabab na bane, is liye har cheez try/catch aur
 * hasColumn guard ke peechhe hai (PROD schema drift).
 */
class PraReportingCoverage
{
    /** Ginti bhaari na pare — har company ke liye 10 minute ka cache. */
    private const CACHE_MINUTES = 10;

    /** Kitne din peechhe tak ka chhoota hua karobar ginna hai. */
    private const LOOKBACK_DAYS = 30;

    /** Sirf yeh log bill katte hain — waiter/rider ka switch bemani hai. */
    private const BILLING_ROLES = ['pos_admin', 'pos_manager', 'pos_cashier'];

    /**
     * NULL = dikhane ko kuch nahi (sab theek, ya laagu hi nahi hota).
     *
     * Warna: ['members' => [names], 'count' => n, 'bills' => n, 'amount' => f,
     *         'days' => 30]
     */
    public static function summary(?Company $company): ?array
    {
        if (!$company) {
            return null;
        }

        try {
            return Cache::remember(
                'pra_coverage_gap_' . $company->id,
                now()->addMinutes(self::CACHE_MINUTES),
                fn () => self::compute($company)
            );
        } catch (\Throwable $e) {
            // Alert mehez ittila hai — kabhi dashboard na rok de.
            return null;
        }
    }

    /** Kisi company ka cache foran gira do (switch badalte hi). */
    public static function forget(int|string|null $companyId): void
    {
        if (!$companyId) {
            return;
        }
        try {
            Cache::forget('pra_coverage_gap_' . $companyId);
        } catch (\Throwable $e) {
            // koi baat nahi — 10 minute mein khud taza ho jayega
        }
    }

    private static function compute(Company $company): ?array
    {
        // Standalone edition mein PRA hai hi nahi.
        if (($company->pos_integration_mode ?? 'pra') === 'standalone') {
            return null;
        }
        // Company ka apna switch OFF ho to koi tazaad nahi — dukan ne jaan
        // boojh kar sab kuch local rakha hua hai, yeh un ka faisla hai.
        if (!(bool) ($company->pra_reporting_enabled ?? false)) {
            return null;
        }
        if (!Schema::hasColumn('users', 'pra_reporting_enabled')) {
            return null;
        }

        // Sirf SARAHATAN OFF (0) — NULL ka matlab "company ke sath chalo" hai,
        // aur company ON hai, to woh log pehle se theek hain.
        $offQuery = User::where('company_id', $company->id)
            ->whereIn('pos_role', self::BILLING_ROLES)
            ->where('pra_reporting_enabled', 0);

        if (Schema::hasColumn('users', 'is_active')) {
            $offQuery->where('is_active', true);
        }

        $offMembers = $offQuery->orderBy('id')->get(['id', 'name']);
        if ($offMembers->isEmpty()) {
            return null;
        }

        // Reporting-OFF FINAL bills ki pehchan (documented invariant):
        // completed + regulator mode (ya khali) + pra_status NULL. Jaan-boojh
        // kar banaye gaye LOCAL bills (invoice_mode='local' + pra_status='local')
        // is mein shamil NAHI — woh alag cheez hain aur bilkul jaiz hain.
        $since = now()->subDays(self::LOOKBACK_DAYS)->startOfDay();

        $missed = PosTransaction::withoutGlobalScope('hide_archived')
            ->where('company_id', $company->id)
            ->where('status', 'completed')
            ->whereNull('pra_status')
            ->where(function ($q) {
                $q->where('invoice_mode', 'pra')->orWhereNull('invoice_mode');
            })
            ->whereIn('created_by', $offMembers->pluck('id'))
            ->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_amount), 0) as amount')
            ->first();

        return [
            'members' => $offMembers->pluck('name')->all(),
            'count' => $offMembers->count(),
            'bills' => (int) ($missed->bills ?? 0),
            'amount' => (float) ($missed->amount ?? 0),
            'days' => self::LOOKBACK_DAYS,
        ];
    }
}
