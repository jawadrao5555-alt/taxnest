<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transactions', 'cash_received')) {
                $table->decimal('cash_received', 15, 2)->nullable()->after('payment_method');
            }
            if (!Schema::hasColumn('pos_transactions', 'change_due')) {
                $table->decimal('change_due', 15, 2)->nullable()->after('cash_received');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('pos_transactions', 'cash_received')) $table->dropColumn('cash_received');
            if (Schema::hasColumn('pos_transactions', 'change_due')) $table->dropColumn('change_due');
        });
    }
};
