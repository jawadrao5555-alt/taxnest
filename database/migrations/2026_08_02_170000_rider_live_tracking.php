<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rider LIVE Tracking (Aug 2026) — Unlimited-plan exclusive.
 *
 * - pos_rider_locations: GPS points from the TaxNest Rider Android app.
 * - pos_riders: duty state + denormalized last-known position + app token
 *   (SHA-256 of the bearer token; login rotates it → one active device).
 * - pricing_plans.rider_tracking_enabled: NEW plan gate.
 *   NOTE: default FALSE — a deliberate deviation from the fail-open
 *   convention. Tracking is a pos-only premium gate; future pos plan rows
 *   (e.g. Pro Max from its own migration) must NOT silently unlock it.
 *   Non-pos products never read this column.
 *
 * Idempotent (hasTable/hasColumn guards) — prod schema drift safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_rider_locations')) {
            Schema::create('pos_rider_locations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('rider_id');
                $table->decimal('lat', 10, 7);
                $table->decimal('lng', 10, 7);
                $table->unsignedSmallInteger('accuracy_m')->nullable();
                $table->dateTime('recorded_at');
                $table->timestamp('created_at')->useCurrent();
                $table->index(['company_id', 'rider_id', 'recorded_at'], 'prl_company_rider_time');
            });
        }

        Schema::table('pos_riders', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_riders', 'on_duty')) {
                $table->boolean('on_duty')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('pos_riders', 'duty_started_at')) {
                $table->timestamp('duty_started_at')->nullable()->after('on_duty');
            }
            if (!Schema::hasColumn('pos_riders', 'last_lat')) {
                $table->decimal('last_lat', 10, 7)->nullable()->after('duty_started_at');
            }
            if (!Schema::hasColumn('pos_riders', 'last_lng')) {
                $table->decimal('last_lng', 10, 7)->nullable()->after('last_lat');
            }
            if (!Schema::hasColumn('pos_riders', 'last_located_at')) {
                $table->timestamp('last_located_at')->nullable()->after('last_lng');
            }
            if (!Schema::hasColumn('pos_riders', 'app_token')) {
                $table->string('app_token', 64)->nullable()->after('last_located_at');
            }
        });

        if (!Schema::hasColumn('pricing_plans', 'rider_tracking_enabled')) {
            Schema::table('pricing_plans', function (Blueprint $table) {
                $table->boolean('rider_tracking_enabled')->default(false)->after('analytics_enabled');
            });
        }

        // Matrix (pos only): Unlimited = ON, every other pos plan = OFF.
        // Active-trial rule in PosFeatureService still grants it during trial.
        DB::table('pricing_plans')->where('product_type', 'pos')
            ->where('name', 'Unlimited')->update(['rider_tracking_enabled' => 1]);
        DB::table('pricing_plans')->where('product_type', 'pos')
            ->where('name', '!=', 'Unlimited')->update(['rider_tracking_enabled' => 0]);
    }

    public function down(): void
    {
        // Additive only — no destructive down (prod safety).
    }
};
