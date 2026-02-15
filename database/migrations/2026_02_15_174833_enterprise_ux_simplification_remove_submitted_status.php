<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->boolean('is_fbr_processing')->default(false)->after('status');
        });

        DB::table('invoices')->where('status', 'submitted')->update(['status' => 'draft']);
        DB::table('invoices')->where('status', 'failed')->update(['status' => 'draft']);
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('is_fbr_processing');
        });
    }
};
