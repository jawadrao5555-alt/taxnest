<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ZFC report (5 Aug 2026): waiter login par PRA Mode / settings-type tutorial
 * videos bhi dikh rahi thin. Staff ko sirf apne kaam ki videos dikhni chahiyen.
 *
 * min_role tiers:
 *   'any'     -> har role (waiter/kitchen/rider samet)
 *   'cashier' -> cashier + manager + admin (billing/PRA/operations topics)
 *   'admin'   -> sirf manager + admin (settings, team, billing-package topics)
 *
 * Idempotent: hasColumn guard + slug-keyed updates (prod par migrate --force).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tutorial_videos', 'min_role')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->string('min_role', 20)->default('any')->after('required_feature');
            });
        }

        $tiers = [
            'admin' => [
                'pos-customize', 'desktop-agent-printing', 'package-billing',
                'team-custom-access', 'settings-branding', 'reports-analytics',
                'staff-hazri',
            ],
            'cashier' => [
                'sale-screen-tutorial', 'customers-add-import-export',
                'barcode-scan-search', 'discount-dena', 'provisional-bills',
                'bills-history', 'day-opening', 'day-close-report',
                'delivery-riders-tutorial', 'deals-tutorial',
                'products-excel-import', 'inventory-stock', 'offline-mode',
                'pra-mode', 'restaurant-mode-tutorial',
            ],
        ];

        foreach ($tiers as $role => $slugs) {
            DB::table('tutorial_videos')->whereIn('slug', $slugs)->update(['min_role' => $role]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tutorial_videos', 'min_role')) {
            Schema::table('tutorial_videos', function (Blueprint $table) {
                $table->dropColumn('min_role');
            });
        }
    }
};
