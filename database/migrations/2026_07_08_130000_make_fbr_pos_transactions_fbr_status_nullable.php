<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR-reporting-OFF companies' FINAL sales are stored with fbr_status = NULL
 * ("no FBR involvement") so they never appear in the fail-queue / retry /
 * promote lists which all key off fbr_status values. The column was NOT NULL
 * DEFAULT 'pending', which made NULL impossible — relax it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            $table->string('fbr_status')->nullable()->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Backfill NULLs before restoring NOT NULL to keep the rollback safe.
        \Illuminate\Support\Facades\DB::table('fbr_pos_transactions')
            ->whereNull('fbr_status')->update(['fbr_status' => 'pending']);
        Schema::table('fbr_pos_transactions', function (Blueprint $table) {
            $table->string('fbr_status')->nullable(false)->default('pending')->change();
        });
    }
};
