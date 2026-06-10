<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'pos_receipt_show_tax')) {
                // Show / hide the tax breakdown line on POS receipts (80mm/58mm/A4).
                // Default TRUE preserves existing behaviour for every current company.
                $table->boolean('pos_receipt_show_tax')->default(true)->after('receipt_footer_note');
            }
            if (!Schema::hasColumn('companies', 'kot_reprint_enabled')) {
                // Allow cashiers to reprint a KOT after a sale (held-orders / last-sale widget).
                $table->boolean('kot_reprint_enabled')->default(true)->after('auto_print_kot');
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['pos_receipt_show_tax', 'kot_reprint_enabled'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
