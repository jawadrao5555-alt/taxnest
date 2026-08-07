<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Matching — FBR POS held sales.
 * Idempotent: each column only added when not already present (PROD schema-drift rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_held_sales', 'token_no')) {
            Schema::table('fbr_pos_held_sales', function (Blueprint $table) {
                $table->unsignedInteger('token_no')->nullable()->after('notes')
                    ->comment('Daily order-matching token (style=token); set at first hold, preserved on re-hold');
            });
        }

        if (!Schema::hasColumn('fbr_pos_held_sales', 'order_code')) {
            Schema::table('fbr_pos_held_sales', function (Blueprint $table) {
                $table->string('order_code', 10)->nullable()->after('token_no')
                    ->comment('Short 5-char random code for style=code; set at first hold');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fbr_pos_held_sales', function (Blueprint $table) {
            if (Schema::hasColumn('fbr_pos_held_sales', 'token_no')) {
                $table->dropColumn('token_no');
            }
            if (Schema::hasColumn('fbr_pos_held_sales', 'order_code')) {
                $table->dropColumn('order_code');
            }
        });
    }
};
