<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make pricing_plan_id and end_date nullable on the subscriptions table.
 *
 * REASON: AdminCompanyController::getOrCreateActiveSubscription() creates a
 * placeholder subscription row when an admin grants an override
 * (lifetime / temporary / grace / usage_free) to a company that has never
 * picked a paid plan (e.g. the seeded FBR POS test company). Both
 * pricing_plan_id (FK) and end_date were defined as NOT NULL by earlier
 * migrations, causing a 1452 / 23000 SQL crash on production with the message
 * "Field 'end_date' doesn't have a default value" or FK violation.
 *
 * SubscriptionAccessService treats null end_date as "no expiry" and the
 * CheckPlanLimit middleware short-circuits when pricingPlan is null, so
 * making both nullable is safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Drop the existing FK constraint so the column type can be altered
        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropForeign(['pricing_plan_id']);
            });
        } catch (\Throwable $e) {
            // Constraint may not exist or have a different name — safe to ignore
        }

        // 2) Make the columns nullable
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('pricing_plan_id')->nullable()->change();
            $table->date('end_date')->nullable()->change();
        });

        // 3) Re-add the FK with onDelete('set null') so deleting a plan no
        //    longer cascade-deletes a company's subscription history.
        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreign('pricing_plan_id')
                    ->references('id')->on('pricing_plans')
                    ->onDelete('set null');
            });
        } catch (\Throwable $e) {
            // FK already exists (idempotent re-run) — safe to ignore
        }
    }

    public function down(): void
    {
        // Reverse: assumes no null rows exist; if they do, this will fail.
        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->dropForeign(['pricing_plan_id']);
            });
        } catch (\Throwable $e) {}

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('pricing_plan_id')->nullable(false)->change();
            $table->date('end_date')->nullable(false)->change();
        });

        try {
            Schema::table('subscriptions', function (Blueprint $table) {
                $table->foreign('pricing_plan_id')
                    ->references('id')->on('pricing_plans')
                    ->onDelete('cascade');
            });
        } catch (\Throwable $e) {}
    }
};
