<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick Type Mode is now OPT-IN per company (owner, 22 Jul 2026: customers
 * reported the "Quick" button as clutter — it only suits dhaba/food shops).
 * Default OFF: button + F7 shortcut + modal hidden unless enabled from
 * /pos/customize. Idempotent guard — safe to re-run on PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_quick_type_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('pos_quick_type_enabled')->default(false)->after('pos_guided_flow_enabled');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_quick_type_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_quick_type_enabled');
            });
        }
    }
};
