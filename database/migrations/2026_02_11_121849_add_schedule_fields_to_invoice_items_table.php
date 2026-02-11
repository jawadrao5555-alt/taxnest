<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('schedule_type', 50)->default('standard')->after('hs_code');
            $table->string('pct_code', 50)->nullable()->after('schedule_type');
            $table->decimal('tax_rate', 8, 2)->default(18)->after('pct_code');
            $table->string('sro_schedule_no', 100)->nullable()->after('tax_rate');
            $table->string('serial_no', 100)->nullable()->after('sro_schedule_no');
            $table->decimal('mrp', 15, 2)->nullable()->after('serial_no');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropColumn(['schedule_type', 'pct_code', 'tax_rate', 'sro_schedule_no', 'serial_no', 'mrp']);
        });
    }
};
