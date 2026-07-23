<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Receipt popup auto-close timer (owner, 23 Jul 2026): the sale-screen
 * success popup closes itself after N seconds so cashiers can chain sales
 * hands-free. NULL = platform default (10s), 0 = never (old persistent
 * behavior), else seconds. Admin-set per company on /pos/customize.
 * Idempotent guard — safe to re-run on PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_receipt_autoclose_seconds')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedSmallInteger('pos_receipt_autoclose_seconds')->nullable()->default(null)->after('pos_quick_type_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_receipt_autoclose_seconds')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_receipt_autoclose_seconds');
            });
        }
    }
};
