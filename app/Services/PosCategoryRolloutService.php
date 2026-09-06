<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Safe rollout of the category profiles (Task 1582, step 8).
 *
 * BEFORE the availability predicate hides anything, every live POS / FBR POS
 * shop is compared against its own category set: any module it already USES
 * outside that set is recorded as a grandfathered extra, so the deploy takes
 * nothing away. Idempotent — an existing record (admin or grandfathered) is
 * never overwritten.
 *
 * "Uses" is decided from evidence, never from the plan alone:
 *   - module flag   stored ON in feature_flags
 *   - deals         a deal row exists
 *   - riders        a rider row exists
 *   - khata         a customer carries a khata balance / a ledger row exists
 *   - loyalty       the FBR loyalty settings row is switched on
 *   - store slip    kitchen_printer_enabled (FBR panel only)
 *   - QR menu       the public profile is switched on
 *   - caller id     caller_id_enabled switch on
 *   - pharmacy      pharmacy_mode on
 *   - inventory     companies.inventory_enabled on (column, not only the flag)
 *   - services      a services row exists
 *   - any gate      a verified, active paid add-on for it
 * Every lookup is table/column guarded so the backfill runs on any schema.
 */
class PosCategoryRolloutService
{
    public const PRODUCT_TYPES = ['pos', 'fbrpos'];

    /** module => evidence string, for modules the shop uses outside its category. */
    public static function outsidersFor(Company $company): array
    {
        $own = PosFeatureService::categoryModules($company);
        $panel = PosFeatureService::panelFor($company);
        $cid = (int) $company->id;
        $out = [];

        $stored = is_array($company->feature_flags) ? $company->feature_flags : [];
        foreach (PosFeatureService::ALL_FLAGS as $flag) {
            if (!empty($stored[$flag]) && !in_array($flag, $own, true)) {
                $out[$flag] = 'flag stored ON';
            }
        }

        $evidence = [];
        $dealsTable = $panel === 'fbr' ? 'fbr_pos_deals' : 'pos_deals';
        if (self::rows($dealsTable, $cid)) {
            $evidence['deals_enabled'] = 'has deals';
        }
        if (self::rows('pos_riders', $cid)) {
            $evidence['riders_enabled'] = 'has riders';
            $evidence['rider_tracking_enabled'] = 'rides on riders';
        }
        $ledger = $panel === 'fbr' ? 'fbr_customer_ledgers' : 'customer_ledgers';
        if (self::rows($ledger, $cid) || self::khataBalances($cid)) {
            $evidence['khata_enabled'] = 'khata in use';
        }
        if (self::loyaltyOn($cid)) {
            $evidence['loyalty_enabled'] = 'loyalty switched on';
        }
        if ($panel === 'fbr' && self::col($company, 'kitchen_printer_enabled')) {
            $evidence['kot_enabled'] = 'store slip switched on';
        }
        if (self::publicProfileOn($company)) {
            $evidence['qr_menu_enabled'] = 'public QR profile on';
        }
        if (self::col($company, 'caller_id_enabled')) {
            $evidence['caller_id_enabled'] = 'caller id switched on';
        }
        if (self::col($company, 'pharmacy_mode')) {
            $evidence['pharmacy_enabled'] = 'pharmacy mode on';
        }
        // Inventory has TWO switches (flag + companies.inventory_enabled) and the
        // stock pages read the COLUMN — a shop tracking stock on the column
        // alone must be grandfathered before the URL gate lands.
        if (self::col($company, 'inventory_enabled')) {
            $evidence['inventory'] = 'stock tracking switched on';
        }
        if (self::rows('pos_services', $cid)) {
            $evidence['service_jobs'] = 'has services';
        }
        foreach (self::addonGates($company) as $gate) {
            $evidence[$gate] = 'paid add-on active';
        }

        foreach ($evidence as $gate => $why) {
            if (!in_array($gate, $own, true)) {
                $out[$gate] = $why;
            }
        }
        return $out;
    }

    /**
     * Walk every POS/FBR POS company; record outsiders as grandfathered extras
     * when $write is true. Returns the report rows.
     *
     * @return array<int, array{id:int,name:string,category:string,modules:array<string,string>}>
     */
    public static function backfill(bool $write, ?callable $onRow = null): array
    {
        if (!PosFeatureService::extrasColumnExists()) {
            return [];
        }
        $report = [];
        Company::query()
            ->where(function ($q) {
                $q->whereIn('product_type', self::PRODUCT_TYPES)->orWhereNull('product_type');
            })
            ->orderBy('id')
            ->chunkById(200, function ($companies) use ($write, $onRow, &$report) {
                foreach ($companies as $company) {
                    PosFeatureService::flushGateCaches();
                    $outsiders = self::outsidersFor($company);
                    if (!$outsiders) {
                        continue;
                    }
                    $existing = PosFeatureService::extraModules($company);
                    $new = array_diff_key($outsiders, $existing);
                    $row = [
                        'id' => (int) $company->id,
                        'name' => (string) $company->name,
                        'category' => PosFeatureService::profileCategory($company),
                        'modules' => $outsiders,
                        'new' => array_keys($new),
                    ];
                    if ($write && $new) {
                        $extras = $existing;
                        foreach ($new as $key => $why) {
                            $extras[$key] = [
                                'source' => 'grandfathered',
                                'reason' => $why,
                                'at' => now()->toDateTimeString(),
                            ];
                        }
                        $company->forceFill(['pos_module_extras' => $extras])->save();
                    }
                    $report[] = $row;
                    if ($onRow) {
                        $onRow($row);
                    }
                }
            });
        PosFeatureService::flushGateCaches();
        return $report;
    }

    /* ---------------- evidence helpers (all schema-guarded) ---------------- */

    protected static function rows(string $table, int $companyId): bool
    {
        try {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'company_id')) {
                return false;
            }
            return DB::table($table)->where('company_id', $companyId)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function khataBalances(int $companyId): bool
    {
        try {
            if (!Schema::hasTable('pos_customers') || !Schema::hasColumn('pos_customers', 'khata_balance')) {
                return false;
            }
            return DB::table('pos_customers')->where('company_id', $companyId)->where('khata_balance', '!=', 0)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function loyaltyOn(int $companyId): bool
    {
        try {
            if (!Schema::hasTable('fbr_pos_loyalty_settings')) {
                return false;
            }
            return DB::table('fbr_pos_loyalty_settings')->where('company_id', $companyId)->where('is_enabled', 1)->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function col(Company $company, string $column): bool
    {
        try {
            return Schema::hasColumn('companies', $column) && (bool) $company->getAttribute($column);
        } catch (\Throwable $e) {
            return false;
        }
    }

    protected static function publicProfileOn(Company $company): bool
    {
        $raw = $company->getAttribute('public_profile_settings');
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) && !empty($raw['enabled']);
    }

    /** Plan-gate columns covered by this shop's verified, active paid add-ons. */
    protected static function addonGates(Company $company): array
    {
        try {
            $codes = PosAddonService::activeCodes($company);
        } catch (\Throwable $e) {
            return [];
        }
        $gates = [];
        foreach ($codes as $code) {
            $gate = PosAddonPricingService::ADDONS[$code]['gate'] ?? null;
            if ($gate) {
                $gates[] = $gate;
            }
        }
        return $gates;
    }
}
