<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company receipt / invoice display preferences.
 *
 * JSON structure (all keys optional — NULL/missing = show everything with
 * the product's default footer text, i.e. zero visual change until the
 * owner opts out):
 * {
 *   "pos": {"show_address":true,"show_ntn":true,"show_email":true,"show_mobile":true,"footer_text":"..."},
 *   "di":  {"show_address":true,"show_ntn":true,"show_email":true,"show_mobile":true,"footer_text":"..."}
 * }
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('companies')) {
            return;
        }

        if (!Schema::hasColumn('companies', 'invoice_display_prefs')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->json('invoice_display_prefs')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('companies') && Schema::hasColumn('companies', 'invoice_display_prefs')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('invoice_display_prefs');
            });
        }
    }
};
