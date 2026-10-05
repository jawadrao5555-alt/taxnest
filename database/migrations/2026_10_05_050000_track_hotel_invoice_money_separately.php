<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotel_stays', fn (Blueprint $t) => $t->boolean('hotel_money_from_folio')->default(false));
        Schema::table('pos_transactions', fn (Blueprint $t) => $t->boolean('hotel_money_from_folio')->default(false));
        Schema::table('hotel_folio_entries', function (Blueprint $t) {
            $t->date('settlement_business_date')->nullable();
            $t->unsignedBigInteger('settlement_branch_id')->nullable();
            $t->unsignedBigInteger('settlement_terminal_id')->nullable();
            $t->string('settlement_invoice_mode', 12)->nullable();
            $t->index(['company_id', 'settlement_business_date'], 'hotel_money_settlement_day');
        });
    }
    public function down(): void
    {
        Schema::table('hotel_folio_entries', function (Blueprint $t) {
            $t->dropIndex('hotel_money_settlement_day');
            $t->dropColumn(['settlement_business_date', 'settlement_branch_id', 'settlement_terminal_id', 'settlement_invoice_mode']);
        });
        Schema::table('pos_transactions', fn (Blueprint $t) => $t->dropColumn('hotel_money_from_folio'));
        Schema::table('hotel_stays', fn (Blueprint $t) => $t->dropColumn('hotel_money_from_folio'));
    }
};
