<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->string('invoice_mode', 10)->default('pra')->after('invoice_number');
        });

        DB::table('pos_transactions')->whereNull('invoice_mode')->orWhere('invoice_mode', '')->update(['invoice_mode' => 'pra']);

        Schema::table('companies', function (Blueprint $table) {
            $table->string('confidential_pin')->nullable()->after('receipt_printer_size');
            $table->integer('next_local_invoice_number')->default(1)->after('confidential_pin');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('pos_role', 20)->nullable()->after('role');
        });

        DB::table('users')
            ->where('role', 'company_admin')
            ->whereNotNull('company_id')
            ->update(['pos_role' => 'pos_admin']);
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn('invoice_mode');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['confidential_pin', 'next_local_invoice_number']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pos_role');
        });
    }
};
