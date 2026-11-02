<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasColumn('pos_transaction_items', 'cost_price')) {
            Schema::table('pos_transaction_items', fn (Blueprint $t) => $t->decimal('cost_price', 18, 6)->nullable()->change());
        }
    }
    public function down(): void
    {
        // Widening preserves existing amounts; rollback must not truncate sold recipe costs.
    }
};
