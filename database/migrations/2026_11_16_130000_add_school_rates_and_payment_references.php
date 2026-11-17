<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('school_fee_heads', 'default_amount_paisa')) {
            Schema::table('school_fee_heads', fn (Blueprint $t) => $t->unsignedBigInteger('default_amount_paisa')->nullable());
        }
        if (!Schema::hasColumn('school_fee_receipts', 'payment_reference')) {
            Schema::table('school_fee_receipts', fn (Blueprint $t) => $t->string('payment_reference', 100)->nullable());
        }
    }
    public function down(): void
    {
        // Forward-only: recorded payment references are immutable receipt history.
        // No historical amount, fee label, receipt, balance or close is rewritten.
    }
};
