<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order Matching — FBR POS transactions.
 * Token/code copied from the held sale at billing time and stored permanently
 * so receipt reprints always carry the correct match identifier.
 * Idempotent: each column only added when not already present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fbr_pos_transactions', 'token_no')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->unsignedInteger('token_no')->nullable()->after('offline_uuid')
                    ->comment('Order-matching daily token (style=token); copied from held sale at billing');
            });
        }

        if (!Schema::hasColumn('fbr_pos_transactions', 'order_code')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->string('order_code', 10)->nullable()->after('token_no')
                    ->comment('Short random code for style=code; copied from held sale at billing');
            });
        }
    }

    public function down(): void
    {
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('fbr_pos_transactions', 'token_no')) {
                $table->dropColumn('token_no');
            }
            if (Schema::hasColumn('fbr_pos_transactions', 'order_code')) {
                $table->dropColumn('order_code');
            }
        });
    }
};
