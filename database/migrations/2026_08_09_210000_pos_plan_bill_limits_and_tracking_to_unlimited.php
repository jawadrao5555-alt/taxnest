<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS plan ladder update (owner, 9 Aug 2026):
 *   - Bill limits raised: Starter 2,000 / Business 5,000 / Pro 10,000 / Pro Max UNLIMITED (-1)
 *   - Rider LIVE tracking moved UP: Pro Max se nikal kar sirf Unlimited mein
 *
 * Data-only, idempotent (name + product_type matched — prod runs migrate --force).
 * Display JSON (features) updated in lockstep: Pro Max card loses the tracking
 * bullet + its bill-count bullet becomes Unlimited; Unlimited card gains tracking.
 * scripts/plan-gate-check.php $MATRIX updated in the same commit (deploy wall).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $limits = [
            'Starter' => 2000,
            'Business' => 5000,
            'Pro' => 10000,
            'Pro Max' => -1,
        ];
        foreach ($limits as $name => $limit) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', $name)
                ->update(['invoice_limit' => $limit, 'updated_at' => now()]);
        }

        if (Schema::hasColumn('pricing_plans', 'rider_tracking_enabled')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Pro Max')
                ->update(['rider_tracking_enabled' => 0, 'updated_at' => now()]);
            DB::table('pricing_plans')
                ->where('product_type', 'pos')->where('name', 'Unlimited')
                ->update(['rider_tracking_enabled' => 1, 'updated_at' => now()]);
        }

        // Display copy (features JSON is display-only, but must not contradict gates).
        $proMax = DB::table('pricing_plans')->where('product_type', 'pos')->where('name', 'Pro Max')->first();
        if ($proMax) {
            $f = json_decode((string) $proMax->features, true) ?: [];
            $f = array_values(array_filter($f, fn ($b) => stripos((string) $b, 'rider live tracking') === false));
            foreach ($f as $i => $b) {
                if (stripos((string) $b, 'bills / month') !== false || stripos((string) $b, 'bills/month') !== false) {
                    $f[$i] = 'Unlimited bills / month';
                }
            }
            DB::table('pricing_plans')->where('id', $proMax->id)
                ->update(['features' => json_encode($f, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        }

        $unlimited = DB::table('pricing_plans')->where('product_type', 'pos')->where('name', 'Unlimited')->first();
        if ($unlimited) {
            $f = json_decode((string) $unlimited->features, true) ?: [];
            $hasTracking = false;
            foreach ($f as $b) {
                if (stripos((string) $b, 'rider live tracking') !== false) {
                    $hasTracking = true;
                    break;
                }
            }
            if (!$hasTracking) {
                array_splice($f, 1, 0, ['Rider LIVE tracking on map']);
                DB::table('pricing_plans')->where('id', $unlimited->id)
                    ->update(['features' => json_encode($f, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // Data-only ladder change — no automatic rollback (previous values documented
        // in the migration description; restore via a fresh migration if ever needed).
    }
};
