<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consultant payout details — encrypted at rest (encrypted values overflow
 * varchar(255), so the encrypted columns MUST be TEXT).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('consultant_profiles')) {
            return;
        }

        Schema::table('consultant_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('consultant_profiles', 'payout_method')) {
                $table->string('payout_method', 30)->nullable()->after('payout_notes');
            }
            if (!Schema::hasColumn('consultant_profiles', 'payout_account_title')) {
                $table->text('payout_account_title')->nullable()->after('payout_method');
            }
            if (!Schema::hasColumn('consultant_profiles', 'payout_account_number')) {
                $table->text('payout_account_number')->nullable()->after('payout_account_title');
            }
            if (!Schema::hasColumn('consultant_profiles', 'payout_bank_name')) {
                $table->text('payout_bank_name')->nullable()->after('payout_account_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('consultant_profiles', function (Blueprint $table) {
            foreach (['payout_bank_name', 'payout_account_number', 'payout_account_title', 'payout_method'] as $col) {
                if (Schema::hasColumn('consultant_profiles', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
