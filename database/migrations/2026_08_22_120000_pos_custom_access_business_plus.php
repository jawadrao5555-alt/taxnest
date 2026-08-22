<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Custom Access is included in every paid PRA POS package except Starter.
 * The active-trial rule still grants trial access through PosFeatureService.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pricing_plans') && Schema::hasColumn('pricing_plans', 'custom_access_enabled')) {
            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->whereIn('name', ['Business', 'Pro', 'Pro Max', 'Unlimited'])
                ->update(['custom_access_enabled' => 1]);

            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('name', 'Starter')
                ->update(['custom_access_enabled' => 0]);
        }

        // Materialise the initial catalogue as well as keeping service fallbacks.
        // updateOrInsert makes this safe on repeat deploys and preserves an
        // admin-edited value if this migration is ever re-run.
        if (Schema::hasTable('system_settings')) {
            $columns = array_flip(Schema::getColumnListing('system_settings'));
            $defaults = [
                'delivery_riders', 'qr_menu', 'whatsapp_bill',
                'staff_attendance', 'rider_tracking', 'caller_id',
            ];

            foreach ($defaults as $code) {
                foreach (['annual' => 12000, 'quarterly' => 3000] as $cycle => $price) {
                    $key = "pos_addon_{$code}_{$cycle}_price";
                    $values = ['value' => (string) $price];
                    if (isset($columns['description'])) {
                        $values['description'] = 'PRA POS paid add-on pricing';
                    }
                    if (isset($columns['updated_at'])) {
                        $values['updated_at'] = now();
                    }
                    if (isset($columns['created_at'])) {
                        $values['created_at'] = now();
                    }

                    DB::table('system_settings')->updateOrInsert(['key' => $key], $values);
                }
            }
        }
    }

    public function down(): void
    {
        // Do not remove a paid package entitlement automatically.
    }
};