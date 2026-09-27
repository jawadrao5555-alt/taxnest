<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hotel_stays', function (Blueprint $table) {
            $table->decimal('standard_rate_amount', 14, 2)->nullable();
            $table->string('discount_type', 16)->default('amount');
            $table->decimal('discount_value', 14, 2)->default(0);
        });
        Schema::table('hotel_folio_entries', function (Blueprint $table) {
            $table->decimal('gross_amount', 14, 2)->nullable();
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->boolean('room_pricing')->default(false);
            $table->date('room_from_date')->nullable();
            $table->date('room_to_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hotel_folio_entries', fn (Blueprint $table) => $table->dropColumn(['gross_amount', 'discount_amount', 'room_pricing', 'room_from_date', 'room_to_date']));
        Schema::table('hotel_stays', fn (Blueprint $table) => $table->dropColumn(['standard_rate_amount', 'discount_type', 'discount_value']));
    }
};
