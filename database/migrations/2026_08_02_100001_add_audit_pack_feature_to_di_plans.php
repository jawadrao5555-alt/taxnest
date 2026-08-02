<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FEATURE = 'FBR Audit Pack (6-year archive)';

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans') || !Schema::hasColumn('pricing_plans', 'features')) {
            return;
        }

        $plans = DB::table('pricing_plans')->where('product_type', 'di')->get(['id', 'features']);

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true);
            if (!is_array($features)) {
                $features = [];
            }

            $already = false;
            foreach ($features as $feature) {
                if (is_string($feature) && stripos($feature, 'audit pack') !== false) {
                    $already = true;
                    break;
                }
            }

            if (!$already) {
                $features[] = self::FEATURE;
                DB::table('pricing_plans')->where('id', $plan->id)->update([
                    'features' => json_encode(array_values($features)),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('pricing_plans') || !Schema::hasColumn('pricing_plans', 'features')) {
            return;
        }

        $plans = DB::table('pricing_plans')->where('product_type', 'di')->get(['id', 'features']);

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true);
            if (!is_array($features)) {
                continue;
            }

            $filtered = array_values(array_filter($features, fn ($f) => $f !== self::FEATURE));

            if (count($filtered) !== count($features)) {
                DB::table('pricing_plans')->where('id', $plan->id)->update([
                    'features' => json_encode($filtered),
                ]);
            }
        }
    }
};
