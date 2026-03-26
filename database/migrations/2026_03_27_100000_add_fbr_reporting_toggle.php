<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('companies', 'fbr_reporting_enabled')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->boolean('fbr_reporting_enabled')->default(false)->after('fbr_pos_enabled');
            });
        }

        if (!Schema::hasColumn('fbr_pos_transactions', 'invoice_mode')) {
            Schema::table('fbr_pos_transactions', function (Blueprint $table) {
                $table->string('invoice_mode', 10)->default('fbr')->after('invoice_number');
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('fbr_reporting_enabled');
        });
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            $table->dropColumn('invoice_mode');
        });
    }
};
