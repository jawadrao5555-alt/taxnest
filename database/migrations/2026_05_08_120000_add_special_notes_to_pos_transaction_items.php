<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            if (!Schema::hasColumn('pos_transaction_items', 'special_notes')) {
                $table->text('special_notes')->nullable()->after('item_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_transaction_items', function (Blueprint $table) {
            if (Schema::hasColumn('pos_transaction_items', 'special_notes')) {
                $table->dropColumn('special_notes');
            }
        });
    }
};
