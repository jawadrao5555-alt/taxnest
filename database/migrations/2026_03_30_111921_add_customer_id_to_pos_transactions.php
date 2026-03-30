<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('invoice_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('pos_transactions', 'customer_id')) {
                $table->dropColumn('customer_id');
            }
        });
    }
};
