<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-USER POS style pref (owner, 5 Aug 2026): waiter apni marzi se
 * Full/Saaf chun sake — NULL = company ka style (default), warna user
 * ki apni pasand jeetti hai (BOTH directions, pos-user-grid-prefs pattern).
 * Idempotent: hasColumn-guarded for prod schema drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pos_personal_style')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('pos_personal_style', 10)->nullable()->after('dark_mode');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pos_personal_style')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pos_personal_style');
            });
        }
    }
};
