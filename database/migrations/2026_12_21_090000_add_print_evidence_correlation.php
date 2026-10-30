<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pos_print_evidence')) {
            foreach (['product_type', 'transaction_id', 'order_id', 'branch_id'] as $column) {
                if (!Schema::hasColumn('pos_print_evidence', $column)) {
                    Schema::table('pos_print_evidence', function (Blueprint $t) use ($column) {
                        if ($column === 'product_type') $t->string($column, 16)->nullable();
                        else $t->unsignedBigInteger($column)->nullable();
                    });
                }
            }
            foreach (['print_evidence_period' => ['company_id', 'received_at', 'id'],
                'print_evidence_bill' => ['company_id', 'transaction_id', 'id']] as $name => $columns) {
                if (!Schema::hasIndex('pos_print_evidence', $name)) {
                    Schema::table('pos_print_evidence', fn (Blueprint $t) => $t->index($columns, $name));
                }
            }
        }
        if (!Schema::hasTable('pos_print_evidence_health')) {
            Schema::create('pos_print_evidence_health', function (Blueprint $t) {
                $t->unsignedBigInteger('company_id')->primary();
                $t->timestamp('first_recorded_at')->nullable();
                $t->timestamp('last_recorded_at')->nullable();
                $t->timestamp('last_failure_at')->nullable();
                $t->unsignedBigInteger('failure_count')->default(0);
            });
        }
        // No backfill: old rows did not record immutable product/bill/branch attribution.
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_print_evidence_health');
        if (Schema::hasTable('pos_print_evidence') && Schema::hasColumn('pos_print_evidence', 'product_type')) {
            Schema::table('pos_print_evidence', function (Blueprint $t) {
                $t->dropIndex('print_evidence_period'); $t->dropIndex('print_evidence_bill');
                $t->dropColumn(['product_type', 'transaction_id', 'order_id', 'branch_id']);
            });
        }
    }
};
