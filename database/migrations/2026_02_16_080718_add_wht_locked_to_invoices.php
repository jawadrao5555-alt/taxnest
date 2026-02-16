<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('wht_locked')->default(false)->after('wht_amount');
        });

        DB::statement("UPDATE invoices SET wht_locked = true WHERE status = 'locked' AND fbr_status = 'production' AND fbr_invoice_number IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('wht_locked');
        });
    }
};
