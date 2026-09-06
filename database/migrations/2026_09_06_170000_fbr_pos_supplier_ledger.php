<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FBR POS — distributor ledger, scheme/bonus purchases and purchase returns
 * (Task 1580, Sep 2026).
 *
 * A medical store buys on distributor credit and every pharma invoice carries
 * a scheme ("10+1" bonus strips), a trade discount and often a flat invoice
 * discount. Until now a purchase was qty × rate and nothing else: no payable,
 * no payment, no bonus, no return document.
 *
 *  - purchase_orders       : supplier invoice number + the discount breakdown.
 *                            total_amount KEEPS its meaning ("what the shop
 *                            owes for this bill") and is now the NET figure.
 *  - purchase_order_items  : bonus qty, line discount, net cost per unit
 *                            spread over paid + bonus units (avg cost, Munafa
 *                            and batch cost all read this).
 *  - supplier_payments     : money handed to a distributor (POS-side twin of
 *                            health_supplier_payments; company/branch scoped;
 *                            void-only editing).
 *  - purchase_returns (+items): goods sent back = a credit note on the ledger.
 *  - pharmacy_claims.ledger_credited_at : the claim → ledger credit link.
 *
 * Idempotent (hasTable / hasColumn guards) — cPanel PROD has a history of
 * migrations marked "Ran" without their columns (memory: prod-schema-drift).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_orders')) {
            Schema::table('purchase_orders', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_orders', 'supplier_invoice_no')) {
                    $table->string('supplier_invoice_no', 60)->nullable()->after('po_number');
                }
                if (!Schema::hasColumn('purchase_orders', 'gross_amount')) {
                    $table->decimal('gross_amount', 15, 2)->nullable()->after('total_amount');
                }
                if (!Schema::hasColumn('purchase_orders', 'line_discount_amount')) {
                    $table->decimal('line_discount_amount', 15, 2)->default(0)->after('gross_amount');
                }
                if (!Schema::hasColumn('purchase_orders', 'invoice_discount_amount')) {
                    $table->decimal('invoice_discount_amount', 15, 2)->default(0)->after('line_discount_amount');
                }
            });

            // Legacy FBR purchases never stamped their branch on the PO row (the
            // movements carried it). The ledger scopes by purchase_orders.branch_id,
            // so read it back once from the PURCHASE movements — only rows that
            // are still blank and whose goods went into exactly one branch.
            if (Schema::hasTable('inventory_movements')) {
                DB::table('purchase_orders')
                    ->whereNull('branch_id')
                    ->orderBy('id')
                    ->select('id')
                    ->chunkById(500, function ($rows) {
                        foreach ($rows as $row) {
                            $branches = DB::table('inventory_movements')
                                ->where('reference_type', 'purchase_order')
                                ->where('reference_id', $row->id)
                                ->where('type', 'purchase')
                                ->whereNotNull('branch_id')
                                ->distinct()
                                ->pluck('branch_id');
                            if ($branches->count() === 1) {
                                DB::table('purchase_orders')->where('id', $row->id)
                                    ->whereNull('branch_id')
                                    ->update(['branch_id' => $branches->first()]);
                            }
                        }
                    });
            }
        }

        if (Schema::hasTable('purchase_order_items')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                if (!Schema::hasColumn('purchase_order_items', 'bonus_qty')) {
                    $table->decimal('bonus_qty', 15, 3)->default(0)->after('received_quantity');
                }
                if (!Schema::hasColumn('purchase_order_items', 'discount_pct')) {
                    $table->decimal('discount_pct', 6, 2)->default(0)->after('bonus_qty');
                }
                if (!Schema::hasColumn('purchase_order_items', 'discount_amount')) {
                    $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_pct');
                }
                if (!Schema::hasColumn('purchase_order_items', 'net_total')) {
                    $table->decimal('net_total', 15, 2)->nullable()->after('discount_amount');
                }
                // NULL = legacy line (cost = unit_price). A real 0 is a fully
                // discounted / bonus-only line, which is a legitimate value.
                if (!Schema::hasColumn('purchase_order_items', 'net_unit_cost')) {
                    $table->decimal('net_unit_cost', 15, 4)->nullable()->after('net_total');
                }
            });
        }

        if (!Schema::hasTable('supplier_payments')) {
            Schema::create('supplier_payments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supplier_id');
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                $table->decimal('amount', 15, 2)->default(0);
                // cash | bank | online | cheque
                $table->string('method', 24)->default('cash');
                $table->date('paid_on')->nullable();
                $table->string('reference', 64)->nullable();
                $table->string('notes', 500)->nullable();
                // active | void — a payment is never edited, only voided and re-entered.
                $table->string('status', 12)->default('active');
                $table->timestamp('voided_at')->nullable();
                $table->unsignedBigInteger('voided_by')->nullable();
                $table->string('void_reason', 200)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'supplier_id'], 'sup_pay_supplier_idx');
                $table->index(['company_id', 'purchase_order_id'], 'sup_pay_po_idx');
                $table->index(['company_id', 'branch_id'], 'sup_pay_branch_idx');
            });
        }

        if (!Schema::hasTable('purchase_returns')) {
            Schema::create('purchase_returns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->unsignedBigInteger('purchase_order_id')->nullable();
                $table->string('return_number', 30);
                // surplus | wrong | damaged | expired | other
                $table->string('reason', 20)->default('surplus');
                $table->string('supplier_reference', 60)->nullable();
                $table->decimal('credit_amount', 15, 2)->default(0);
                // posted (only status for now — a return is a final document)
                $table->string('status', 12)->default('posted');
                $table->date('returned_on')->nullable();
                $table->string('notes', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['company_id', 'supplier_id'], 'pur_ret_supplier_idx');
                $table->index(['company_id', 'purchase_order_id'], 'pur_ret_po_idx');
                $table->unique(['company_id', 'return_number'], 'pur_ret_number_uq');
            });
        }

        if (!Schema::hasTable('purchase_return_items')) {
            Schema::create('purchase_return_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('purchase_return_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('purchase_order_item_id')->nullable();
                $table->unsignedBigInteger('batch_id')->nullable();
                $table->string('batch_number', 60)->nullable();
                $table->date('expiry_date')->nullable();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->decimal('unit_cost', 15, 4)->default(0);
                $table->decimal('total', 15, 2)->default(0);
                $table->timestamps();

                $table->index('purchase_return_id', 'pur_ret_item_ret_idx');
            });
        }

        if (Schema::hasTable('pharmacy_claims') && !Schema::hasColumn('pharmacy_claims', 'ledger_credited_at')) {
            Schema::table('pharmacy_claims', function (Blueprint $table) {
                $table->timestamp('ledger_credited_at')->nullable()->after('settlement_reference');
            });
        }
    }

    public function down(): void
    {
        // Additive-only: the new tables/columns hold money history that must
        // never vanish on a rollback. Nothing to undo.
    }
};
