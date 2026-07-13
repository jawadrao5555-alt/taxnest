<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-cashier PRA Reporting toggle (owner rule Jul 2026): every POS team account
// controls its OWN reporting switch — one cashier flipping it must never affect
// another cashier or the admin. NULL = inherit the company-level flag (legacy
// behavior), so existing users see no change until they personally toggle.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'pra_reporting_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('pra_reporting_enabled')->nullable()->default(null)->after('pos_role');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'pra_reporting_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('pra_reporting_enabled');
            });
        }
    }
};
