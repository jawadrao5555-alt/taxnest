<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR Biometric Hazri — late-arrival marking (Task #1274).
 *
 * Per-company "late after" wall-clock time (HH:MM, 24h). When set, staff
 * whose FIRST biometric check-in of a business day is after this time are
 * marked late on the hazri report + payroll summary. NULL = feature off.
 *
 * Idempotent per-column guard (prod schema drift convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_bio_late_after')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pos_bio_late_after', 5)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_bio_late_after')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_bio_late_after');
            });
        }
    }
};
