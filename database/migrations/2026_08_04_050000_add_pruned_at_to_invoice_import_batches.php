<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('invoice_import_batches')) {
            return;
        }

        Schema::table('invoice_import_batches', function (Blueprint $table) {
            if (!Schema::hasColumn('invoice_import_batches', 'pruned_at')) {
                $table->timestamp('pruned_at')->nullable()->after('finished_at');
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('invoice_import_batches') && Schema::hasColumn('invoice_import_batches', 'pruned_at')) {
            Schema::table('invoice_import_batches', function (Blueprint $table) {
                $table->dropColumn('pruned_at');
            });
        }
    }
};
