<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-approved PRA POS annual ladder. The three existing package rows keep
 * their IDs, included branch/team limits and category gates. Old subscription
 * amounts and verified payment proofs are historical snapshots, not repriced.
 *
 * Business can buy Caller ID and rider tracking; Unlimited now includes both.
 * Trial, FBR POS, DI and retired POS rows remain untouched.
 */
return new class extends Migration
{
    private const ANNUAL = [
        'Starter' => 19999,
        'Business' => 29999,
        'Unlimited' => 37999,
    ];

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        $columns = array_flip(Schema::getColumnListing('pricing_plans'));
        if (!isset($columns['product_type'], $columns['name'], $columns['price'])) {
            return;
        }

        foreach (self::ANNUAL as $name => $price) {
            $changes = ['price' => $price];
            if ($name === 'Unlimited' && isset($columns['rider_tracking_enabled'])) {
                $changes['rider_tracking_enabled'] = 1;
            }
            if (isset($columns['updated_at'])) {
                $changes['updated_at'] = now();
            }

            $plan = DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->where('name', $name);
            if (isset($columns['is_trial'])) {
                $plan->where('is_trial', false);
            }
            $plan->update($changes);
        }
    }

    public function down(): void
    {
        // Prices and entitlements are a business decision. Rolling back code
        // must never silently replace a newer admin price or remove tracking
        // that an Unlimited shop already began using.
    }
};
