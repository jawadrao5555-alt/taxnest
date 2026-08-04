<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-company product search mode (owner, 4 Aug 2026): shops choose how the
 * sale/waiter screen product search matches names —
 *   'prefix'   = strict name-prefix (24 Jul 2026 rule) + word-start rescue
 *                when the prefix finds nothing (default, current behavior)
 *   'any_word' = match the start of ANY word in the name right away
 *                ("win" → "5 Piece Hot Wings"), prefix hits float first.
 * Idempotent (hasColumn guard) — safe on live where migrate --force runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'pos_product_search_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('pos_product_search_mode', 20)->default('prefix');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('companies', 'pos_product_search_mode')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('pos_product_search_mode');
            });
        }
    }
};
