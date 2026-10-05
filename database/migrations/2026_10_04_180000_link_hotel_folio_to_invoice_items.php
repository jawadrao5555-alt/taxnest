<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('pos_transaction_items', 'hotel_folio_entry_id')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->unsignedBigInteger('hotel_folio_entry_id')->nullable()->index();
            });
        }
        // Historical names/order do not prove identity. Never guess a backfill.
    }

    public function down(): void
    {
        if (Schema::hasColumn('pos_transaction_items', 'hotel_folio_entry_id')) {
            Schema::table('pos_transaction_items', function (Blueprint $table) {
                $table->dropColumn('hotel_folio_entry_id');
            });
        }
    }
};
