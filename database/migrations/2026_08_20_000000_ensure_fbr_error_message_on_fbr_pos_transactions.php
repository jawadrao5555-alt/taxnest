<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 627: FBR POS counterpart of pra_error_message (Task 624) — store the ACTUAL
 * failure reason (timeout / HTTP code / FBR message) on the bill itself so the
 * F11 Failed Bills modal can show it — no more SSH+log grep.
 *
 * Idempotent ensure-column migration (prod-schema-drift-selfheal pattern):
 * safe to re-run; per-column hasColumn guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'fbr_error_message')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->text('fbr_error_message')->nullable()->after('fbr_response_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fbr_pos_transactions', 'fbr_error_message')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->dropColumn('fbr_error_message');
            });
        }
    }
};
