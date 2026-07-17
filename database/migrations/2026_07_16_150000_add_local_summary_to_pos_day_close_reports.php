<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive day-close report (owner request Jul 2026):
 * `local_summary` stores the full local-bill wash detail as JSON —
 * per bill kind (provisional / final_local): action taken, count washed,
 * total amount, and how many were BACKLOG bills from earlier un-closed dates.
 * TEXT (not JSON type) for maximum cPanel/MySQL compatibility; Eloquent
 * 'array' cast handles encoding. Idempotent per the PROD schema-drift pattern.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_day_close_reports', 'local_summary')) {
                $table->text('local_summary')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_day_close_reports', function (Blueprint $table) {
            if (Schema::hasColumn('pos_day_close_reports', 'local_summary')) {
                $table->dropColumn('local_summary');
            }
        });
    }
};
