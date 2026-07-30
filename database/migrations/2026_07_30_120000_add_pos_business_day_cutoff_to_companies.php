<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom business-day cutoff time (owner request, 30 Jul 2026).
 *
 * Per-company "Din band hone ka waqt": sales before this time belong to the
 * PREVIOUS trading day (business_date). Default '06:00' keeps the existing
 * hardcoded 6 AM behavior for every company. Stored as 'HH:MM' string —
 * compared lexicographically against $local->format('H:i').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('companies', 'pos_business_day_cutoff')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pos_business_day_cutoff', 5)->default('06:00');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_business_day_cutoff')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_business_day_cutoff');
            });
        }
    }
};
