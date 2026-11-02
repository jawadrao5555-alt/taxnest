<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pos_rider_settlements')) return;
        Schema::table('pos_rider_settlements', function (Blueprint $table) {
            $table->unsignedBigInteger('rider_id')->nullable()->change();
            if (!Schema::hasColumn('pos_rider_settlements', 'receipt_kind')) $table->string('receipt_kind', 20)->default('rider');
            if (!Schema::hasColumn('pos_rider_settlements', 'receipt_key')) $table->uuid('receipt_key')->nullable();
            if (!Schema::hasColumn('pos_rider_settlements', 'branch_id')) $table->unsignedBigInteger('branch_id')->nullable()->index();
            if (!Schema::hasColumn('pos_rider_settlements', 'business_date')) $table->date('business_date')->nullable()->index();
        });
        if (!Schema::hasIndex('pos_rider_settlements', 'pos_cash_receipt_once')) {
            Schema::table('pos_rider_settlements', fn (Blueprint $table) => $table->unique(['company_id', 'receipt_key'], 'pos_cash_receipt_once'));
        }
    }

    public function down(): void
    {
        // Existing cash evidence must remain readable after an application rollback.
    }
};
