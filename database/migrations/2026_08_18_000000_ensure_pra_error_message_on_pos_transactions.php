<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 624: store the ACTUAL failure reason (PRA timeout / HTTP code / PRA message)
 * on the bill itself so the F11 Failed Bills modal can show it — no more SSH+log grep.
 *
 * Idempotent ensure-column migration (prod-schema-drift-selfheal pattern):
 * safe to re-run; per-column hasColumn guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transactions', 'pra_error_message')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->text('pra_error_message')->nullable()->after('pra_response_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transactions', 'pra_error_message')) {
            Schema::table('pos_transactions', function (Blueprint $table) {
                $table->dropColumn('pra_error_message');
            });
        }
    }
};
