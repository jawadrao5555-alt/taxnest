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

        \Illuminate\Support\Facades\DB::statement("UPDATE invoices SET wht_locked = 1 WHERE status = 'locked' AND fbr_status = 'production'");
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('wht_locked');
        });
    }
};
