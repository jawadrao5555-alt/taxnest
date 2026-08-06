<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS Retail Core (Aug 2026) — 4-feature foundation:
 *   1. Udhaar/Khata  — fbr_customer_ledgers table + pos_customers.khata_balance cache
 *   2. Returns       — (schema already existed: transaction_type/parent_transaction_id)
 *   3. Units         — (schema already existed: decimal quantity + uom)
 *   4. Stock/Purchase— (reuses inventory_stocks/suppliers/purchase_orders from inventory module)
 *
 * Idempotent: every change is guarded with hasTable/hasColumn so the owner's
 * cPanel PROD (migrate --force) can re-run it safely.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fbr_customer_ledgers')) {
            Schema::create('fbr_customer_ledgers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('customer_id')->index();
                // udhaar = customer took goods on credit (balance UP)
                // wasooli = customer paid back (balance DOWN)
                // return_adjust = return refunded into khata (balance DOWN)
                $table->string('entry_type', 20);
                $table->decimal('amount', 12, 2);
                $table->decimal('balance_after', 12, 2)->default(0);
                $table->unsignedBigInteger('transaction_id')->nullable()->index();
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->index(['company_id', 'customer_id']);
            });
        }

        if (Schema::hasTable('pos_customers') && !Schema::hasColumn('pos_customers', 'khata_balance')) {
            Schema::table('pos_customers', function (Blueprint $table) {
                $table->decimal('khata_balance', 12, 2)->default(0)->after('total_orders');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fbr_customer_ledgers');
        if (Schema::hasTable('pos_customers') && Schema::hasColumn('pos_customers', 'khata_balance')) {
            Schema::table('pos_customers', function (Blueprint $table) {
                $table->dropColumn('khata_balance');
            });
        }
    }
};
