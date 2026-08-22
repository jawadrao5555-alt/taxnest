<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes FBR Khata partial payments inspectable and retry-safe.
 *
 * Existing credit history remains in fbr_customer_ledgers; new settlement rows
 * record the FIFO lots consumed by each wasooli/return adjustment. request_uuid
 * is unique within a company so a retried wasooli cannot reduce a balance twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_customer_ledgers')) {
            return;
        }

        if (!Schema::hasColumn('fbr_customer_ledgers', 'request_uuid')) {
            Schema::table('fbr_customer_ledgers', function (Blueprint $table) {
                $table->string('request_uuid', 64)->nullable()->after('transaction_id');
            });
        }

        // A prior interrupted/manual rollout can leave the nullable column in
        // place without its uniqueness guard. Treat column and index readiness
        // independently so re-running this idempotent migration fixes it.
        $hasRequestUuidIndex = collect(Schema::getIndexes('fbr_customer_ledgers'))
            ->contains(fn (array $index) => ($index['name'] ?? '') === 'fbr_ledger_company_request_uuid_unique');
        if (!$hasRequestUuidIndex) {
            Schema::table('fbr_customer_ledgers', function (Blueprint $table) {
                $table->unique(['company_id', 'request_uuid'], 'fbr_ledger_company_request_uuid_unique');
            });
        }

        if (!Schema::hasTable('fbr_khata_settlements')) {
            Schema::create('fbr_khata_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                $table->unsignedBigInteger('settlement_ledger_id')->index();
                $table->unsignedBigInteger('credit_ledger_id')->index();
                $table->decimal('amount', 12, 2);
                $table->timestamps();

                $table->unique(
                    ['settlement_ledger_id', 'credit_ledger_id'],
                    'fbr_khata_settlement_credit_unique'
                );
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_khata_settlements');

        if (Schema::hasTable('fbr_customer_ledgers')
            && Schema::hasColumn('fbr_customer_ledgers', 'request_uuid')) {
            Schema::table('fbr_customer_ledgers', function (Blueprint $table) {
                $table->dropUnique('fbr_ledger_company_request_uuid_unique');
                $table->dropColumn('request_uuid');
            });
        }
    }
};