<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->text('pra_qr_code')->nullable()->after('submission_hash');
        });

        if (!Schema::hasColumn('companies', 'receipt_printer_size')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->string('receipt_printer_size', 10)->default('80mm')->after('pra_production_token');
            });
        }

        if (Schema::hasColumn('pos_terminals', 'terminal_id') && !Schema::hasColumn('pos_terminals', 'terminal_code')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->renameColumn('terminal_id', 'terminal_code');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pos_transactions', function (Blueprint $table) {
            $table->dropColumn('pra_qr_code');
        });

        if (Schema::hasColumn('companies', 'receipt_printer_size')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('receipt_printer_size');
            });
        }

        if (Schema::hasColumn('pos_terminals', 'terminal_code') && !Schema::hasColumn('pos_terminals', 'terminal_id')) {
            Schema::table('pos_terminals', function (Blueprint $table) {
                $table->renameColumn('terminal_code', 'terminal_id');
            });
        }
    }
};
