<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local Billing day-close policy (owner feature F1, Jul 2026).
 *
 * Companies decide what happens to their LOCAL bills at day-close:
 *   - pos_dayclose_final_local_action  — reporting-OFF FINAL bills ('save' = archive | 'delete')
 *   - pos_dayclose_provisional_action  — deliberate PROVISIONAL bills ('save' = archive | 'delete')
 *   - pos_customer_spend_persist       — when deleting, keep a customer-spend snapshot ledger
 *
 * pos_customer_spend_snapshots is the immutable per-bill spend ledger written BEFORE a
 * local bill is deleted (only when persist is ON) so customer purchase history survives.
 *
 * Idempotent: hasColumn/hasTable guards — safe to run repeatedly on PROD.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            if (! Schema::hasColumn('companies', 'pos_dayclose_final_local_action')) {
                $table->string('pos_dayclose_final_local_action', 10)->default('save');
            }
            if (! Schema::hasColumn('companies', 'pos_dayclose_provisional_action')) {
                $table->string('pos_dayclose_provisional_action', 10)->default('save');
            }
            if (! Schema::hasColumn('companies', 'pos_customer_spend_persist')) {
                $table->boolean('pos_customer_spend_persist')->default(true);
            }
        });

        if (! Schema::hasTable('pos_customer_spend_snapshots')) {
            Schema::create('pos_customer_spend_snapshots', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('customer_id')->nullable()->index();
                $table->string('customer_phone', 30)->nullable()->index();
                $table->string('customer_name')->nullable();
                $table->string('invoice_number')->nullable();
                $table->string('bill_kind', 20)->default('provisional'); // provisional | final_local
                $table->string('payment_method', 30)->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('tax_amount', 12, 2)->default(0);
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->timestamp('sold_at')->nullable();
                $table->unsignedBigInteger('dayclose_report_id')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['pos_dayclose_final_local_action', 'pos_dayclose_provisional_action', 'pos_customer_spend_persist'] as $col) {
                if (Schema::hasColumn('companies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('pos_customer_spend_snapshots');
    }
};
