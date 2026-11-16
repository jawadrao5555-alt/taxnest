<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('school_fee_accounts')) {
            Schema::create('school_fee_accounts', function (Blueprint $t) {
                $t->id(); $t->unsignedBigInteger('company_id'); $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('student_reference', 100); $t->string('student_name', 100)->nullable(); $t->string('fee_period', 7);
                $t->json('fee_lines'); $t->unsignedBigInteger('payable_paisa'); $t->unsignedBigInteger('paid_paisa')->default(0); $t->timestamps();
                $t->index(['company_id', 'branch_id', 'id']);
            });
        }
        // A down/up deployment cycle preserves both the schema and its data.
        foreach (['account_id', 'requested_account_id', 'student_name', 'payment_method', 'fee_lines', 'payable_paisa', 'paid_paisa', 'balance_paisa'] as $column) {
            if (!Schema::hasColumn('school_fee_receipts', $column)) {
                Schema::table('school_fee_receipts', function (Blueprint $t) use ($column) {
                    if ($column === 'student_name') $t->string($column, 100)->nullable();
                    elseif ($column === 'payment_method') $t->string($column, 10)->default('cash');
                    elseif ($column === 'fee_lines') $t->json($column)->nullable();
                    else $t->unsignedBigInteger($column)->nullable();
                });
            }
        }
        if (!Schema::hasIndex('school_fee_receipts', 'school_fee_receipts_account_id_index')) {
            Schema::table('school_fee_receipts', fn (Blueprint $t) => $t->index('account_id'));
        }
        foreach (['variance_reason' => 500, 'handover_to' => 100, 'handover_note' => 500] as $column => $length) {
            if (!Schema::hasColumn('school_fee_day_closes', $column)) {
                Schema::table('school_fee_day_closes', fn (Blueprint $t) => $t->string($column, $length)->nullable());
            }
        }
    }

    public function down(): void
    {
        // Forward-only: receipts, balances and permanent close/handover evidence
        // are financial history. Rolling code back must not erase that history.
    }
};
