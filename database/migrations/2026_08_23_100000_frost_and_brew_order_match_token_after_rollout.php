<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Frost and Brew (company id 26) — restore 'token' order-match style,
     * ordered AFTER the 2026_08_23_000000 code-rollout so the outcome is the
     * same on fresh and already-migrated databases (Task 654 review fix; the
     * original 2026_08_13 revert was neutralized because it sorted before the
     * rollout and got overwritten on fresh runs).
     *
     * Guarded by id + name (live-data migration convention) and idempotent:
     * on databases where the owner's revert already applied, this re-sets the
     * same value; on any database without that company it does nothing.
     */
    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        DB::table('companies')
            ->where('id', 26)
            ->where('name', 'like', '%Frost%')
            ->update(['order_match_style' => 'token']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'order_match_style')) {
            return;
        }

        DB::table('companies')
            ->where('id', 26)
            ->where('name', 'like', '%Frost%')
            ->update(['order_match_style' => 'code']);
    }
};
