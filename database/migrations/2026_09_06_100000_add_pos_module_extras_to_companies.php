<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1582 — category profiles. Modules a shop carries OUTSIDE its business
 * category: SaaS-admin grants (with a reason) and modules grandfathered by the
 * rollout backfill (see 2026_09_06_100100_backfill_pos_module_extras).
 *
 * The availability predicate stays DORMANT until this column exists, so a
 * lagging migrate can never hide a live shop's modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'pos_module_extras')) {
                $table->json('pos_module_extras')->nullable()->after('feature_flags');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (Schema::hasColumn('companies', 'pos_module_extras')) {
                $table->dropColumn('pos_module_extras');
            }
        });
    }
};
