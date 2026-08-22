<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preserve the server quote a shop was asked to pay for a POS feature add-on.
 *
 * Payment proofs are deployed to installations with historical schema drift,
 * so this is deliberately additive and fully guarded.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_proofs')
            && !Schema::hasColumn('payment_proofs', 'addon_quote_snapshot')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->text('addon_quote_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payment_proofs')
            && Schema::hasColumn('payment_proofs', 'addon_quote_snapshot')) {
            Schema::table('payment_proofs', function (Blueprint $table) {
                $table->dropColumn('addon_quote_snapshot');
            });
        }
    }
};