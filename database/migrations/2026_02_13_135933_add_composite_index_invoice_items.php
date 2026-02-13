<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!$this->indexExists('invoice_items', 'invoice_items_hs_code_invoice_id_index')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->index(['hs_code', 'invoice_id'], 'invoice_items_hs_code_invoice_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex('invoice_items_hs_code_invoice_id_index');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::select("SELECT 1 FROM pg_indexes WHERE tablename = ? AND indexname = ?", [$table, $indexName]) ? true : false;
    }
};
