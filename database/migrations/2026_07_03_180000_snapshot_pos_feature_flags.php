<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time snapshot: persist each company's CURRENTLY RESOLVED POS feature
 * flags (business_category / restaurant_mode presets merged with any manual
 * overrides) into companies.feature_flags as a FULL explicit flag set.
 *
 * After this runs, PosFeatureService::forCompany() stops consulting
 * business_category / restaurant_mode entirely — behavior is byte-identical
 * for existing companies by construction, and every feature becomes an
 * individual per-company toggle.
 *
 * Idempotent: re-running merges the same values (explicit overrides win).
 */
return new class extends Migration
{
    private const ALL_FLAGS = [
        'kot', 'tables', 'kitchen', 'kitchen_notes', 'recipes',
        'inventory', 'delivery', 'barcode', 'prescription',
        'service_jobs', 'customer_profile', 'bulk_pricing',
        'multi_branch', 'customer_loyalty',
    ];

    private const CATEGORY_DEFAULTS = [
        'restaurant' => [
            'kot' => true, 'tables' => true, 'kitchen' => true,
            'kitchen_notes' => true, 'recipes' => true, 'inventory' => true,
            'delivery' => true, 'customer_profile' => true,
        ],
        'cafe' => [
            'kot' => true, 'kitchen' => true, 'kitchen_notes' => true,
            'recipes' => true, 'inventory' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'quick_service' => [
            'kot' => true, 'kitchen' => true, 'recipes' => true,
            'inventory' => true, 'delivery' => true,
            'customer_profile' => true,
        ],
        'retail' => [
            'barcode' => true, 'inventory' => true, 'customer_profile' => true,
        ],
        'pharmacy' => [
            'barcode' => true, 'inventory' => true,
            'prescription' => true, 'delivery' => true, 'customer_profile' => true,
        ],
        'salon' => [
            'tables' => true, 'service_jobs' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
        'grocery' => [
            'barcode' => true, 'inventory' => true, 'delivery' => true,
        ],
        'wholesale' => [
            'inventory' => true, 'bulk_pricing' => true,
            'customer_profile' => true, 'multi_branch' => true,
        ],
        'hybrid_cafe_retail' => [
            'barcode' => true, 'inventory' => true,
            'kot' => true, 'kitchen' => true,
            'customer_profile' => true, 'customer_loyalty' => true,
        ],
    ];

    private const DEPENDENCIES = [
        'kot' => ['kitchen'],
        'recipes' => ['inventory'],
        'delivery' => ['customer_profile'],
        'prescription' => ['customer_profile'],
        'customer_loyalty' => ['customer_profile'],
    ];

    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'feature_flags')) {
            return;
        }

        $hasCategory = Schema::hasColumn('companies', 'business_category');
        $hasRestaurantMode = Schema::hasColumn('companies', 'restaurant_mode');

        foreach (DB::table('companies')->orderBy('id')->get() as $c) {
            $category = null;
            if ($hasCategory && !empty($c->business_category)) {
                $category = $c->business_category;
            } elseif ($hasRestaurantMode && !empty($c->restaurant_mode)) {
                $category = 'restaurant';
            } else {
                $category = 'retail';
            }

            $defaults = self::CATEGORY_DEFAULTS[$category] ?? [];

            $overrides = [];
            if (!empty($c->feature_flags)) {
                $decoded = json_decode($c->feature_flags, true);
                if (is_array($decoded)) {
                    $overrides = $decoded;
                }
            }

            $resolved = array_merge(
                array_fill_keys(self::ALL_FLAGS, false),
                $defaults,
                $overrides
            );

            foreach (self::DEPENDENCIES as $child => $parents) {
                if (!empty($resolved[$child])) {
                    foreach ($parents as $parent) {
                        if (empty($resolved[$parent])) {
                            $resolved[$child] = false;
                        }
                    }
                }
            }

            $resolved = array_map(fn ($v) => (bool) $v, $resolved);

            DB::table('companies')->where('id', $c->id)->update([
                'feature_flags' => json_encode($resolved),
            ]);
        }
    }

    public function down(): void
    {
        // Snapshot is additive/explicit — nothing safe to revert.
    }
};
