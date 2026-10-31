<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('pos_deleted_history_totals')) {
            Schema::create('pos_deleted_history_totals', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->date('business_date'); $t->unsignedBigInteger('created_by')->nullable();
                $t->string('stream', 16); $t->string('stage', 20); $t->string('payment_method', 32);
                $t->unsignedBigInteger('dayclose_report_id')->nullable();
                $t->string('source', 32); $t->json('totals'); $t->json('products'); $t->timestamp('captured_at');
                $t->index(['company_id', 'business_date', 'branch_id'], 'pos_deleted_history_scope');
            });
        }
        if (Schema::hasTable('pos_transaction_items') && !Schema::hasColumn('pos_transaction_items', 'reporting_snapshot')) {
            Schema::table('pos_transaction_items', fn (Blueprint $t) => $t->json('reporting_snapshot')->nullable());
        }
    }

    public function down(): void
    {
        // Aggregate accounting evidence survives application rollback; no bill recovery data is stored here.
    }
};
