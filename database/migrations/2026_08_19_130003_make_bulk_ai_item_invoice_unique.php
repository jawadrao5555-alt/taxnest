<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_ai_image_items') && Schema::hasColumn('bulk_ai_image_items', 'invoice_id')) {
            Schema::table('bulk_ai_image_items', function (Blueprint $table) {
                $table->unique('invoice_id', 'bulk_ai_item_invoice_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bulk_ai_image_items')) {
            Schema::table('bulk_ai_image_items', function (Blueprint $table) {
                $table->dropUnique('bulk_ai_item_invoice_unique');
            });
        }
    }
};