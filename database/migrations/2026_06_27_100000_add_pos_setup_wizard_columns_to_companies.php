<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * POS unification — setup wizard tracking + legacy-restaurant fallback.
 *
 * Idempotent / self-heal (hasColumn guards) so `php artisan migrate --force`
 * heals a stale cPanel schema without touching a healthy one.
 *
 * - pos_setup_completed       : has this company finished the POS setup wizard?
 *                               Drives the first-time auto-launch. Existing
 *                               companies are backfilled TRUE so no live tenant
 *                               is ever trapped — only brand-new companies see
 *                               the wizard automatically.
 * - pos_use_legacy_restaurant : emergency per-company opt-OUT that keeps a
 *                               restaurant company on the OLD restaurant sale
 *                               screen instead of the unified universal screen.
 *                               Default FALSE = everyone uses the unified screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_setup_completed')) {
                $table->boolean('pos_setup_completed')->default(false);
            }
            if (!Schema::hasColumn('companies', 'pos_use_legacy_restaurant')) {
                $table->boolean('pos_use_legacy_restaurant')->default(false);
            }
        });

        // Backfill: every company that exists at migration time is a live tenant
        // and must NEVER hit the first-time wizard. Run this UNCONDITIONALLY (not
        // only when the column was just added): on a drift-healed cPanel schema the
        // column may already exist all-FALSE, in which case skipping the backfill
        // would trap EVERY tenant in the wizard. Idempotent — only flips rows still
        // false, so re-running is harmless and new companies (created AFTER this
        // one-time migration) keep the default false and still see the wizard.
        if (Schema::hasColumn('companies', 'pos_setup_completed')) {
            DB::table('companies')->where('pos_setup_completed', false)->update(['pos_setup_completed' => true]);
        }
    }

    public function down(): void
    {
        // No-op: self-heal migration never drops columns.
    }
};
