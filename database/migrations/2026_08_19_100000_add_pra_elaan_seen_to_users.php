<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1202: PRA provisional-billing elaan popup (raay collection).
 * Per-user "seen" stamp — set when the admin/manager ANSWERS the elaan OR
 * dismisses it ("Baad mein"), so the popup never re-appears (no dismiss loop).
 * Idempotent per-column guard (prod schema-drift self-heal convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pra_elaan_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('pra_elaan_seen_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pra_elaan_seen_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pra_elaan_seen_at');
            });
        }
    }
};
