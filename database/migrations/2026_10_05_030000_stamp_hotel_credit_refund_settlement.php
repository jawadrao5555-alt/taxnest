<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_folio_entries', function (Blueprint $table) {
            $table->date('refund_business_date')->nullable();
            $table->unsignedBigInteger('refund_branch_id')->nullable();
            $table->unsignedBigInteger('refund_terminal_id')->nullable();
            $table->index(['company_id', 'refund_business_date'], 'hotel_refund_day');
        });
    }
    public function down(): void
    {
        Schema::table('hotel_folio_entries', function (Blueprint $table) {
            $table->dropIndex('hotel_refund_day');
            $table->dropColumn(['refund_business_date', 'refund_branch_id', 'refund_terminal_id']);
        });
    }
};
