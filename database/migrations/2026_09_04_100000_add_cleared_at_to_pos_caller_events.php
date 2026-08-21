<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 1380 — "Haaliya calls" list se handle ho chuki call hatane ka rasta.
 *
 * cleared_at = shop-wide "yeh call nimta di" flag. Row DELETE nahi hoti (ring
 * retention purge hi rows ka malik hai) — sirf list se ghayab hoti hai, taake
 * page refresh par aur usi shop ke doosre counter par bhi wapas na aaye.
 * Idempotent + hasColumn-guarded (prod-schema-drift-selfheal).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_caller_events')) {
            return;
        }
        Schema::table('pos_caller_events', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_caller_events', 'cleared_at')) {
                $table->timestamp('cleared_at')->nullable()->after('ring_at');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('pos_caller_events') && Schema::hasColumn('pos_caller_events', 'cleared_at')) {
            Schema::table('pos_caller_events', function (Blueprint $table) {
                $table->dropColumn('cleared_at');
            });
        }
    }
};
