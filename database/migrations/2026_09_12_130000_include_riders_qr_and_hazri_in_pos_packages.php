<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner package decision:
 * - Delivery Riders and QR Menu are included in every paid POS package except Starter.
 * - Staff Attendance is included in Pro, Pro Max and Unlimited.
 * - All three retire from the paid add-on catalogue.
 * - A Business shop with a live Staff Attendance add-on moves to Pro so paid
 *   access is preserved instead of being silently taken away.
 */
return new class extends Migration
{
    private const PACKAGE_GATES = [
        'riders_enabled' => ['Business', 'Pro', 'Pro Max', 'Unlimited'],
        'qr_menu_enabled' => ['Business', 'Pro', 'Pro Max', 'Unlimited'],
        'hazri_enabled' => ['Pro', 'Pro Max', 'Unlimited'],
    ];

    private const RETIRED_ADDON_CODES = [
        'delivery_riders',
        'qr_menu',
        'staff_attendance',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('pricing_plans')) {
            return;
        }

        DB::transaction(function () {
            $this->moveLiveBusinessHazriBuyersToPro();
            $this->applyPackageMatrix();
            $this->retireIncludedAddonRows();
        });
    }

    private function applyPackageMatrix(): void
    {
        foreach (self::PACKAGE_GATES as $column => $includedPlans) {
            if (!Schema::hasColumn('pricing_plans', $column)) {
                continue;
            }

            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->update([$column => 0]);

            DB::table('pricing_plans')
                ->where('product_type', 'pos')
                ->whereIn('name', $includedPlans)
                ->update([$column => 1]);
        }
    }

    private function moveLiveBusinessHazriBuyersToPro(): void
    {
        if (!Schema::hasTable('subscriptions') || !Schema::hasTable('pos_addons')) {
            return;
        }

        $businessPlanIds = DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Business')
            ->pluck('id');
        $proPlanId = DB::table('pricing_plans')
            ->where('product_type', 'pos')
            ->where('name', 'Pro')
            ->value('id');

        if ($businessPlanIds->isEmpty() || !$proPlanId) {
            return;
        }

        $today = now()->toDateString();
        $subscriptionIds = DB::table('subscriptions as s')
            ->join('pos_addons as pa', 'pa.company_id', '=', 's.company_id')
            ->whereIn('s.pricing_plan_id', $businessPlanIds->all())
            ->where('s.active', 1)
            ->where(function ($query) use ($today) {
                $query->whereNull('s.end_date')->orWhereDate('s.end_date', '>=', $today);
            })
            ->where('pa.addon_code', 'staff_attendance')
            ->where('pa.active', 1)
            ->where(function ($query) use ($today) {
                $query->whereNull('pa.ends_at')->orWhereDate('pa.ends_at', '>=', $today);
            })
            ->distinct()
            ->pluck('s.id');

        if ($subscriptionIds->isEmpty()) {
            return;
        }

        DB::table('subscriptions')
            ->whereIn('id', $subscriptionIds->all())
            ->update([
                'pricing_plan_id' => $proPlanId,
                'updated_at' => now(),
            ]);
    }

    private function retireIncludedAddonRows(): void
    {
        if (!Schema::hasTable('pos_addons')) {
            return;
        }

        $values = ['active' => 0];
        if (Schema::hasColumn('pos_addons', 'updated_at')) {
            $values['updated_at'] = now();
        }

        DB::table('pos_addons')
            ->whereIn('addon_code', self::RETIRED_ADDON_CODES)
            ->update($values);
    }

    public function down(): void
    {
        // One-way commercial decision. Package upgrades and paid history cannot
        // be reconstructed safely by a rollback.
    }
};