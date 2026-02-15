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
            $table->dropUnique(['fbr_submission_hash']);
            $table->index('fbr_submission_hash', 'invoices_fbr_submission_hash_index');
        });

        DB::table('invoices')
            ->where('status', 'draft')
            ->where('fbr_status', 'failed')
            ->update(['status' => 'failed']);
    }

    public function down(): void
    {
        DB::table('invoices')
            ->where('status', 'failed')
            ->update(['status' => 'draft']);

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_fbr_submission_hash_index');
            $table->unique('fbr_submission_hash');
        });
    }
};
