<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cash Received / Wapsi box is now a per-company setting (owner, Aug 2026:
// "koi company yeh feature chahti hai koi nahi" — so it became a switch).
// Default OFF for everyone (sale screen stays exactly as today); a company
// that wants it flips it ON at POS Customize. Idempotent + hasColumn-guarded
// so it self-heals on prod schema drift.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_cash_received_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_cash_received_enabled')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_cash_received_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_cash_received_enabled');
            });
        }
    }
};
