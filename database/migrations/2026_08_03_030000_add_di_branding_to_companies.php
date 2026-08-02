<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 140: DI Premium white-label branding storage.
 *
 * companies.di_branding (TEXT, JSON-encoded, nullable):
 *   { enabled, logo_path, accent, footer_line1, footer_line2, hide_platform }
 *
 * Idempotent with hasColumn guards — safe to re-run on environments where
 * migration state has drifted (prod can mark rows "Ran" while columns are
 * missing; guards make this migration self-healing).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'di_branding')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->text('di_branding')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'di_branding')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('di_branding');
            });
        }
    }
};
