<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pos_transaction_items')) return;
        foreach (['taxable_base', 'exempt_base', 'third_schedule_base', 'allocated_discount'] as $column) {
            if (!Schema::hasColumn('pos_transaction_items', $column)) {
                Schema::table('pos_transaction_items', fn (Blueprint $table) => $table->decimal($column, 18, 2)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Financial snapshots survive an application rollback. Old code ignores nullable columns.
    }
};
